<?php

namespace Torgash\Motivation\Models;

use App\Core\Support\HasTranslations;
use App\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;

class MotivationItem extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia;

    protected $table = 'm_motivation_items';

    protected $guarded = [];

    public const ICON_TYPES = ['none', 'image', 'lucide'];

    public const CONTENT_TYPES = ['html', 'categories', 'payment_methods', 'shipping_methods'];

    public const COLORS = ['primary', 'secondary', 'accent', 'neutral', 'info', 'success', 'warning', 'error'];

    /** The theme's colour names as words, each saying which colour it is. */
    public const COLOR_LABELS = [
        'primary' => 'Main colour',
        'secondary' => 'Second colour',
        'accent' => 'Accent colour',
        'neutral' => 'Neutral colour',
        'info' => 'Blue (info)',
        'success' => 'Green (success)',
        'warning' => 'Amber (warning)',
        'error' => 'Red (error)',
    ];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Motivation card';
    }

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'content' => 'array',
            'expanded' => 'bool',
            'enabled' => 'bool',
            'exclude_categories' => 'bool',
            'exclude_products' => 'bool',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('icon')->singleFile();
    }

    public function iconUrl(): ?string
    {
        return $this->getFirstMediaUrl('icon') ?: null;
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'm_motivation_item_categories');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'm_motivation_item_products');
    }

    public function customerGroups(): BelongsToMany
    {
        return $this->belongsToMany(CustomerGroup::class, 'm_motivation_item_customer_groups');
    }

    /**
     * Enabled items whose scoping matches $product and $customerGroupId,
     * sorted for display. $customerGroupId is null for a guest.
     */
    public static function forProduct(Product $product, ?int $customerGroupId): Collection
    {
        $categoryIds = $product->categories->pluck('id')->all();

        return static::query()
            ->where('enabled', true)
            ->with(['categories:id', 'products:id', 'customerGroups:id'])
            ->orderBy('sort')
            ->get()
            ->filter(fn (self $item) => $item->matchesCategories($categoryIds)
                && $item->matchesProduct($product->id)
                && $item->matchesCustomerGroup($customerGroupId))
            ->values();
    }

    /**
     * An empty pivot means "show everywhere" regardless of the exclude flag —
     * that flag only has a meaning once at least one category/product is
     * actually picked, so an admin can never end up hiding a card everywhere
     * just by leaving a picker untouched.
     */
    protected function matchesCategories(array $productCategoryIds): bool
    {
        // The picked categories and everything under them — a card written for
        // "Electronics" belongs on the cameras in it (Catalog\Support\CategoryTree).
        $scoped = app(CategoryTree::class)->descendants($this->categories->pluck('id')->all());

        if (empty($scoped)) {
            return true;
        }

        $intersects = (bool) array_intersect($productCategoryIds, $scoped);

        return $this->exclude_categories ? ! $intersects : $intersects;
    }

    protected function matchesProduct(int $productId): bool
    {
        $scoped = $this->products;

        if ($scoped->isEmpty()) {
            return true;
        }

        $contains = $scoped->contains('id', $productId);

        return $this->exclude_products ? ! $contains : $contains;
    }

    /** No group restriction = visible to everyone, guests included. */
    protected function matchesCustomerGroup(?int $customerGroupId): bool
    {
        if ($this->customerGroups->isEmpty()) {
            return true;
        }

        return $customerGroupId !== null && $this->customerGroups->contains('id', $customerGroupId);
    }
}
