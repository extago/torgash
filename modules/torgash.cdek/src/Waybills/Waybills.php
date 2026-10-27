<?php

namespace Torgash\Cdek\Waybills;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Torgash\Cdek\Api\ApiException;
use Torgash\Cdek\Api\Client;
use Torgash\Cdek\Models\PlaceMap;
use Torgash\Cdek\Models\Waybill;
use Torgash\Cdek\Roubles;
use Torgash\Cdek\Tariff;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\Measurements;
use Torgash\Checkout\Models\Order;

/**
 * Registering a shop order with CDEK, and everything after: its number, its
 * status, its printed forms, withdrawing it.
 *
 * Everything the waybill needs was frozen onto the order at checkout by
 * CdekProvider::snapshot() — the tariff of the point actually chosen, the
 * point's code or the courier's address, the city — so a waybill made a week
 * later sends what the customer agreed to, not what the method says today.
 *
 * Measured on the test server (2026-10-21): `POST orders` answers at once with
 * a `uuid` and "ACCEPTED", and the order gets its `cdek_number` only later —
 * or turns out INVALID with the reasons in its `requests`. A printed form
 * asked for before the number exists is refused ("All orders are incorrect").
 * So a waybill is a row with a state, refreshed from CDEK, not a single call.
 */
class Waybills
{
    /** How many times a printed form is asked for before giving up, a second apart. */
    public const PRINT_TRIES = 15;

    protected int $printPause = 1;

    public function __construct(protected Client $client) {}

    public function withoutPrintPause(): static
    {
        $clone = clone $this;
        $clone->printPause = 0;

        return $clone;
    }

    /** Was this order sent with CDEK at all? */
    public static function applies(Order $order): bool
    {
        return (($order->shipping_details ?? [])['carrier'] ?? null) === 'cdek';
    }

    /**
     * What the form starts with: the parcel's weight and box from the goods
     * themselves, and whether to collect money on delivery.
     *
     * Collect by default when something is still owed AND the order is not
     * waiting for an online payment — an unpaid order of a card-payment method
     * is "not paid YET", and collecting it at the door would charge twice.
     *
     * @return array{weight_g: int, length_cm: int, width_cm: int, height_cm: int, cod: bool, due: ?float, comment: string}
     */
    public function draft(Order $order): array
    {
        [$grams, $volume] = $this->measure($order);
        [$length, $width, $height] = $this->box($volume);
        $due = $this->due($order);

        return [
            'weight_g' => $grams ?: (int) setting('torgash.cdek', 'default_weight_g', 500),
            'length_cm' => $length,
            'width_cm' => $width,
            'height_cm' => $height,
            'cod' => ($due ?? 0) > 0 && $order->status !== 'awaiting_payment',
            'due' => $due,
            'comment' => '',
        ];
    }

    /**
     * Registers the order with CDEK. Refusals — a missing setting, CDEK saying
     * no at once — come back as a validation error on the form.
     *
     * @param  array{weight_g?: mixed, length_cm?: mixed, width_cm?: mixed, height_cm?: mixed, cod?: mixed, comment?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function create(Order $order, array $input, ?int $userId = null): Waybill
    {
        if (Waybill::where('order_id', $order->id)->whereIn('state', [Waybill::REGISTERING, Waybill::ACTIVE])->exists()) {
            $this->refuse(__('This order already has a CDEK waybill. Cancel it first to make another.'));
        }

        $payload = $this->payload($order, $input);

        try {
            $answer = $this->client->post('orders', $payload);
        } catch (ApiException $e) {
            $this->refuse(__('CDEK refused the waybill: :error', ['error' => $e->getMessage()]));
        }

        $uuid = (string) ($answer['entity']['uuid'] ?? '');

        if ($uuid === '') {
            $this->refuse(__('CDEK did not register the waybill. Try again.'));
        }

        $waybill = Waybill::create([
            'order_id' => $order->id,
            'uuid' => $uuid,
            'number' => $payload['number'],
            'tariff_code' => $payload['tariff_code'],
            'state' => Waybill::REGISTERING,
            'payload' => $payload,
            'cod_kopecks' => $this->codKopecks($payload),
            'created_by' => $userId,
        ]);

        // Usually still "accepted" a moment later, but asking once costs
        // nothing and sometimes saves the admin a click.
        return $this->refresh($waybill);
    }

    /**
     * What CDEK says about it now: its number, its status, or why it was
     * refused. An answer CDEK could not give leaves the row as it was.
     */
    public function refresh(Waybill $waybill): Waybill
    {
        if (! $waybill->uuid || $waybill->state === Waybill::CANCELLED) {
            return $waybill;
        }

        try {
            $answer = $this->client->get('orders/'.$waybill->uuid);
        } catch (ApiException $e) {
            // Gone at CDEK — deleted in its cabinet, say — is an answer too.
            if (in_array('v2_entity_not_found', $e->codes, true)) {
                $waybill->update(['state' => Waybill::CANCELLED, 'checked_at' => now()]);
            } else {
                report($e);
            }

            return $waybill;
        }

        $entity = (array) ($answer['entity'] ?? []);
        $statuses = array_values(array_filter((array) ($entity['statuses'] ?? []), 'is_array'));
        usort($statuses, fn ($a, $b) => strcmp((string) ($b['date_time'] ?? ''), (string) ($a['date_time'] ?? '')));
        $latest = $statuses[0] ?? null;
        $requests = array_values(array_filter((array) ($answer['requests'] ?? []), 'is_array'));

        $errors = [];
        $invalid = false;
        $deleted = false;

        foreach ($requests as $request) {
            if (($request['state'] ?? '') === 'INVALID') {
                $invalid = $invalid || ($request['type'] ?? '') === 'CREATE';
                $errors = [...$errors, ...array_map(
                    fn ($error) => (string) ($error['message'] ?? $error['code'] ?? ''),
                    array_filter((array) ($request['errors'] ?? []), 'is_array'),
                )];
            }

            $deleted = $deleted || (($request['type'] ?? '') === 'DELETE' && ($request['state'] ?? '') === 'SUCCESSFUL');
        }

        $number = (string) ($entity['cdek_number'] ?? '');
        $state = match (true) {
            $deleted || ($latest['code'] ?? '') === 'REMOVED' => Waybill::CANCELLED,
            $number !== '' => Waybill::ACTIVE,
            $invalid || ($latest['code'] ?? '') === 'INVALID' => Waybill::INVALID,
            default => Waybill::REGISTERING,
        };

        $before = $waybill->status_code;

        $waybill->update([
            'cdek_number' => $number !== '' ? $number : $waybill->cdek_number,
            'state' => $state,
            'status_code' => $latest['code'] ?? $waybill->status_code,
            'status_name' => $latest['name'] ?? $waybill->status_name,
            'status_at' => isset($latest['date_time']) ? \Illuminate\Support\Carbon::parse($latest['date_time']) : $waybill->status_at,
            'statuses' => $statuses ?: $waybill->statuses,
            'errors' => array_values(array_filter($errors)) ?: null,
            'checked_at' => now(),
        ]);

        // For whoever wants to act on it — an automation, a status mapping.
        if ($waybill->status_code !== $before && $waybill->status_code !== null) {
            hook_action('cdek.waybill.status_changed', ['waybill' => $waybill, 'from' => $before]);
        }

        return $waybill;
    }

    /**
     * Withdraws it. Only while CDEK does not have the parcel yet — after that
     * CDEK refuses, and that refusal is what the admin is told.
     *
     * @throws ValidationException
     */
    public function cancel(Waybill $waybill): Waybill
    {
        if (! $waybill->isCancellable()) {
            $this->refuse(__('CDEK already has this parcel — it can no longer be cancelled here.'));
        }

        try {
            $this->client->delete('orders/'.$waybill->uuid);
        } catch (ApiException $e) {
            $this->refuse(__('CDEK refused to cancel it: :error', ['error' => $e->getMessage()]));
        }

        $waybill->update(['state' => Waybill::CANCELLED, 'checked_at' => now()]);

        return $waybill;
    }

    /**
     * A printed form as PDF bytes: 'waybill' (the receipt, two copies — one
     * stays with the shop) or 'label' (the barcode sticker for the box, A6).
     *
     * CDEK makes the file asynchronously too: ask, then look until it is
     * READY. Fifteen seconds is far longer than it takes when CDEK is well.
     *
     * @throws ApiException
     */
    public function pdf(Waybill $waybill, string $kind): string
    {
        [$path, $body] = $kind === 'label'
            ? ['print/barcodes', ['orders' => [['order_uuid' => $waybill->uuid]], 'copy_count' => 1, 'format' => 'A6']]
            : ['print/orders', ['orders' => [['order_uuid' => $waybill->uuid]], 'copy_count' => 2]];

        $uuid = (string) ($this->client->post($path, $body)['entity']['uuid'] ?? '');

        for ($try = 1; $uuid !== '' && $try <= self::PRINT_TRIES; $try++) {
            $this->printPause && sleep($this->printPause);

            $answer = $this->client->get($path.'/'.$uuid);
            $entity = (array) ($answer['entity'] ?? []);
            $codes = array_column(array_filter((array) ($entity['statuses'] ?? []), 'is_array'), 'code');

            if (! empty($entity['url'])) {
                return $this->client->download((string) $entity['url']);
            }

            if (in_array('INVALID', $codes, true) || in_array('REMOVED', $codes, true)) {
                $errors = [];

                foreach ((array) ($answer['requests'] ?? []) as $request) {
                    foreach ((array) ($request['errors'] ?? []) as $error) {
                        $errors[] = (string) ($error['message'] ?? '');
                    }
                }

                throw new ApiException(implode('; ', array_filter($errors)) ?: 'CDEK could not make the form.');
            }
        }

        throw new ApiException('CDEK has not made the form yet — try again in a minute.', transient: true);
    }

    /**
     * The order as CDEK's `POST orders` wants it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function payload(Order $order, array $input): array
    {
        $details = (array) ($order->shipping_details ?? []);
        $tariff = (int) ($details['tariff_code'] ?? 0);

        if (! self::applies($order) || $tariff <= 0) {
            $this->refuse(__('This order was not sent with CDEK.'));
        }

        $payload = [
            // An online shop's order — the contract type every module of the
            // carrier's own uses for retail.
            'type' => 1,
            'number' => $this->number($order),
            'tariff_code' => $tariff,
            'comment' => mb_substr(trim((string) ($input['comment'] ?? '')), 0, 255) ?: null,
            'recipient' => array_filter([
                'company' => $order->company_name ?: null,
                'name' => trim((string) $order->customer_name) ?: $this->refuse(__('The order has no recipient name.')),
                'email' => $order->email ?: null,
                'phones' => [['number' => $this->phone((string) $order->phone) ?: $this->refuse(__('The order has no recipient phone — CDEK needs one.'))]],
            ]),
        ];

        $payload += $this->origin($tariff);
        $payload += $this->destination($order, $details);

        if ($sender = $this->sender()) {
            $payload['sender'] = $sender;
        }

        $cod = filter_var($input['cod'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $payload['packages'] = [$this->package($order, $input, $cod)];

        if ($cod) {
            $payload['delivery_recipient_cost'] = ['value' => $this->shippingDue($order, $payload['packages'][0])];
        }

        return array_filter($payload, fn ($value) => $value !== null);
    }

    /**
     * Where CDEK takes the parcel from: the office the shop drops it at, or
     * the shop's own address for its courier — the tariff says which.
     *
     * @return array<string, mixed>
     */
    protected function origin(int $tariff): array
    {
        if (! Tariff::startsAtDoor($tariff)) {
            $point = trim((string) setting('torgash.cdek', 'shipment_point', ''));

            return ['shipment_point' => $point !== '' ? $point : $this->refuse(__('Set the CDEK office you drop parcels at in the module settings.'))];
        }

        $city = (int) setting('torgash.cdek', 'sender_city_code', 0);
        $address = trim((string) setting('torgash.cdek', 'sender_address', ''));

        if ($city <= 0 || $address === '') {
            $this->refuse(__('Set the sender city and the pickup address in the module settings — this tariff has CDEK collect the parcel.'));
        }

        return ['from_location' => ['code' => $city, 'address' => $address]];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    protected function destination(Order $order, array $details): array
    {
        if (($details['delivery'] ?? 'point') === 'point') {
            $point = trim((string) ($details['point_code'] ?? ''));

            return ['delivery_point' => $point !== '' ? $point : $this->refuse(__('The order names no CDEK pickup point.'))];
        }

        $city = (int) ($details['city_code'] ?? 0)
            ?: (int) ($order->place_id ? PlaceMap::whereKey($order->place_id)->value('city_code') : 0);
        $address = trim((string) ($details['address'] ?? $order->address_line ?? ''));

        if ($city <= 0 || $address === '') {
            $this->refuse(__('The order has no CDEK city or no street address for the courier.'));
        }

        return ['to_location' => array_filter([
            'code' => $city,
            'address' => $address,
            'postal_code' => $order->postal_code ?: null,
        ])];
    }

    /** @return array<string, mixed>|null */
    protected function sender(): ?array
    {
        $sender = array_filter([
            'company' => trim((string) setting('torgash.cdek', 'sender_company', '')) ?: null,
            'name' => trim((string) setting('torgash.cdek', 'sender_name', '')) ?: null,
        ]);
        $phone = $this->phone((string) setting('torgash.cdek', 'sender_phone', ''));

        if ($phone !== '') {
            $sender['phones'] = [['number' => $phone]];
        }

        return $sender ?: null;
    }

    /**
     * One box with every line of the order in it — what a shop sends nine
     * times in ten. Item values are what the goods cost (CDEK insures them);
     * what is collected per item is that share of what is still owed, so a
     * discount, a gift card or a part paid already is not charged again.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function package(Order $order, array $input, bool $cod): array
    {
        $draft = $this->draft($order);
        $grams = max(1, (int) ($input['weight_g'] ?? 0) ?: $draft['weight_g']);
        $lines = array_values(array_filter((array) $order->items, fn ($line) => is_array($line) && (int) ($line['qty'] ?? 0) > 0));
        $units = max(1, array_sum(array_map(fn ($line) => (int) $line['qty'], $lines)));
        $weights = $this->unitWeights($lines);

        $goods = 0.0;
        $items = [];

        foreach ($lines as $line) {
            $qty = (int) $line['qty'];
            $unit = Roubles::fromMinor((int) round(((int) ($line['line_total'] ?? 0)) / $qty), $order->currency_code) ?? 0.0;
            $goods += $unit * $qty;

            $items[] = [
                'name' => mb_substr((string) ($line['name'] ?? 'Item'), 0, 255),
                'ware_key' => mb_substr((string) (($line['sku'] ?? '') ?: ($line['product_id'] ?? $line['key'] ?? '')), 0, 50),
                'cost' => $unit,
                'payment' => ['value' => 0],
                'weight' => max(1, $weights[(int) ($line['product_id'] ?? 0)] ?? intdiv($grams, $units)),
                'amount' => $qty,
            ];
        }

        if ($cod && $goods > 0) {
            $owedForGoods = max(0.0, ($this->due($order) ?? 0.0) - $this->shippingRoubles($order));
            $share = min(1.0, $owedForGoods / $goods);

            foreach ($items as &$item) {
                $item['payment']['value'] = round($item['cost'] * $share, 2);
            }
            unset($item);
        }

        return [
            'number' => '1',
            'weight' => $grams,
            'length' => max(1, (int) ($input['length_cm'] ?? 0) ?: $draft['length_cm']),
            'width' => max(1, (int) ($input['width_cm'] ?? 0) ?: $draft['width_cm']),
            'height' => max(1, (int) ($input['height_cm'] ?? 0) ?: $draft['height_cm']),
            'items' => $items,
        ];
    }

    /**
     * What the recipient pays for the delivery itself: what is owed beyond
     * what the goods collect — the shipping charge as a rule, less whatever
     * the rounding of the per-item amounts already took.
     *
     * @param  array<string, mixed>  $package
     */
    protected function shippingDue(Order $order, array $package): float
    {
        $collected = array_sum(array_map(
            fn ($item) => $item['payment']['value'] * $item['amount'],
            $package['items'],
        ));

        return max(0.0, round(($this->due($order) ?? 0.0) - $collected, 2));
    }

    /** Roubles still owed on the order, or null when its currency cannot be turned into roubles. */
    protected function due(Order $order): ?float
    {
        return Roubles::fromMinor(max(0, (int) $order->balance()), $order->currency_code);
    }

    protected function shippingRoubles(Order $order): float
    {
        return Roubles::fromMinor((int) $order->shipping_cost, $order->currency_code) ?? 0.0;
    }

    /** @param  array<string, mixed>  $payload */
    protected function codKopecks(array $payload): int
    {
        $sum = (float) ($payload['delivery_recipient_cost']['value'] ?? 0);

        foreach ($payload['packages'] ?? [] as $package) {
            foreach ($package['items'] ?? [] as $item) {
                $sum += (float) $item['payment']['value'] * (int) $item['amount'];
            }
        }

        return (int) round($sum * 100);
    }

    /**
     * The shop's order number, made unique per attempt: CDEK will not take the
     * same `number` twice from one account, and a cancelled waybill keeps its.
     */
    protected function number(Order $order): string
    {
        $earlier = Waybill::where('order_id', $order->id)->count();

        return (string) $order->order_number.($earlier > 0 ? '-'.($earlier + 1) : '');
    }

    /** "+7 (900) 123-45-67" → "+79001234567"; CDEK wants digits with the plus. */
    protected function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        // A Russian number typed the domestic way, 8 900…
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7'.substr($digits, 1);
        }

        return '+'.$digits;
    }

    /**
     * Grams and cubic centimetres of the order's goods, from the products as
     * they are now (the order keeps prices, not weights). Zero where nothing
     * is known — the form then starts from the module's default parcel.
     *
     * @return array{0: int, 1: int}
     */
    protected function measure(Order $order): array
    {
        $lines = array_values(array_filter((array) $order->items, 'is_array'));
        $products = Product::whereIn('id', array_filter(array_map(fn ($line) => (int) ($line['product_id'] ?? 0), $lines)))->get()->keyBy('id');
        app(Measurements::class)->prime($products);

        $grams = 0;
        $volume = 0;

        foreach ($lines as $line) {
            $product = $products[(int) ($line['product_id'] ?? 0)] ?? null;
            $qty = (int) ($line['qty'] ?? 0);

            $grams += (int) ($product?->resolvedWeight() ?? 0) * $qty;

            if ($box = $product?->resolvedBox()) {
                // Millimetres → cubic centimetres.
                $volume += (int) round($box['length'] * $box['width'] * $box['height'] / 1000) * $qty;
            }
        }

        return [$grams, $volume];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<int, int> product id => grams for one
     */
    protected function unitWeights(array $lines): array
    {
        $ids = array_filter(array_map(fn ($line) => (int) ($line['product_id'] ?? 0), $lines));
        $products = Product::whereIn('id', $ids)->get();
        app(Measurements::class)->prime($products);

        return $products->mapWithKeys(fn (Product $product) => [$product->id => (int) ($product->resolvedWeight() ?? 0)])->filter()->all();
    }

    /**
     * The default box from the settings, or a cube that holds the goods when
     * they do not fit in it — the same rule the live price uses.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    protected function box(int $volume): array
    {
        $length = max(1, (int) setting('torgash.cdek', 'default_length_cm', 20));
        $width = max(1, (int) setting('torgash.cdek', 'default_width_cm', 20));
        $height = max(1, (int) setting('torgash.cdek', 'default_height_cm', 10));

        if ($volume > $length * $width * $height) {
            $side = (int) ceil($volume ** (1 / 3));

            return [$side, $side, $side];
        }

        return [$length, $width, $height];
    }

    /**
     * @throws ValidationException
     */
    protected function refuse(string $message): never
    {
        throw ValidationException::withMessages(['waybill' => $message]);
    }
}
