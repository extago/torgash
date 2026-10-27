<?php

namespace Torgash\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One requirement a cart must meet for its method to be offered. */
class MethodCondition extends Model
{
    protected $table = 'm_shipping_method_conditions';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Delivery condition';
    }

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }

    /** @return list<int> the value as a list of ids, however it was stored */
    public function ids(): array
    {
        return array_values(array_filter(array_map('intval', (array) ($this->value['ids'] ?? []))));
    }

    public function text(string $key): string
    {
        return trim((string) ($this->value[$key] ?? ''));
    }

    public function amount(): int
    {
        return (int) ($this->value['amount'] ?? 0);
    }
}
