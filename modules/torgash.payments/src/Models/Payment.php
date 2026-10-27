<?php

namespace Torgash\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;

/**
 * One payment at a gateway — an invoice, a PaymentIntent, a LiqPay attempt —
 * with its own state and what it holds now, in the shop's books. See the
 * migration that created the table for why an order has several.
 */
class Payment extends Model
{
    protected $table = 'm_payments_payments';

    protected $guarded = [];

    public static function auditLabel(): string
    {
        return 'Gateway payment';
    }

    protected function casts(): array
    {
        return ['requested' => 'int', 'amount' => 'int'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Money this payment holds now — a hold included, a refunded part not. */
    public function held(): int
    {
        return PaymentState::holdsMoney($this->state) ? max(0, (int) $this->amount) : 0;
    }

    public function isHold(): bool
    {
        return $this->state === PaymentState::AUTHORIZED && $this->held() > 0;
    }

    /** Nobody has paid it yet: an invoice that may still be paid, or cancelled. */
    public function isOpen(): bool
    {
        return $this->state === PaymentState::PENDING;
    }

    /** Has the gateway ever said anything about it that we acted on? */
    public function heardFrom(): bool
    {
        return Transaction::where('payment_id', $this->id)->where('applied', true)->exists();
    }
}
