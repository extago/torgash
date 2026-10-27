<?php

namespace Torgash\Collections\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Torgash\Catalog\Filters\FilterPages;
use Torgash\Catalog\Filters\Flags;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Media\Gallery;

/**
 * A collection of products and its page.
 *
 * WHICH products is the catalogue filter's own vocabulary, saved — the same
 * pieces Listing::applyFilters() narrows a category with, so a collection
 * means exactly what the same ticks in the filter panel would:
 * - `categories` — any of them, each with everything under it;
 * - `features` — per characteristic, any of its values; every characteristic must match;
 * - `labels` — any of them, manual and by their rules (applyConditions);
 * - `flags` — the filter's yes/no boxes by registry key (in stock, on sale, a
 *   module's own), through Flags::applyKeys() — a key nobody answers for
 *   narrows to nothing, as on a published filter page;
 * - `price_min` / `price_max` — the shop's books, minor units.
 * The pieces that are set must ALL hold. Products picked by hand are added to
 * what the conditions find; products left out never appear. With no
 * conditions and nothing picked the collection is empty — never "the whole
 * catalogue" by accident.
 *
 * Pictures inside the description are the media module's `content`
 * collection (registered as the owner `collection`), so the editor offers
 * them as on a product; they go when the collection does.
 */
class Collection extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia;

    protected $table = 'm_collections';

    protected $guarded = [];

    /** Its files' folder on the public disk (the cover). */
    public const FILES = 'collections';

    protected $attributes = [
        'enabled' => true,
        'listed' => true,
        'sort' => 0,
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'meta_title' => 'array',
            'meta_description' => 'array',
            'conditions' => 'array',
            'enabled' => 'boolean',
            'listed' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Collection $collection) => Storage::disk('public')->deleteDirectory(self::FILES.'/'.$collection->id));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        Gallery::conversions($this, $media);
    }

    public function looks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Look::class)->orderBy('sort')->orderBy('id');
    }

    /** Switched on and inside its dates — what the storefront shows. */
    public function scopeShown(Builder $query): Builder
    {
        return $query->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isShown(): bool
    {
        return $this->enabled
            && ($this->starts_at === null || ! $this->starts_at->isFuture())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function coverUrl(): ?string
    {
        return $this->cover ? Storage::disk('public')->url($this->cover) : null;
    }

    /** The collection's products, as a listing query the catalogue's Listing can page, filter and sort. */
    public function productQuery(): Builder
    {
        return $this->constrain(Product::listed());
    }

    /** The same narrowing onto a query somebody else started (a product block). */
    public function constrain(Builder $query): Builder
    {
        $links = DB::table('m_collection_products')->where('collection_id', $this->id)->get(['product_id', 'excluded']);
        $picked = $links->where('excluded', false)->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        $excluded = $links->where('excluded', true)->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        $hasConditions = $this->hasConditions();

        if (! $hasConditions && $picked === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->where(function (Builder $either) use ($hasConditions, $picked) {
            if ($hasConditions) {
                $either->where(fn (Builder $q) => $this->applyConditions($q));
            }
            if ($picked !== []) {
                $either->orWhereIn('m_catalog_products.id', $picked);
            }
        });

        return $excluded === [] ? $query : $query->whereNotIn('m_catalog_products.id', $excluded);
    }

    public function hasConditions(): bool
    {
        $c = (array) ($this->conditions ?? []);

        return ! empty($c['categories']) || ! empty(array_filter((array) ($c['features'] ?? []))) || ! empty($c['labels'])
            || ! empty($c['flags']) || isset($c['price_min']) || isset($c['price_max']);
    }

    protected function applyConditions(Builder $query): Builder
    {
        $c = (array) ($this->conditions ?? []);

        if ($categories = array_map('intval', (array) ($c['categories'] ?? []))) {
            $ids = app(CategoryTree::class)->descendants($categories);
            $query->whereHas('categories', fn ($q) => $q->whereIn('m_catalog_categories.id', $ids));
        }

        foreach ((array) ($c['features'] ?? []) as $featureId => $valueIds) {
            $valueIds = array_map('intval', (array) $valueIds);
            if ($valueIds !== []) {
                $query->whereHas('featureAssignments', fn ($q) => $q->where('feature_id', (int) $featureId)->whereIn('feature_value_id', $valueIds));
            }
        }

        // Labels: any of them, each as the storefront answers it — the
        // products it was put on by hand, and the ones its rules match
        // ("New" for a product younger than N days, "Hit" by sales). A label
        // switched off or outside its dates matches nothing, as on a card.
        // A "discount" rule exists only on a loaded product (Label::rulesAreSql),
        // so such a label counts its hand-picked products only — the filter's
        // own "on sale" box is the query for that.
        if ($labelIds = array_map('intval', (array) ($c['labels'] ?? []))) {
            $labels = Label::whereIn('id', $labelIds)->get()->filter(fn (Label $l) => $l->isActiveNow());

            $query->where(function (Builder $any) use ($labels) {
                $any->whereRaw('1 = 0');
                foreach ($labels as $label) {
                    $any->orWhereHas('labels', fn ($q) => $q->where('m_catalog_labels.id', $label->id));
                    if ($label->hasRules() && $label->rulesAreSql()) {
                        $any->orWhere(fn (Builder $q) => $label->applyConditions($q));
                    }
                }
            });
        }

        if ($flags = array_values(array_filter((array) ($c['flags'] ?? []), 'is_string'))) {
            app(Flags::class)->applyKeys($query, $flags);
        }

        return FilterPages::applyPriceBounds(
            $query,
            isset($c['price_min']) ? (int) $c['price_min'] : null,
            isset($c['price_max']) ? (int) $c['price_max'] : null,
        );
    }
}
