<?php

namespace Torgash\Catalog\Models;

use App\Core\Support\HasTranslations;
use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Torgash\Catalog\Support\AssignsCode;

class PriceType extends Model
{
    use AssignsCode, HasTranslations;

    protected $table = 'm_catalog_price_types';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'is_base' => 'bool',
            'adjustment_percent' => 'int',
            'requires_auth' => 'bool',
            'enabled' => 'bool',
            'show_on_storefront' => 'bool',
            'storefront_note' => 'array',
        ];
    }

    protected function codeFallbackPrefix(): string
    {
        return 'PRICE-TYPE';
    }

    /** Empty = visible to every customer group. */
    public function customerGroups(): BelongsToMany
    {
        return $this->belongsToMany(CustomerGroup::class, 'm_catalog_price_type_customer_group');
    }

    public function markupRules(): HasMany
    {
        return $this->hasMany(PriceTypeMarkupRule::class, 'price_type_id')->orderBy('sort');
    }

    /** First enabled rule (in sort order) whose conditions all match — or null, meaning "no formula, use adjustment_percent/override". */
    public function matchingRuleFor(Product $product): ?PriceTypeMarkupRule
    {
        return $this->markupRules->first(fn (PriceTypeMarkupRule $rule) => $rule->enabled && $rule->matches($product));
    }

    public function visibleTo(?User $user): bool
    {
        if ($this->requires_auth && ! $user) {
            return false;
        }

        $allowedGroupIds = $this->customerGroups->pluck('id');
        if ($allowedGroupIds->isEmpty()) {
            return true;
        }

        return $user && $allowedGroupIds->contains($user->customer_group_id);
    }

    protected static function booted(): void
    {
        // Exactly one is_base row — setting one true unsets every other.
        static::saving(function (self $type) {
            if ($type->is_base) {
                static::where('id', '!=', $type->id ?? 0)->update(['is_base' => false]);
            }
        });
    }
}
