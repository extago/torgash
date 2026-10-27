<?php

namespace Torgash\Checkout\Models;

use App\Core\Support\Currencies;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Torgash\Checkout\Orders\OrderKey;
use Torgash\Checkout\Orders\PaymentCurrency;
use Torgash\Checkout\Orders\PaymentState;

class Order extends Model
{
    // The trash (see Orders\OrderTrash): a trashed order is out of every list,
    // count, export and API answer by the global scope, and still restorable.
    use SoftDeletes;

    protected $table = 'm_checkout_orders';

    protected $guarded = [];

    /**
     * The column defaults again, in PHP: without them a just-created order
     * reads back `payment_state === null` until somebody reloads it, and
     * whoever asks first — a hook listener, a mail template, an API
     * serializer — would see a state the row does not actually have.
     */
    protected $attributes = [
        'payment_state' => PaymentState::PENDING,
        'paid_total' => 0,
        'prepaid_total' => 0,
    ];

    /** @var array{currency: string, rate: float}|null */
    protected ?array $resolvedPayment = null;

    /** The manager's own discount on this order, as it came to (minor units). */
    public function manualDiscount(): int
    {
        return (int) ($this->manual_discount['amount'] ?? 0);
    }

    /** What other modules took off after the coupon (bonus points), frozen at placement. */
    public function otherDiscount(): int
    {
        return array_sum(array_map(fn ($line) => (int) ($line['amount'] ?? 0), (array) $this->other_discounts));
    }

    /** The coupon's part of `discount_amount`. */
    public function couponDiscount(): int
    {
        return max(0, (int) $this->discount_amount - $this->manualDiscount() - $this->otherDiscount());
    }

    /**
     * The discount as the lines everybody prints — the admin, the invoice, the
     * confirmation, the 1C document — so they cannot tell it differently.
     * The manager's reason is the shop's own note and is not among them.
     *
     * @return list<array{code: string, label: string, amount: int}>
     */
    public function discountLines(): array
    {
        $lines = [];

        if ($this->couponDiscount() > 0) {
            $lines[] = ['code' => 'coupon', 'label' => $this->coupon_code ? __('Coupon').' ('.$this->coupon_code.')' : __('Discount'), 'amount' => $this->couponDiscount()];
        }

        if ($this->manualDiscount() > 0) {
            $lines[] = ['code' => 'manual', 'label' => __('Discount'), 'amount' => $this->manualDiscount()];
        }

        foreach ((array) $this->other_discounts as $other) {
            if ((int) ($other['amount'] ?? 0) > 0) {
                $lines[] = ['code' => (string) ($other['code'] ?? 'other'), 'label' => (string) ($other['label'] ?? __('Discount')), 'amount' => (int) $other['amount']];
            }
        }

        return $lines;
    }

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'client' => 'array',
            'shipping_details' => 'array',
            'coupon_terms' => 'array',
            'manual_discount' => 'array',
            'other_discounts' => 'array',
            'discount_amount' => 'int',
            'subtotal' => 'int',
            'shipping_cost' => 'int',
            'total' => 'int',
            'paid_total' => 'int',
            'prepaid_total' => 'int',
            'deposit_amount' => 'int',
            'charge_rate' => 'float',
            'payment_rate' => 'float',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * The currency this order was quoted and charged in — the customer's, not
     * the shop's. Falls back to the books' currency, which is what a
     * single-currency shop and every order placed before this always had.
     */
    public function chargeCurrency(): string
    {
        return $this->charge_currency ?: (string) $this->currency_code;
    }

    /** How many units of the charge currency one unit of the books' currency is. */
    public function chargeRate(): float
    {
        return $this->charge_currency ? (float) ($this->charge_rate ?: 1) : 1.0;
    }

    /**
     * The currency the BANK is asked for, which is a third question again.
     *
     * The payment method decides it: an acquirer that only holds a hryvnia
     * account charges hryvnia whatever the shop prices in and whatever the
     * customer was browsing in. Frozen onto the order at checkout — and, for an
     * order placed before the shop configured any of this, worked out from the
     * method it was sold under and frozen on the way into the gateway
     * (Torgash\Checkout\Orders\PaymentCurrency). Answering it live until then is
     * what stops a Pay button promising an amount no acquirer would take.
     */
    public function paymentCurrency(): string
    {
        return $this->payment_currency ?: $this->resolvedPayment()['currency'];
    }

    /** Units of the payment currency per one unit of the books' currency. */
    public function paymentRate(): float
    {
        return $this->payment_currency
            ? (float) ($this->payment_rate ?: 1)
            : $this->resolvedPayment()['rate'];
    }

    /**
     * Memoised on the instance: the two methods above are called once per
     * printed amount, and for an unfrozen order the answer costs a look at the
     * payment methods table. Per instance and not static — a resolved answer
     * belongs to this order, and the row it reads may change between requests.
     *
     * @return array{currency: string, rate: float}
     */
    protected function resolvedPayment(): array
    {
        return $this->resolvedPayment ??= app(PaymentCurrency::class)->forOrder($this);
    }

    /**
     * Who placed it, for a screen: the name, or — an order taken by phone with
     * no name given — those words in the READER's language. Never stored: the
     * buyer's language is not the manager's.
     */
    public function customerLabel(): string
    {
        return filled($this->customer_name) ? $this->customer_name : __('Ordered by phone');
    }

    /** Is the customer charged in something other than what the order is printed in? */
    public function paymentDiffers(): bool
    {
        return $this->paymentCurrency() !== $this->chargeCurrency();
    }

    /**
     * A stored amount, printed the way the customer saw it.
     *
     * Not format_price(): that converts at TODAY's rate, so an invoice reprinted
     * a month later would quote a different number than the receipt. The rate is
     * the one frozen onto the order, deliberately — an order is a record of an
     * agreement, and the agreement was at that rate.
     */
    public function money(int $amount): string
    {
        return $this->print($amount, $this->chargeCurrency(), $this->chargeRate());
    }

    /**
     * The same amount as the bank will see it — the Pay button, and the "you
     * will be charged" line beside the total.
     *
     * Identical to money() whenever the two currencies agree, which is why
     * templates can use it wherever the number is about paying rather than
     * about what the order cost.
     */
    public function paymentMoney(int $amount): string
    {
        return $this->print($amount, $this->paymentCurrency(), $this->paymentRate());
    }

    /**
     * The same amount as an ADMIN reads it.
     *
     * With no viewing currency chosen — the default — that is the customer's:
     * an order is a record of an agreement and the screen should show the
     * agreement. With one chosen, every order on the screen is converted into
     * it at today's rate, which is the whole point of the switcher: twenty
     * orders in four currencies cannot be compared otherwise.
     *
     * Never for an input. What the item editor types is the books' own units,
     * so that saving an order cannot move its money through a rate.
     */
    public function adminMoney(int $amount): string
    {
        return admin_currency() === null ? $this->money($amount) : admin_price($amount, $this->currency_code);
    }

    protected function print(int $amount, string $code, float $rate): string
    {
        $currency = app(Currencies::class)->find($code);

        if (! $currency) {
            return number_format($amount / 100, 2).' '.$code;
        }

        return $currency->format(($amount / 100) * $rate);
    }

    /**
     * What to ask the gateway for: the amount in hundredths of the PAYMENT
     * currency, which is the unit every acquiring bills in.
     *
     * Rounded to the number of decimals that currency is shown with before
     * being turned into hundredths, so the card is charged the sum on the screen
     * and not a fraction more — a shop displaying whole hryvnia charges whole
     * hryvnia.
     */
    public function chargeMinor(?int $amount = null): int
    {
        $amount ??= $this->total;

        // No conversion, no rounding: the stored amount is already exact in
        // this currency, and re-rounding it to however many decimals the shop
        // chose to DISPLAY would change what the card is charged (a shop that
        // shows whole hryvnia still charges the kopecks it billed).
        if ($this->paymentCurrency() === $this->currency_code) {
            return $amount;
        }

        $decimals = (int) (app(Currencies::class)->find($this->paymentCurrency())?->decimals ?? 2);
        $shown = round(($amount / 100) * $this->paymentRate(), $decimals);

        return (int) round($shown * 100);
    }

    /**
     * The reverse: what a gateway's number means in the shop's own units.
     *
     * A gateway reports in the currency it was asked to charge, and everything
     * on this row — `total`, `paid_total` — is in the books' currency. Without
     * this a ₴42 732 payment against a $1 042.25 order reads as a fortyfold
     * overpayment.
     */
    public function baseFromCharge(int $chargeMinor): int
    {
        // Paying exactly what was asked settles the order exactly, without the
        // arithmetic being run backwards. Converting is lossy in the direction
        // the shop displays — ₴42 732 asked for a $1 042.25 order comes back as
        // $1 042.24 — and a cent of rounding would leave every converted order
        // reading as a cent short of paid, for ever.
        // What a gateway was asked for is the part no gift card covered, so
        // that is the exact answer to compare with.
        if ($chargeMinor === $this->chargeMinor($this->gatewayDue())) {
            return $this->gatewayDue();
        }

        // ...or the deposit, when that is what it was asked for.
        if ($this->deposit_amount && $chargeMinor === $this->chargeMinor(min($this->gatewayDue(), (int) $this->deposit_amount))) {
            return min($this->gatewayDue(), (int) $this->deposit_amount);
        }

        $rate = $this->paymentRate();

        return $rate > 0 ? (int) round($chargeMinor / $rate) : $chargeMinor;
    }

    /**
     * What is still owed, in minor units. Negative means the shop is holding
     * more than the order now costs — which is not a corner case but the normal
     * result of editing a paid order down, and the number a manager has to see
     * before deciding to refund the difference.
     */
    public function balance(): int
    {
        return $this->total - $this->paid_total;
    }

    /**
     * What is left for a GATEWAY to take: the total minus what was paid before
     * any gateway was asked (a gift card, PLAN §40.4). This, never `total`, is
     * what the bank is asked for — a card that covered a third of the order
     * must not be charged again at the till.
     */
    public function gatewayDue(): int
    {
        return max(0, (int) $this->total - (int) $this->prepaid_total);
    }

    /**
     * What a gateway is asked for NOW: the deposit, while an order sold with
     * one ("part online, the rest on delivery") has had nothing from a bank
     * yet; otherwise all that is left. Once the deposit is in, the rest is
     * the courier's, and a Pay link would be asking for money nobody agreed
     * to send online.
     */
    public function chargeNow(): int
    {
        $due = $this->gatewayDue();

        if ($this->deposit_amount && $this->gatewayPaid() <= 0) {
            return min($due, (int) $this->deposit_amount);
        }

        return max(0, $due - $this->gatewayPaid());
    }

    /** The part left for delivery when the order was sold with a deposit, else 0. */
    public function payOnDelivery(): int
    {
        if (! $this->deposit_amount) {
            return 0;
        }

        $online = max($this->gatewayPaid(), min($this->gatewayDue(), (int) $this->deposit_amount));

        return max(0, $this->gatewayDue() - $online);
    }

    /**
     * What a gateway is holding — `paid_total` without the prepaid part. The
     * amount a gateway's own refund and capture are measured against: the
     * bank never had the gift card's money and cannot give it back.
     */
    public function gatewayPaid(): int
    {
        return max(0, (int) $this->paid_total - (int) $this->prepaid_total);
    }

    /**
     * How the prepaid part is printed — one line per source ("Gift card
     * ····1234"), asked of whoever took it (`checkout.order.prepaid_lines`).
     * Empty for every order nothing was prepaid on, without asking anybody.
     *
     * @return list<array{label: string, amount: int}>
     */
    public function prepaidLines(): array
    {
        if ((int) $this->prepaid_total <= 0) {
            return [];
        }

        $lines = array_values(array_filter(
            (array) hook_filter('checkout.order.prepaid_lines', [], ['order' => $this]),
            fn ($line) => is_array($line) && isset($line['label'], $line['amount']),
        ));

        // A source that has gone (its module uninstalled) still took the
        // money: the sum is on the row, and is printed under a plain word.
        $told = array_sum(array_column($lines, 'amount'));

        if ($told < (int) $this->prepaid_total) {
            $lines[] = ['label' => __('Paid in advance'), 'amount' => (int) $this->prepaid_total - $told];
        }

        return $lines;
    }

    /**
     * The rows printed under an order's total about HOW it is paid, when that
     * is more than "all of it, one way": what was paid in advance (a gift
     * card), and for an order sold with a deposit what goes online and what
     * on delivery. Empty for the ordinary order. One list for the
     * confirmation, the invoice, the admin and the letter.
     *
     * @return list<array{label: string, amount: int, minus: bool, strong: bool}>
     */
    public function paymentSplit(): array
    {
        $rows = array_map(
            fn (array $line) => ['label' => $line['label'], 'amount' => (int) $line['amount'], 'minus' => true, 'strong' => false],
            $this->prepaidLines(),
        );

        if ($this->deposit_amount) {
            $online = $this->gatewayPaid();

            $rows[] = $online > 0
                ? ['label' => __('Paid online'), 'amount' => $online, 'minus' => false, 'strong' => false]
                : ['label' => __('To pay online now'), 'amount' => min($this->gatewayDue(), (int) $this->deposit_amount), 'minus' => false, 'strong' => true];
            $rows[] = ['label' => __('To pay on delivery'), 'amount' => $this->payOnDelivery(), 'minus' => false, 'strong' => $online > 0];
        } elseif ($rows !== []) {
            $rows[] = ['label' => __('To pay'), 'amount' => $this->gatewayDue(), 'minus' => false, 'strong' => true];
        }

        return $rows;
    }

    /** Was anything sent — false for an order of goods nobody delivers (PLAN §40.10/2). */
    public function hasDelivery(): bool
    {
        return $this->shipping_method_name !== null || (int) $this->shipping_cost > 0;
    }

    public function isPaid(): bool
    {
        return $this->payment_state === PaymentState::PAID;
    }

    public function logs()
    {
        return $this->hasMany(OrderLog::class)->oldest();
    }

    /**
     * The order was placed with a delivery that had no price yet, and a human
     * still has to name one — so its zero shipping must never read as "free"
     * on an invoice, an email or the order card.
     *
     * The flag is written into the delivery snapshot by whoever owns delivery
     * (`checkout.delivery.snapshot`) and cleared when the amount is set. An
     * order placed before that module existed, or without delivery at all,
     * simply has no such key.
     */
    public function deliveryPending(): bool
    {
        return (bool) (($this->shipping_details ?? [])['pending_quote'] ?? false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * A name frozen onto the order, read in the READER's language — but only
     * when it is merely another language of the same words.
     *
     * The order keeps what the buyer saw, in the language they shopped in, and
     * that stays the record (the invoice, the letters). A manager reading in
     * another language, though, was handed "Arrange with manager" beside
     * "Обсудить с менеджером" for the same method. So: if the snapshot is one
     * of the thing's current translations, show the reader's; if it is not —
     * renamed since, typed by hand, a configured line's composed name — the
     * snapshot is what it was sold as, and it stands.
     */
    public static function sameWordsFor(?string $snapshot, mixed $translations): ?string
    {
        if ($snapshot === null || ! is_array($translations) || ! in_array($snapshot, $translations, true)) {
            return $snapshot;
        }

        return pick_translation($translations) ?? $snapshot;
    }

    public function paymentMethodLabel(): ?string
    {
        return self::sameWordsFor($this->payment_method_name, $this->paymentMethod?->name);
    }

    /** Delivery is another module's, so the words are asked of whoever owns it. */
    public function shippingMethodLabel(): ?string
    {
        return hook_filter('checkout.order.shipping_method_name', $this->shipping_method_name, ['order' => $this]);
    }

    /** @var array<int, mixed>|null product id => its name translations, for itemLabel() */
    protected ?array $productNames = null;

    /** A line's name in the reader's language, on the same terms as sameWordsFor(). */
    public function itemLabel(array $item): string
    {
        $this->productNames ??= \Torgash\Catalog\Models\Product::query()
            ->whereIn('id', collect($this->items ?? [])->pluck('product_id')->filter()->unique()->all())
            ->get(['id', 'name'])->mapWithKeys(fn ($product) => [$product->id => $product->name])->all();

        return (string) self::sameWordsFor($item['name'] ?? '', $this->productNames[$item['product_id'] ?? 0] ?? null);
    }

    /**
     * The employee dealing with this order. Nullable and freely reassignable —
     * it is a work assignment, not a record of who placed the order (that is
     * user_id, the customer) and not who touched it (that is the log).
     */
    /**
     * The customer's own link to this order, carrying its key.
     *
     * What goes in an email and what a guest keeps: the confirmation page
     * accepts the key (see CheckoutController::authorizeCustomerView), so this
     * is the one address that works for somebody with no account and no
     * session. Not expiring on purpose — an order is a thing people come back
     * to months later, and an expired link in an old email is a support call.
     *
     * Built through OrderKey, which is where the locale problem is solved: the
     * key belongs to the order, so the same link opens it in any language.
     */
    public function customerUrl(?string $locale = null): string
    {
        return OrderKey::url($this, 'checkout.confirmation', $locale ?? $this->locale);
    }

    /** The printable copy, reachable by the same key. */
    public function invoiceUrl(?string $locale = null): string
    {
        return OrderKey::url($this, 'checkout.invoice', $locale ?? $this->locale);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * The stored country as a name, if anyone can name it.
     *
     * Asked through the same hook the checkout form fills its select from, so
     * an order placed on a shop that later uninstalls the locations registry
     * degrades to showing the ISO code rather than to an error.
     */
    public function countryName(): ?string
    {
        if (! $this->country) {
            return null;
        }

        $named = collect(hook_filter('checkout.location.countries', []))
            ->firstWhere('code', $this->country);

        return $named['name'] ?? $this->country;
    }

    /**
     * The order this one was poured into, when it was (PLAN §40.10/4) —
     * following a chain to its end: B into A, and A later into Z, is Z.
     */
    public function mergeRoot(): self
    {
        $order = $this;
        $seen = [$order->id => true];

        while ($order->merged_into_id && ! isset($seen[$order->merged_into_id])) {
            $next = static::withTrashed()->find($order->merged_into_id);
            if (! $next) {
                break;
            }
            $seen[$next->id] = true;
            $order = $next;
        }

        return $order;
    }

    /**
     * This order and every order poured into it, however deep — whose money
     * and payments are now this order's. A seen-set, because a looping
     * `merged_into_id` is a corrupt row, not an impossible one.
     *
     * @return list<int>
     */
    public function mergedIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $next = static::withTrashed()->whereIn('merged_into_id', $frontier)->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = [...$ids, ...$next];
            $frontier = $next;
        }

        return $ids;
    }
}
