<?php

namespace Torgash\Checkout;

use Torgash\Cart\CartManager;
use Torgash\Checkout\Models\Coupon;

/**
 * Reads the code CartManager stores (a plain string it knows nothing about)
 * and applies torgash.checkout's own validation/discount rules on top.
 */
class CouponManager
{
    public function __construct(protected CartManager $cart) {}

    /**
     * What a coupon is a percentage OF, and what it is measured against.
     *
     * The cart's own total, minus whatever another module says must not be
     * discounted again — goods that are already cheaper because they are part
     * of a kit (PLAN §33.8). Asked through a filter rather than known here:
     * checkout has no idea what a kit is, and a coupon that halves a case
     * already sold at half price is how a shop sells below cost.
     */
    public function base(): int
    {
        return max(0, (int) hook_filter('checkout.coupon.base', (int) $this->cart->total(), ['cart' => $this->cart]));
    }

    /** @return array{ok: bool, message: string} */
    public function apply(string $code): array
    {
        // Codes are stored in capitals (the admin form writes them so), and a
        // customer typing "summer10" from a newsletter means SUMMER10.
        $code = mb_strtoupper(trim($code));
        $coupon = Coupon::where('code', $code)->first();
        $subtotal = $this->base();

        // One box for every code a customer can hold (PLAN §40.4): a code that
        // is not a coupon is offered to whoever else issues codes — a gift
        // card — and the customer never has to know which kind they typed.
        if (! $coupon) {
            $answer = hook_filter('checkout.code.apply', null, ['code' => $code, 'cart' => $this->cart]);

            if (is_array($answer) && isset($answer['ok'])) {
                return ['ok' => (bool) $answer['ok'], 'message' => (string) ($answer['message'] ?? '')];
            }
        }

        if (! $coupon || ! $coupon->isValidFor($subtotal)) {
            return ['ok' => false, 'message' => __('This coupon code is not valid.')];
        }

        $this->cart->setCouponCode($coupon->code);

        return ['ok' => true, 'message' => __('Coupon applied.')];
    }

    /**
     * The box's own words: "Have a coupon?", or "Coupon or gift card?" once a
     * module answers `checkout.code.apply` and says so here.
     */
    public static function placeholder(): string
    {
        return (string) hook_filter('checkout.code.placeholder', __('Have a coupon?'));
    }

    /**
     * Whether the box takes other codes too, and so stays open after a coupon
     * is on the basket — a coupon is one per order, gift cards are not.
     */
    public static function takesOtherCodes(): bool
    {
        return (bool) hook_filter('checkout.code.others', false);
    }

    public function remove(): void
    {
        $this->cart->setCouponCode(null);
    }

    /** The currently applied coupon, or null if none/no longer valid for the current subtotal. */
    public function current(): ?Coupon
    {
        $code = $this->cart->couponCode();

        if (! $code) {
            return null;
        }

        $coupon = Coupon::where('code', mb_strtoupper($code))->first();

        return $coupon && $coupon->isValidFor($this->base()) ? $coupon : null;
    }

    /** The coupon's own part. */
    public function couponDiscount(): int
    {
        return $this->current()?->discountFor($this->base()) ?? 0;
    }

    /**
     * Discounts AFTER the coupon that other modules give — bonus points
     * (PLAN §40.5) — asked through `checkout.cart.discounts` and measured
     * against what the coupon left. Each line is `{code, label, amount, …}`;
     * together they never exceed that remainder.
     *
     * Memoised per basket state: the summary, the quote and the placement all
     * ask it in one request.
     *
     * @return list<array<string, mixed>>
     */
    public function otherDiscounts(): array
    {
        $base = max(0, (int) $this->cart->total() - $this->couponDiscount());
        $key = $base.'|'.json_encode($this->cart->checkout());

        if ($this->otherMemo !== null && $this->otherMemo[0] === $key) {
            return $this->otherMemo[1];
        }

        $lines = hook_filter('checkout.cart.discounts', [], ['cart' => $this->cart, 'base' => $base]);
        $left = $base;
        $kept = [];

        foreach (is_array($lines) ? $lines : [] as $line) {
            $amount = min($left, max(0, (int) ($line['amount'] ?? 0)));

            if (! is_array($line) || $amount <= 0) {
                continue;
            }

            $kept[] = ['amount' => $amount] + $line;
            $left -= $amount;
        }

        $this->otherMemo = [$key, $kept];

        return $kept;
    }

    /** @var array{0: string, 1: list<array<string, mixed>>}|null */
    protected ?array $otherMemo = null;

    /**
     * Everything taken off the goods: the coupon, then what other modules
     * give after it. This is `discount` on the Quote and `discount_amount` on
     * the order — so a tax is charged on what is left, as it must be.
     */
    public function discount(): int
    {
        return $this->couponDiscount() + array_sum(array_column($this->otherDiscounts(), 'amount'));
    }
}
