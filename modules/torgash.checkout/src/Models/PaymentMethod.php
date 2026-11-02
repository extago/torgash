<?php

namespace Torgash\Checkout\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class PaymentMethod extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia;

    protected $table = 'm_checkout_payment_methods';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'instructions' => 'array',
            'currencies' => 'array',
            'enabled' => 'bool',
            'deposit_value' => 'int',
            'surcharge_percent' => 'int',
            'surcharge_fixed' => 'int',
            'surcharge_title' => 'array',
            'customer_groups' => 'array',
            'shipping_methods' => 'array',
        ];
    }

    /**
     * The method's mark, shown beside it wherever a customer picks one — the
     * same idiom as ShippingMethod's carrier logo. A single file with no
     * conversions: it renders at one small size and a shop uploads an
     * already-small PNG, so the thumbnail pipeline would only cost work and
     * lose the transparency a logo needs.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
    }

    public function logoUrl(): ?string
    {
        return $this->getFirstMediaUrl('logo') ?: null;
    }

    /**
     * How much of an order owing `$due` this method takes NOW, when it takes
     * only part online and the rest on delivery (PLAN §40.10/1) — or null
     * when it takes the whole thing. Never more than is owed: a 200 deposit on
     * a 150 order is the order.
     */
    public function depositFor(int $due): ?int
    {
        $value = (int) $this->deposit_value;

        if ($due <= 0 || $value <= 0) {
            return null;
        }

        $deposit = match ($this->deposit_type) {
            'fixed' => $value,
            'percent' => round_money($due * min(100, $value) / 100),
            default => null,
        };

        return $deposit !== null && $deposit < $due ? max(1, $deposit) : null;
    }

    /**
     * Does this method take money in that currency at all?
     *
     * An empty list means "any", which is what every method has until somebody
     * says otherwise — a shop that never thought about currencies must not
     * discover that its payment methods have quietly stopped being offered.
     */
    public function accepts(string $code): bool
    {
        $accepted = array_filter((array) $this->currencies);

        return $accepted === [] || in_array(strtoupper($code), array_map('strtoupper', $accepted), true);
    }

    /**
     * What a customer quoted in `$code` would actually be charged in, or null
     * when this method cannot serve them at all.
     *
     * Three answers, and each is a real shop:
     *   the currency itself — the method takes it (the ordinary case);
     *   another currency — the method takes only that one and the shop chose to
     *     convert, which is a dollar-priced Ukrainian shop billing in hryvnia;
     *   null — the method takes neither, so it is not offered. A courier who
     *     cannot accept dollars must not appear on a dollar order and fail at
     *     the door.
     */
    public function chargeCurrencyFor(string $code): ?string
    {
        if ($this->accepts($code)) {
            return strtoupper($code);
        }

        return $this->charge_currency ? strtoupper($this->charge_currency) : null;
    }

    /** May this method be offered for an order quoted in `$code`? */
    public function availableFor(string $code): bool
    {
        return $this->chargeCurrencyFor($code) !== null;
    }

    public const BUYERS = [
        'all' => 'Anybody',
        'person' => 'Private persons only',
        'company' => 'Companies only',
    ];

    /**
     * Why this method is not offered to such an order — an empty list when it
     * is (PLAN §66). The checkout leaves it out; a manager placing an order
     * is told, and decides.
     *
     * `$buyer` person | company (null = a person, as the form starts);
     * `$customer` the catalogue's words for who is buying (guest,
     * registered, group:<id> — customerTokens()); `$offerId` the delivery
     * chosen ("method" or "method:offer"); `$needsDelivery` false for goods
     * nobody sends.
     *
     * @param  list<string>  $customer
     * @return list<string>
     */
    public function refusals(?string $buyer, array $customer, ?string $offerId, bool $needsDelivery = true): array
    {
        $reasons = [];

        if (($this->buyers ?? 'all') !== 'all' && $this->buyers !== ($buyer ?: 'person')) {
            $reasons[] = $this->buyers === 'company'
                ? __('This payment method is for companies only.')
                : __('This payment method is for private persons only.');
        }

        $groups = array_values(array_filter((array) ($this->customer_groups ?? [])));
        if ($groups !== [] && array_intersect($customer, array_map('strval', $groups)) === []) {
            $reasons[] = __('This payment method is not offered to this customer group.');
        }

        $methods = array_map('strval', array_filter((array) ($this->shipping_methods ?? [])));
        if ($methods !== [] && ! ($needsDelivery && $offerId !== null && $offerId !== ''
            && in_array(explode(':', $offerId, 2)[0], $methods, true))) {
            $reasons[] = __('This payment method is not offered with this delivery.');
        }

        return $reasons;
    }

    /**
     * Why this method would not be offered to that placed order — what the
     * order screen warns a manager about (PLAN §66). The order's own answers:
     * a company if it carries one, its customer's group, its delivery.
     *
     * @return list<string>
     */
    public function refusalsForOrder(Order $order): array
    {
        $offer = ((array) ($order->shipping_details ?? []))['shipping_method_id'] ?? null;

        return $this->refusals(
            filled($order->company_name) ? 'company' : 'person',
            self::customerTokens($order->user_id ? \App\Models\User::find($order->user_id) : null),
            $offer !== null ? (string) $offer : null,
            $order->hasDelivery(),
        );
    }

    /**
     * What this method is narrowed to, in words — for a list a manager picks
     * from before the order exists (the admin's new order).
     */
    public function restrictionNote(): ?string
    {
        $parts = [];

        if (($this->buyers ?? 'all') !== 'all') {
            $parts[] = __(self::BUYERS[$this->buyers] ?? $this->buyers);
        }
        if (array_filter((array) ($this->customer_groups ?? []))) {
            $parts[] = __('some customer groups');
        }
        if (array_filter((array) ($this->shipping_methods ?? []))) {
            $parts[] = __('some deliveries');
        }

        return $parts ? __('At the checkout: :what', ['what' => implode(', ', $parts)]) : null;
    }

    /**
     * Who is buying, in the catalogue's words (Assortment): a guest, or an
     * account and its group. No staff exception: a manager trying the
     * checkout sees what a customer of their group would.
     *
     * @return list<string>
     */
    public static function customerTokens(?\App\Models\User $user): array
    {
        return $user
            ? array_values(array_filter(['registered', $user->customer_group_id ? 'group:'.$user->customer_group_id : null]))
            : ['guest'];
    }

    /**
     * The method a placed order was sold under.
     *
     * By id, which is what orders placed since that column exists carry. The
     * name is the RECORD of what the customer bought and stays a snapshot; it
     * is not a join, and using it as one is how renaming a method used to
     * silently detach every older order from its gateway.
     *
     * The by-name search survives as the fallback for the orders the backfill
     * could not match — a method renamed before the column existed. It compares
     * against every translation, because the name on the order is in the
     * language the CUSTOMER was shopping in and whoever is looking at the order
     * rarely is. Finding nothing is honest: nobody can say what it was sold
     * under any more.
     */
    public static function forOrder(Order $order): ?self
    {
        if ($order->payment_method_id) {
            return static::find($order->payment_method_id);
        }

        if (! $order->payment_method_name) {
            return null;
        }

        return static::query()->get()
            ->first(fn (self $method) => in_array(
                $order->payment_method_name,
                array_values((array) $method->name),
                true,
            ));
    }
}
