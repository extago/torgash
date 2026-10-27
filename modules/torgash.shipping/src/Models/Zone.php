<?php

namespace Torgash\Shipping\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    use HasTranslations;

    protected $table = 'm_shipping_zones';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Delivery zone';
    }

    protected function casts(): array
    {
        return ['name' => 'array', 'enabled' => 'bool'];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ZoneRule::class);
    }

    /** What is offered here: one rate per method priced in this zone. */
    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class);
    }
}
