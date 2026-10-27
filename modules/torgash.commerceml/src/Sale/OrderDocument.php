<?php

namespace Torgash\CommerceMl\Sale;

use App\Core\Support\Currencies;
use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Orders\CustomerRef;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\CommerceMl\Xml\Tags;
use Torgash\CommerceMl\Xml\Writer;

/**
 * One order as CommerceML's `<Документ>`.
 *
 * Three rules run through all of it.
 *
 * The money is the SHOP'S BOOKS. Every amount on the order row is in
 * `currency_code` and consistent with every other; the currency the customer
 * was quoted in and the one their bank was asked for are facts about the sale,
 * written into the open bag for anyone who wants them, and never the numbers
 * 1C accounts in. Exporting the charge currency instead would hand an
 * accountant a document whose lines do not add up to its own total.
 *
 * The document ADDS UP. Delivery goes in as a service line and so does anything
 * a module charges on top (a tax added at checkout, a fee), so that the sum of
 * the lines minus the discount is the order's total by construction rather than
 * by luck. Dropping the delivery line — tempting, since a Nova Poshta parcel is
 * no business of 1C's — is what produces documents an accountant has to fix by
 * hand a week later.
 *
 * A product is named by its `external_id` (§58) and nothing else. A product 1C
 * has never heard of is exported with a fallback identifier of ours: 1C will
 * not match it, which is correct — it is genuinely not in their nomenclature —
 * and the alternative, matching by name, invents links between things that
 * happen to be called the same.
 */
class OrderDocument
{
    /** @var array<int, string> product id => what 1C calls it */
    protected array $productIds = [];

    /** @var Collection<string, OrderStatus>|null */
    protected ?Collection $statuses = null;

    public function __construct(protected CustomerRef $refs) {}

    /**
     * Everything the batch will ask about, in one query each.
     *
     * @param  Collection<int, Order>  $orders
     */
    public function prime(Collection $orders): void
    {
        $ids = $orders
            ->flatMap(fn (Order $order) => collect($order->items ?? [])->pluck('product_id'))
            ->filter()->unique()->all();

        $this->productIds = $ids === []
            ? []
            : Product::whereIn('id', $ids)->pluck('external_id', 'id')->filter()->all();
    }

    public function render(Order $order): string
    {
        $xml = new Writer(declaration: false);

        $xml->open('document');
        $xml->el('id', $order->external_id ?: $order->order_number);
        $xml->el('number', $order->order_number);
        $xml->el('date', $order->created_at?->format('Y-m-d'));
        $xml->el('time', $order->created_at?->format('H:i:s'));
        $xml->el('operation', Tags::of('order_operation'));
        $xml->el('role', Tags::of('seller'));
        $xml->el('currency', $order->currency_code);
        $xml->el('rate', 1);
        $xml->el('total', $this->money($order, $order->total));

        $extras = $this->extras($order);

        $this->counterparty($xml, $order);
        $this->lines($xml, $order, $extras);
        $this->discount($xml, $order, $extras);
        $this->attributes($xml, $order);

        $xml->el('notes', $order->notes);
        $xml->close();

        return $xml->toString();
    }

    // ----------------------------------------------------------- the customer

    protected function counterparty(Writer $xml, Order $order): void
    {
        $xml->open('counterparties')->open('counterparty');

        // The buyer's own identifier, stable across their orders — see
        // Torgash\Checkout\Orders\CustomerRef. Derived on the way out for orders
        // placed before the column existed, so an old order does not export as
        // an anonymous new counterparty every time.
        $xml->el('id', $order->customer_ref ?: $this->refs->for($order));
        // A company's order is the COMPANY's: 1C files a legal counterparty
        // under its official name and tax number, and a private person under
        // their full name. The contact person stays in the contacts below.
        if ($order->company_name) {
            $xml->el('name', $order->company_name);
            $xml->el('official_name', $order->company_name);
            if ($order->company_tax_id) {
                $xml->el('tax_number', $order->company_tax_id);
            }
        } else {
            // A phone order may carry no name; 1C files a counterparty by
            // one, and the number is the only thing this buyer gave.
            $name = $order->customer_name ?: $order->phone;
            $xml->el('name', $name);
            $xml->el('counterparty_full_name', $name);
        }
        $xml->el('role', Tags::of('buyer'));

        $xml->open('contacts');
        $xml->attribute(Tags::of('contact_phone'), $order->phone);
        $xml->attribute(Tags::of('contact_email'), $this->email($order));
        $xml->close();

        if ($address = $this->address($order)) {
            $xml->open('address')->el('presentation', $address)->close();
        }

        $xml->close()->close();
    }

    /**
     * An address 1C can print, as one line.
     *
     * Not broken into `АдресноеПоле` parts: those are a fixed vocabulary of
     * Russian classifier names, our address is five free-text fields, and
     * guessing which of ours is their "Регион" would be worse than honest prose.
     */
    protected function address(Order $order): ?string
    {
        return implode(', ', array_filter([
            $order->postal_code,
            $order->countryName(),
            $order->city,
            $order->address_line,
        ])) ?: null;
    }

    /**
     * The email, or one made up out of the buyer's reference.
     *
     * 1C's counterparty card wants an address, and buying in one click will not
     * have one. A synthesised address is produced HERE and stored nowhere: the
     * shop's own record of that customer keeps saying, truthfully, that it has
     * no email for them.
     */
    protected function email(Order $order): ?string
    {
        if ($order->email) {
            return $order->email;
        }

        $domain = trim((string) setting('torgash.commerceml', 'sale_guest_domain', ''));

        if ($domain === '') {
            return null;
        }

        return ($order->customer_ref ?: $this->refs->for($order)).'@'.ltrim($domain, '@');
    }

    // -------------------------------------------------------------- the goods

    /** @param  list<array{code: string, label: string, amount: int, included: bool}>  $extras */
    protected function lines(Writer $xml, Order $order, array $extras): void
    {
        $xml->open('products');

        foreach ((array) $order->items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);

            $xml->open('product');
            // A line with no product behind it (a service charged once for a
            // configured line — see PLAN §33.3) is exported the way delivery
            // is: by name, with no identifier. Inventing `torgash:0` would put
            // a nomenclature id in 1C that names nothing.
            if ($productId > 0) {
                $xml->el('id', $this->productIds[$productId] ?? 'torgash:'.$productId);
            }
            $xml->el('name', $item['name'] ?? null);
            $xml->el('article', $item['sku'] ?? null);
            $this->unit($xml);
            $xml->el('price_per_unit', $this->money($order, (int) ($item['unit_price'] ?? 0)));
            $xml->el('quantity', (int) ($item['qty'] ?? 0));
            $xml->el('total', $this->money($order, (int) ($item['line_total'] ?? 0)));
            $xml->close();
        }

        $this->deliveryLine($xml, $order);
        $this->extraLines($xml, $order, $extras);

        $xml->close();
    }

    /**
     * Delivery, as the service line it is in every 1C document.
     *
     * The identifier is the shop's to supply, per method, because it is 1C's own
     * nomenclature id and nothing on this side can know it (Admin → Shipping →
     * the method itself). A method with none still exports — by name, without an
     * identifier — because a document whose lines do not add up to its total is
     * a worse thing to send than one with a line 1C has to be told about once.
     */
    protected function deliveryLine(Writer $xml, Order $order): void
    {
        if ((int) $order->shipping_cost === 0) {
            return;
        }

        $xml->open('product');
        $xml->el('id', $this->serviceId($order));
        $xml->el('name', $order->shipping_method_name ?: Tags::of('delivery'));
        $this->unit($xml);
        $xml->el('price_per_unit', $this->money($order, (int) $order->shipping_cost));
        $xml->el('quantity', 1);
        $xml->el('total', $this->money($order, (int) $order->shipping_cost));
        $xml->open('attributes')->attribute(Tags::of('item_type'), Tags::of('service'))->close();
        $xml->close();
    }

    protected function serviceId(Order $order): ?string
    {
        $perMethod = (array) setting('torgash.commerceml', 'sale_service_guids', []);
        $methodId = ($order->shipping_details ?? [])['shipping_method_id'] ?? null;

        return $perMethod[(string) $methodId]
            ?? (trim((string) setting('torgash.commerceml', 'sale_delivery_guid', '')) ?: null);
    }

    /**
     * Whatever else the order is charged for, read ONCE per document.
     *
     * From the order's own snapshot through the hook every other total reader
     * uses, so a tax module's line reaches 1C without this module having heard
     * of taxes. `included` lines are already inside the item prices and are
     * disclosed, not added — exporting them would charge them twice — and a
     * zero line is nothing to say at all.
     *
     * The list is read here rather than where it is used because it is used
     * TWICE (lines that add, lines that subtract) and a listener on this hook
     * is querying the database to answer.
     *
     * @return list<array{code: string, label: string, amount: int, included: bool}>
     */
    protected function extras(Order $order): array
    {
        return array_values(array_filter(
            hook_filter('checkout.order.extra_totals', [], ['order' => $order]),
            fn (array $line) => (int) ($line['amount'] ?? 0) !== 0 && ! ($line['included'] ?? false),
        ));
    }

    /**
     * The extra lines that ADD to the bill, as the services they are.
     *
     * A line that subtracts is not a service with a negative price — it is a
     * discount, and it leaves through `<Скидки>` with the coupon (see
     * discount()). CommerceML has an element for "money off this document" and
     * 1C's own processing reads it; a `<Товар>` priced at −500 is a document
     * some configurations refuse outright and none of them read as a discount.
     * The rule is a property of the FORMAT, which is why it lives here and
     * mentions no module by name.
     *
     * @param  list<array{code: string, label: string, amount: int, included: bool}>  $extras
     */
    protected function extraLines(Writer $xml, Order $order, array $extras): void
    {
        foreach ($extras as $line) {
            $amount = (int) $line['amount'];

            if ($amount < 0) {
                continue;
            }

            $xml->open('product');
            $xml->el('id', 'torgash:'.($line['code'] ?? 'extra'));
            $xml->el('name', $line['label'] ?? $line['code'] ?? '');
            $this->unit($xml);
            $xml->el('price_per_unit', $this->money($order, $amount));
            $xml->el('quantity', 1);
            $xml->el('total', $this->money($order, $amount));
            $xml->open('attributes')->attribute(Tags::of('item_type'), Tags::of('service'))->close();
            $xml->close();
        }
    }

    /** Pieces. The shop sells no fractional units, and 796 is what OKEI calls one. */
    protected function unit(Writer $xml): void
    {
        $xml->el('base_unit', 'шт', [
            'unit_code' => '796',
            'unit_full_name' => 'Штука',
            'unit_abbreviation' => 'PCE',
        ]);
    }

    /**
     * Every discount on the order: the coupon, plus each extra-totals line that
     * subtracts (an automatic promotion, and anything else that ever takes
     * money off through that hook).
     *
     * `УчтеноВСумме` is false on all of them for the same reason: our line
     * totals are the prices BEFORE any discount, so 1C must subtract them.
     * Spreading a discount across the lines instead would change the price of
     * goods on a document — the numbers the customer agreed to — to make one
     * subtraction easier. Each keeps its own name, because "why is this order
     * 30.00 cheaper" is answered by the accountant reading the document, not by
     * opening the shop.
     *
     * @param  list<array{code: string, label: string, amount: int, included: bool}>  $extras
     */
    protected function discount(Writer $xml, Order $order, array $extras): void
    {
        $discounts = [];

        // The coupon and the manager's own discount are two lines, as the
        // order prints them (Order::discountLines()); together they are
        // discount_amount, so the document still adds up.
        foreach ($order->discountLines() as $line) {
            $discounts[] = [$line['code'] === 'coupon' && $order->coupon_code ? $order->coupon_code : Tags::of('discount'), (int) $line['amount']];
        }

        foreach ($extras as $line) {
            if ((int) $line['amount'] < 0) {
                $discounts[] = [$line['label'] ?: Tags::of('discount'), -(int) $line['amount']];
            }
        }

        if ($discounts === []) {
            return;
        }

        $xml->open('discounts');

        foreach ($discounts as [$name, $amount]) {
            $xml->open('discount');
            $xml->el('name', $name);
            $xml->el('total', $this->money($order, $amount));
            $xml->el('discount_included', Tags::of('no'));
            $xml->close();
        }

        $xml->close();
    }

    // --------------------------------------------------------------- the rest

    /**
     * The open bag: everything CommerceML has no element for.
     *
     * These names are what 1C's own exchange rules match on, and they are the
     * spellings its standard processing expects — which is why they are literal
     * strings from Tags rather than anything configurable. A shop whose 1C wants
     * others adds them in 1C, where the rule lives.
     */
    protected function attributes(Writer $xml, Order $order): void
    {
        $xml->open('attributes');

        $xml->attribute(Tags::of('order_status'), $this->statusName($order));
        $xml->attribute(Tags::of('payment_method'), $order->payment_method_name);
        $xml->attribute(Tags::of('shipping_method'), $order->shipping_method_name);
        $xml->attribute(Tags::of('paid_flag'), Tags::of($order->payment_state === PaymentState::PAID ? 'yes' : 'no'));
        $xml->attribute(Tags::of('paid_at'), $order->paid_at?->format('Y-m-d H:i:s'));

        // Poured into another order (PLAN §40.10/4): its goods and money are
        // that order's document now, so 1C is told this one is cancelled — the
        // standard requisite its exchange processing reads — and which order
        // took it over.
        if ($order->merged_into_id) {
            $xml->attribute(Tags::of('cancelled'), 'true');
            $xml->attribute('Объединен с заказом', (string) \Torgash\Checkout\Models\Order::withTrashed()->whereKey($order->merged_into_id)->value('order_number'));
        }

        // What the customer was actually quoted and charged, when it was not the
        // books' currency. Disclosed rather than used: the document's own
        // amounts stay in one currency, and an accountant who needs to know what
        // the card was charged can read it here.
        if ($order->chargeCurrency() !== $order->currency_code) {
            $xml->attribute('Валюта покупателя', $order->chargeCurrency());
            $xml->attribute('Сумма в валюте покупателя', number_format(
                ($order->total / 100) * $order->chargeRate(), 2, '.', '',
            ));
        }

        $xml->close();
    }

    /**
     * The status as a NAME, in the language 1C is being written in.
     *
     * Not the code: the code is ours, and what an accountant reads in their
     * document list should be the words the shop uses for it. Loaded once —
     * a batch of two hundred orders has eight distinct statuses between them.
     */
    protected function statusName(Order $order): string
    {
        $this->statuses ??= OrderStatus::all()->keyBy('code');
        $locale = (string) setting('torgash.commerceml', 'content_locale', config('app.locale'));

        return $this->statuses->get($order->status)?->t('name', $locale) ?: (string) $order->status;
    }

    /**
     * Minor units as the decimal 1C reads, in the currency's own precision.
     *
     * A dot for the point and no thousands separator, always: this is a machine
     * reading it, and the one place a locale-aware number format would be a bug
     * rather than a nicety.
     */
    protected function money(Order $order, int $minor): string
    {
        $decimals = (int) (app(Currencies::class)->find($order->currency_code)?->decimals ?? 2);

        return number_format($minor / 100, $decimals, '.', '');
    }
}
