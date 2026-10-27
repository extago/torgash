<?php

namespace Torgash\Warehouses\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Torgash\Warehouses\Hours;

class Warehouse extends Model
{
    use HasTranslations;

    protected $table = 'm_warehouses';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'hours' => 'array',
            'transfer_days' => 'int',
            'is_default' => 'bool',
            'enabled' => 'bool',
            'is_public' => 'bool',
            'is_pickup' => 'bool',
        ];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * The places a customer may be told about. A disabled warehouse is never
     * one: "disabled" means the shop does not sell from here, and a location
     * whose stock is not for sale has no business being offered.
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('enabled', true)->where('is_public', true);
    }

    /** Public AND able to hand an order over — the pickup point picker's list. */
    public function scopePickup(Builder $query): Builder
    {
        return $query->public()->where('is_pickup', true);
    }

    /**
     * The warehouse the shop dispatches from — the sender's address, as far as
     * a carrier is concerned.
     *
     * The default warehouse first, but only if it has been placed on the map:
     * a shop that gave a town to one warehouse and not to the default one meant
     * the one it filled in, and answering "no origin" because of an untouched
     * field would silently price parcels from nowhere. Priority order decides
     * the rest, exactly as it does when stock is taken.
     */
    public static function dispatching(): ?self
    {
        return static::query()->enabled()->whereNotNull('place_id')
            ->orderByDesc('is_default')->orderBy('sort')->orderBy('id')
            ->first();
    }

    /** @return list<array{days: string, time: string}> */
    public function hoursSummary(): array
    {
        return Hours::summary($this->hours);
    }

    /** How many products have a stock row here — not the sum of quantities, just the count. */
    public function productCount(): int
    {
        return DB::table('m_warehouse_product_stock')->where('warehouse_id', $this->id)->count();
    }

    /** How many products have units here (a row at zero is not stock) — what stops a delete. */
    public function stockedCount(): int
    {
        return DB::table('m_warehouse_product_stock')->where('warehouse_id', $this->id)->where('qty', '<>', 0)->count();
    }
}
