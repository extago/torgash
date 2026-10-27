<?php

namespace Torgash\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;

/**
 * Which payment method is wired to which gateway.
 *
 * A table of ours keyed by the method's id, never a column on checkout's table:
 * a module may not patch another module's schema, and uninstalling this one has
 * to leave the methods exactly as they were.
 */
class Binding extends Model
{
    protected $table = 'm_payments_gateways';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Payment gateway binding';
    }

    protected function casts(): array
    {
        return ['config' => 'array', 'test_mode' => 'bool'];
    }

    /**
     * What a gateway is handed: its own fields plus `test_mode`.
     *
     * The switch lives on the binding because every gateway has one, but what
     * it MEANS is the gateway's: monobank tells a test token from a live one
     * by the token itself, so for it this is a label; LiqPay sends
     * `sandbox: 1`, so for it this is the difference between play money and
     * a real charge — and a gateway that never saw the switch would take a
     * shop's first real payments while the screen said "test".
     *
     * @return array<string, mixed>
     */
    public function gatewayConfig(): array
    {
        return ['test_mode' => (bool) $this->test_mode] + (array) ($this->config ?? []);
    }

    public function method(): ?PaymentMethod
    {
        return PaymentMethod::find($this->payment_method_id);
    }

    /**
     * The binding behind a placed order.
     *
     * Asked of checkout, which owns the question: an order carries the method's
     * id since 2026-09-04 and its NAME before that, and PaymentMethod::forOrder
     * knows both. This module only needs the answer.
     *
     * It used to do the name matching itself, against the CURRENT locale's
     * translation — so an order placed in Russian could not find its gateway
     * while an admin browsed in English, and renaming a method detached every
     * older order silently.
     */
    public static function forOrder(Order $order): ?self
    {
        $method = PaymentMethod::forOrder($order);

        return $method ? static::where('payment_method_id', $method->id)->first() : null;
    }
}
