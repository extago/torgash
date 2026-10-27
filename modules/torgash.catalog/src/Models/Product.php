<?php

namespace Torgash\Catalog\Models;

use App\Core\Seo\HasPageMeta;
use App\Core\Seo\PageSubject;
use App\Core\Support\HasTranslations;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Torgash\Catalog\Support\KeepsAddress;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Torgash\Catalog\Stock\AvailabilityStatuses;
use Torgash\Catalog\Support\Measurements;
use Torgash\Catalog\Support\PriceTypes;
use Torgash\Media\Gallery;

class Product extends Model implements HasMedia, PageSubject
{
    use HasPageMeta, HasTranslations, InteractsWithMedia, KeepsAddress, SoftDeletes;

    public function addressPath(string $slug): string
    {
        return '/product/'.$slug;
    }

    /** What an SEO template calls this kind of record. */
    public const PAGE_META_KIND = 'product';

    protected $table = 'm_catalog_products';

    protected $guarded = [];

    /**
     * Mirrors the column's own DB default — Eloquent does not read a
     * Postgres-applied DEFAULT back into memory after INSERT (it only asks
     * `RETURNING id`), so a `Product::create([...])` that omits this field
     * would otherwise leave the in-memory model's `availability_status` NULL
     * until something calls `fresh()`/`refresh()`. `isOrderable()` treats an
     * unregistered status as "fails closed" (§102's rule, extended) — which
     * is correct for a status a MODULE stopped answering for, and wrong for a
     * value that is only missing because nobody re-read the row yet. This is
     * what keeps the two apart.
     */
    protected $attributes = [
        'availability_status' => 'in_stock',
        'needs_delivery' => true,
    ];

    /** Never typed, never kept: the SKU of a row whose id is not known yet. */
    protected const SKU_PENDING = 'PENDING#';

    /**
     * A product always has a SKU, and it may be left BLANK by whoever creates
     * it — the admin form, an import: the model fills it in, the same
     * rule as a characteristic's code (catalog.md, "a code is assigned by the
     * MODEL"). Blank becomes a placeholder on INSERT and, once the id is known,
     * whatever `catalog.product.sku` answers (a module's template — torgash.sku),
     * else `P-<id>`. The filter is asked for EVERY new product, with `typed`,
     * so a rule that replaces even a typed SKU can say so; an answer that is
     * somebody else's SKU is ignored. Written quietly: the row is still being
     * created, and the audit and the search see the final value.
     */
    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (trim((string) $product->sku) === '') {
                $product->sku = self::SKU_PENDING.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(12));
            }
        });

        static::created(function (Product $product) {
            $typed = ! str_starts_with((string) $product->sku, self::SKU_PENDING);
            $fallback = $typed ? (string) $product->sku : self::freeSku('P-'.$product->id, $product->id);
            $answer = trim((string) hook_filter('catalog.product.sku', $fallback, ['product' => $product, 'typed' => $typed]));
            $sku = $answer !== '' && $answer !== $fallback && ! self::skuTaken($answer, $product->id) ? $answer : $fallback;

            if ($sku !== $product->sku) {
                $product->sku = $sku;
                $product->saveQuietly();
            }
        });
    }

    /** Is the SKU somebody else's (a product in the bin included — the column is unique)? */
    public static function skuTaken(string $sku, ?int $except = null): bool
    {
        return static::withTrashed()->where('sku', $sku)->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
    }

    /** $sku, or $sku-2, -3… — the first nobody has. */
    protected static function freeSku(string $sku, ?int $except = null): string
    {
        $candidate = $sku;

        for ($n = 2; static::skuTaken($candidate, $except); $n++) {
            $candidate = $sku.'-'.$n;
        }

        return $candidate;
    }

    /** Is this the SKU the model fell back to (`P-<id>`), i.e. nobody chose one? */
    public function hasFallbackSku(): bool
    {
        return preg_match('/^P-'.$this->getKey().'(-\d+)?$/', (string) $this->sku) === 1;
    }

    /**
     * Postgres GENERATED ALWAYS columns — readable, never writable. Anything
     * that copies a whole attribute bag (replicate) has to drop these or the
     * insert is rejected outright.
     */
    public const GENERATED_COLUMNS = ['search_vector'];

    /**
     * Columns DERIVED from another table, which a copy must not inherit: a
     * duplicate has no price history, so it has no reference price either.
     */
    public const DERIVED_COLUMNS = ['prior_lowest_price'];

    /** @see the add_status_to_products migration for what each state means. */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUS_DISABLED = 'disabled';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_HIDDEN, self::STATUS_DISABLED];

    /** A change to it can be put back from the audit trail (Torgash\Guard\Audit\Revert): it is content, saved by its own model. */
    public static function auditRevertible(): bool
    {
        return true;
    }

    /** Short admin-facing label per status — the badge text on the list screen. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => __('Active'),
            self::STATUS_HIDDEN => __('Hidden'),
            default => __('Disabled'),
        };
    }

    public function registerMediaCollections(): void
    {
        // Ordered by media_library's own "order_column" (set on upload/reorder).
        $this->addMediaCollection('gallery');
    }

    /** Thumbnail sizes and formats are the Media module's business, not the catalog's. */
    public function registerMediaConversions(?Media $media = null): void
    {
        Gallery::conversions($this, $media);
    }

    /**
     * First gallery photo, or the legacy image_url, or null (template shows a
     * placeholder).
     *
     * $minWidth is the width the layout actually needs — 120 for a cart line,
     * 400 for a catalogue card, 800 for the product page — and the smallest
     * generated thumbnail at least that wide is returned. Callers MUST pass it:
     * the default of 0 means "the original", which on a category page is a
     * 200 KB JPEG standing in for a 5 KB WebP. That was the bug — the whole
     * thumbnail pipeline existed, generated its files, and nothing asked for
     * them.
     */
    public function primaryImageUrl(int $minWidth = 0): ?string
    {
        $media = $this->getFirstMedia('gallery');

        if ($media) {
            return $minWidth > 0 ? Gallery::thumbUrl($media, $minWidth) : $media->getUrl();
        }

        return $this->externalImageUrls()[0] ?? null;
    }

    /**
     * Pictures on another site, by address — shown only while the shop allows
     * it (`external_images`, off by default) and only where the product has no
     * photos of its own. Stored in `image_url` as the admin typed them, one or
     * more, separated by commas or new lines; only absolute https addresses
     * (and the shop's own paths, `/…`) are read — an http picture on an https
     * page is blocked by the browser anyway.
     *
     * @return list<string>
     */
    public function externalImageUrls(): array
    {
        if (! setting('torgash.catalog', 'external_images', false)) {
            return [];
        }

        return self::parseImageUrls((string) $this->image_url);
    }

    /** @return list<string> */
    public static function parseImageUrls(string $value): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[\s,]+/', $value) ?: []),
            fn (string $url) => str_starts_with($url, 'https://') || (str_starts_with($url, '/') && ! str_starts_with($url, '//')),
        )));
    }

    /**
     * The one place stock ever changes — everything that used to call
     * increment()/decrement('stock', ...) directly (checkout's order-status
     * handler) goes through this instead.
     *
     * A warehouses module, if installed, may intercept catalog.product.stock_adjust
     * for a product whose stock is split across warehouses: it does the real
     * per-warehouse bookkeeping itself and returns {warehouse_id: appliedDelta},
     * which the caller can persist (e.g. onto the order line) for an exact
     * reversal later via $context['restore']. With no listener, or for a
     * product that isn't split, this is a plain atomic column update — exactly
     * what ran here before that hook existed.
     *
     * @param  array<string,mixed>  $context  Passed through to the filter untouched —
     *                                        e.g. checkout sends ['restore' => $previousBreakdown] to reverse
     *                                        a specific order line precisely rather than re-running allocation.
     * @return array<int,int>|null warehouse_id => applied delta, if a listener
     *                             handled it; null when the plain column path ran.
     */
    public function adjustStock(int $delta, array $context = []): ?array
    {
        $statuses = app(AvailabilityStatuses::class);

        // Untracked (PLAN §31.3, e.g. a digital good or a preorder's own
        // shelf) — the number is nobody's business here, so an order-driven
        // delta simply does not move it. A direct edit on the product form
        // still can: that goes through a plain column write, never this
        // funnel, exactly like an admin's manual stock edit always has.
        if (! $statuses->tracksStock((string) $this->availability_status)) {
            return null;
        }

        $breakdown = hook_filter('catalog.product.stock_adjust', null, ['product' => $this, 'delta' => $delta] + $context);

        if ($breakdown === null) {
            // Announced here, with the cause the caller put in force, rather
            // than by the model's own save listener, which would call it an
            // edit (PLAN §42).
            $moves = app(\Torgash\Catalog\Stock\StockMoves::class);
            $moves->quietly(fn () => $delta >= 0 ? $this->increment('stock', $delta) : $this->decrement('stock', -$delta));
            $moves->record($this->id, null, $delta, (int) $this->stock);
        } else {
            // A listener already wrote the new aggregate straight to the DB.
            $this->refresh();
        }

        $this->applyZeroTransition();

        return $breakdown;
    }

    /**
     * The one automatic status transition in the whole system (PLAN §31.3):
     * reaching zero is the one event this code actually sees for itself. Only
     * for a status that refuses negative numbers — a preorder crossing further
     * into the negative is doing exactly what it is for, not running out of
     * anything.
     *
     * Extracted from adjustStock() so that {@see syncStockTo()} runs it too: a
     * delivery booked into a warehouse and a line sold off one are the same
     * number moving, and until now only one of the two paths could flip the
     * status.
     */
    public function applyZeroTransition(): void
    {
        $statuses = app(AvailabilityStatuses::class);
        $def = $statuses->find((string) $this->availability_status);

        if ($def && ! $def['allow_negative'] && $this->stock <= 0
            && ($target = $statuses->onZeroTarget()) && $target !== $this->availability_status) {
            $from = $this->availability_status;
            $this->forceFill(['availability_status' => $target])->save();

            hook_action('catalog.product.availability_changed', [
                'product' => $this, 'from' => $from, 'to' => $target, 'reason' => 'zero_stock',
            ]);
        }
    }

    /**
     * Write the stock aggregate a warehouses module has just recomputed — and
     * say so if the product has come BACK.
     *
     * The warehouses module wrote this column with a raw update, which is right
     * (the number is a sum of its own rows, not an edit of the product) and is
     * also why nothing downstream ever heard about it: a raw update fires no
     * model event, so a delivery arriving — the one moment somebody is waiting
     * to be told about — was the single stock change the shop could not react
     * to. One call instead of that update keeps the rule here, in the module
     * that knows what "orderable" means, rather than teaching it to every
     * module that can move a number.
     *
     * Measured as a CROSSING, never as "stock > 0": a product that was already
     * orderable (a preorder, an untracked one) has not come back from
     * anywhere, and a shop restocking a hundred lines at once must not
     * announce a hundred returns that never happened.
     */
    public static function syncStockTo(int $productId, int $sum): void
    {
        $statuses = app(AvailabilityStatuses::class);

        // Two columns, not a hydrated model: this runs per product line of an
        // allocation, and the model is only needed if there is news.
        $before = DB::table('m_catalog_products')->where('id', $productId)
            ->first(['availability_status', 'stock']);

        DB::table('m_catalog_products')->where('id', $productId)->update(['stock' => $sum]);

        if (! $before) {
            return;
        }

        $was = $statuses->isOrderableFor((string) $before->availability_status, (int) $before->stock);
        $now = $statuses->isOrderableFor((string) $before->availability_status, $sum);

        if ($was === $now) {
            return;
        }

        if (! $product = static::find($productId)) {
            return;
        }

        if ($now) {
            static::announceRestock($product);

            return;
        }

        // Down to nothing through this path: the same automatic transition the
        // other one has always run.
        $product->applyZeroTransition();
    }

    /**
     * "This product can be bought again" — announced once, by whichever path
     * moved the number or the status.
     *
     * Anyone can listen; `torgash.enquiries` does, because "tell me when it is
     * back" is a promise the shop made on the product page and nothing was
     * keeping it.
     */
    public static function announceRestock(self $product): void
    {
        hook_action('catalog.product.restocked', ['product' => $product]);
    }

    /**
     * Can this product be put in a cart right now — see
     * {@see AvailabilityStatuses::isOrderable()} for what decides it.
     */
    public function isOrderable(): bool
    {
        return app(AvailabilityStatuses::class)->isOrderable($this);
    }

    /**
     * How many a customer may buy right now — null when the number does not
     * limit it, 0 when it cannot be bought at all. A DISABLED card is not for
     * sale whatever its availability says; a hidden one still is, by its own
     * link ({@see scopeReachable()}). See {@see AvailabilityStatuses::orderLimit()}.
     */
    public function orderLimit(): ?int
    {
        if ($this->status === self::STATUS_DISABLED) {
            return 0;
        }

        return app(AvailabilityStatuses::class)->orderLimit($this);
    }

    /** @return Collection<int, Media> */
    public function galleryImages(): Collection
    {
        return $this->getMedia('gallery');
    }

    protected function casts(): array
    {
        return [
            'customer_groups' => 'array',
            'name' => 'array',
            'description' => 'array',
            'short_description' => 'array',
            'meta_title' => 'array',
            'meta_description' => 'array',
            'h1' => 'array',
            'noindex' => 'bool',
            'features' => 'array',
            'features_layout' => 'array',
            'features_translations' => 'array',
            'price' => 'int',
            'cost_price' => 'int',
            'list_price' => 'int',
            // The typed amounts behind them, in `price_currency`. NULL means
            // "priced in the shop's own currency", which is most catalogues.
            'price_original' => 'int',
            'cost_price_original' => 'int',
            'list_price_original' => 'int',
            'views_count' => 'int',
            'sales_count' => 'int',
            'popularity' => 'int',
            // Derived by Pricing\PriceHistory; never typed, never copied.
            'prior_lowest_price' => 'int',
            // Grams and millimetres, nullable: null is "never measured", which
            // is not the same answer as zero — see the dimensions migration.
            'weight' => 'int',
            'needs_delivery' => 'bool',
            'length' => 'int',
            'width' => 'int',
            'height' => 'int',
        ];
    }

    /**
     * Weight in grams, or null when nobody has measured this product.
     *
     * Not just the column: a catalogue of any size arrives with the weight
     * sitting in a spec rather than in the field, so the shop can name that
     * spec once (catalog settings) and every price band, carrier request and
     * "can this even be shipped" question reads it from there. The chain is
     * column → named spec → null; a category default and a per-method default
     * parcel come later (PLAN §12.5).
     */
    /**
     * Is this sent anywhere (PLAN §40.10/2)? The product's own answer, and a
     * module's: a gift card is never delivered whatever its column says
     * (`catalog.product.needs_delivery`). A basket of products that are not is
     * placed with no address and no delivery method.
     */
    public function needsDelivery(): bool
    {
        return (bool) ($this->needs_delivery ?? true)
            && (bool) hook_filter('catalog.product.needs_delivery', true, ['product' => $this]);
    }

    public function resolvedWeight(): ?int
    {
        return app(Measurements::class)->weightOf($this);
    }

    /**
     * Length, width, height in millimetres, or null unless all three are known:
     * two sides of a box are not a box, and volumetric weight computed from a
     * missing side is a wrong number rather than a missing one.
     *
     * @return array{length: int, width: int, height: int}|null
     */
    public function resolvedBox(): ?array
    {
        if ($this->length === null || $this->width === null || $this->height === null) {
            return null;
        }

        return ['length' => $this->length, 'width' => $this->width, 'height' => $this->height];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'm_catalog_category_product', 'product_id', 'category_id');
    }

    /** Manually-assigned labels only — see Label::forProducts() for manual ∪ rule-matched. */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'm_catalog_label_product');
    }

    /** Directional — "goes with this" curated by the admin, not implied both ways. */
    public function relatedProducts(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'm_catalog_related_products', 'product_id', 'related_product_id')
            ->withPivot('sort')
            ->orderByPivot('sort');
    }

    /** Assigned values from the global, managed feature catalog (see FeatureCatalog migration). */
    public function featureAssignments(): HasMany
    {
        return $this->hasMany(ProductFeature::class);
    }

    /**
     * The product's brand: the value of the characteristic whose purpose is
     * `brand` — the one with a page, a logo and a description of its own.
     * Read from the loaded assignments, so a page that already has them asks
     * nothing more.
     */
    public function brandValue(): ?FeatureValue
    {
        $this->loadMissing('featureAssignments.feature', 'featureAssignments.featureValue');

        return $this->featureAssignments
            ->first(fn (ProductFeature $pf) => $pf->feature?->purpose === 'brand' && $pf->featureValue)
            ?->featureValue;
    }

    /**
     * Whether the description really says something.
     *
     * One answer, because two places ask: the page, deciding whether to draw
     * that section at all, and the `product_content` display rule, deciding
     * whether to show what stands in for it. `<p></p>` — what a rich-text
     * editor leaves behind when the text is deleted — is not a description;
     * a bare picture or an embedded video is.
     */
    public function hasDescription(): bool
    {
        return trim(strip_tags((string) $this->t('description'), '<img><iframe><video><table>')) !== '';
    }

    /** Tabs written for THIS product alone — the shop-wide ones have no product_id. */
    public function tabs(): HasMany
    {
        return $this->hasMany(ProductTab::class)->orderBy('sort')->orderBy('id');
    }

    /** Wholesale/quantity-break rows, if any — see effectivePrice() and the migration's docblock. */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(ProductPriceTier::class);
    }

    /** Per-SKU exceptions to a price type's flat adjustment_percent — see priceForType(). */
    public function priceTypeOverrides(): HasMany
    {
        return $this->hasMany(ProductPriceTypeOverride::class);
    }

    /**
     * Listed: catalogue grids, search, related blocks, sitemap, feeds. Every
     * existing caller of visible() meant exactly this, so they keep working
     * unchanged — "hidden" simply drops out of all of them at once.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * A storefront LISTING: visible, and narrowed by whatever a module says
     * this particular visitor may be shown — today, the assortment of the
     * pickup point they chose, when the shop is set up to hide the rest.
     *
     * Separate from visible() rather than folded into it because the narrowing
     * depends on the SESSION, and visible() also answers questions that have no
     * visitor: the sitemap, and an export feed run from the admin. A feed that
     * quietly shrank because whoever pressed the button had chosen a shop is
     * exactly the bug this split prevents.
     *
     * The product page uses reachable(), so a link from search or a newsletter
     * still opens: hiding is about what a shop puts in front of a browsing
     * customer, not about making URLs 404.
     */
    public function scopeListed(Builder $query): Builder
    {
        // A closed assortment (PLAN §46) is the catalogue's own narrowing:
        // what this visitor's group may not see is not listed to them.
        return hook_filter('catalog.products.listed_query', app(\Torgash\Catalog\Support\Assortment::class)->narrow($query->visible()));
    }

    /**
     * The answers with no visitor (the sitemap, a public feed) are a GUEST's:
     * a product closed to guests — by its own list or its category's — is not
     * published to the whole internet (PLAN §46).
     */
    public function scopeOpenToGuests(Builder $query): Builder
    {
        return \Torgash\Catalog\Support\Assortment::asGuest()->narrow($query);
    }

    /**
     * Reachable by direct URL — active OR hidden. Only the product page itself
     * uses this; that difference is the entire point of the hidden state.
     */
    public function scopeReachable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_HIDDEN]);
    }

    /**
     * Everything partials/product-grid.blade.php touches per card: the gallery
     * media row (primaryImageUrl) and both price relations (effectivePrice).
     * Without it a grid of N cards costs 2N extra queries — lazy loading fires
     * once per product inside the @foreach, and nothing in the view makes that
     * visible. Every storefront listing must go through this scope; a query
     * that only plucks ids does not need it (no models are hydrated).
     */
    public function scopeWithCardData(Builder $query): Builder
    {
        return $query->with(['media', 'priceTiers', 'priceTypeOverrides']);
    }

    /**
     * Every enabled, non-base price type visible to $user — read once per
     * request (not per call) so a listing grid of N products doesn't run N
     * PriceType queries just to find out most rows have nothing extra to offer.
     * The memo itself lives in the PriceTypes singleton; see its docblock for
     * why it is not a static on this model.
     */
    public static function visiblePriceTypesFor(?User $user): Collection
    {
        return app(PriceTypes::class)->visibleTo($user);
    }

    /**
     * The individual attributes as they should be READ: in the order the admin
     * put them in, under the sections they put them under.
     *
     * One flat list, each row saying what it is — the same shape the editor
     * shows, because the editor is where the order comes from. Three rules,
     * each protecting against a way the two columns can drift apart:
     *
     *  - a key the layout does not mention is appended at the end, so an
     *    import (or the JSON tab) adding a spec can never hide it;
     *  - a layout row whose key is gone from `features` is skipped, so
     *    deleting a spec does not leave a blank line;
     *  - a section with nothing under it is dropped, so deleting the last spec
     *    of a section does not leave a heading over nothing.
     *
     * The third rule is for READING. The EDITOR passes `$keepEmptySections`,
     * because there a section with nothing under it is not a mistake — it is a
     * section the admin has just added and is about to drag rows into, and
     * dropping it on the way back from the server looks exactly like "it did
     * not save".
     *
     * Rows come in the page's language (`features_translations`): a feature
     * row keeps `key` — the base word, its identity — and adds `label`, the
     * key as the reader sees it; `value` and a section's `label` are
     * translated too, the base words standing in wherever nothing is. The
     * EDITOR asks with `$translated = false`: it edits the base words. A
     * reader with no page — a feed — names its language in `$locale`.
     *
     * @param  list<string>|null  $only  restrict to these keys (the product page
     *                                   excludes the ones being used as variation axes)
     * @return list<array{type: string, label?: string, key?: string, value?: mixed}>
     */
    public function featureRows(?array $only = null, bool $keepEmptySections = false, bool $translated = true, ?string $locale = null): array
    {
        $rows = $this->baseFeatureRows($only, $keepEmptySections);

        $words = $translated ? (array) (($this->features_translations ?? [])[$locale ?? app()->getLocale()] ?? []) : [];

        return array_map(function (array $row) use ($words, $translated) {
            if ($row['type'] === 'section') {
                $row['label'] = $this->translatedWord($words['s'] ?? [], $row['label']);

                return $row;
            }

            $row['label'] = $translated ? $this->translatedWord($words['k'] ?? [], $row['key']) : $row['key'];

            if (is_scalar($row['value'])) {
                $row['value'] = $this->translatedWord($words['v'] ?? [], $row['key'], (string) $row['value']);
            }

            return $row;
        }, $rows);
    }

    /** A translated word if there is one, else the base word (`$fallback`, else the key itself). */
    protected function translatedWord(array $map, string $key, ?string $fallback = null): string
    {
        $word = trim((string) ($map[$key] ?? ''));

        return $word !== '' ? $word : ($fallback ?? $key);
    }

    /** @return list<array{type: string, label?: string, key?: string, value?: mixed}> */
    protected function baseFeatureRows(?array $only, bool $keepEmptySections): array
    {
        $features = $this->features ?? [];

        if ($only !== null) {
            $features = array_intersect_key($features, array_flip($only));
        }

        $rows = [];
        $placed = [];

        foreach ((array) ($this->features_layout ?? []) as $row) {
            $type = $row['type'] ?? 'feature';

            if ($type === 'section') {
                if (($row['label'] ?? '') !== '') {
                    $rows[] = ['type' => 'section', 'label' => (string) $row['label']];
                }

                continue;
            }

            $key = (string) ($row['key'] ?? '');

            if ($key !== '' && array_key_exists($key, $features)) {
                $rows[] = ['type' => 'feature', 'key' => $key, 'value' => $features[$key]];
                $placed[$key] = true;
            }
        }

        foreach ($features as $key => $value) {
            if (! isset($placed[$key])) {
                $rows[] = ['type' => 'feature', 'key' => (string) $key, 'value' => $value];
            }
        }

        return $keepEmptySections ? $rows : $this->withoutEmptySections($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function withoutEmptySections(array $rows): array
    {
        $kept = [];

        foreach ($rows as $i => $row) {
            if ($row['type'] !== 'section') {
                $kept[] = $row;

                continue;
            }

            // Keep the heading only if a feature follows it before the next one.
            for ($j = $i + 1; $j < count($rows); $j++) {
                if ($rows[$j]['type'] === 'section') {
                    break;
                }

                $kept[] = $row;

                break;
            }
        }

        return $kept;
    }

    /**
     * The currency this product's prices were TYPED in, which is not
     * necessarily the one they are stored in.
     *
     * A product agreed with its supplier in hryvnia keeps a hryvnia number
     * (`price_original`); the books column beside it is derived and moves with
     * the rate. Everything downstream reads the books column and never asks
     * this question — it exists for the admin screens, which have to show the
     * number the shop actually decided on.
     */
    public function pricedIn(): string
    {
        return $this->price_currency ?: shop_currency();
    }

    /** The typed amount of one price field, in `pricedIn()` minor units. */
    public function typedPrice(string $field = 'price'): ?int
    {
        $original = $this->{$field.'_original'};

        return $original === null ? $this->{$field} : (int) $original;
    }

    /**
     * A price as an ADMIN reads it: in whatever currency they picked, and in
     * the product's own when they picked none — see admin_price().
     */
    public function adminPrice(string $field = 'price'): string
    {
        return admin_price((int) $this->typedPrice($field), $this->pricedIn());
    }

    /**
     * List price minus the viewing customer's group discount, or a cheaper
     * wholesale/quantity-break row if one applies, or a cheaper *named price
     * type* the visitor is eligible for (e.g. a Wholesale-group customer
     * gets that type's own markup-rule/adjustment price automatically,
     * without needing an export or the club-price badge) — whichever of all
     * of these is lowest, same "cheapest matching price always wins" rule
     * older engines use. Defaults to the current
     * visitor, qty 1.
     *
     * A product with no priceTiers rows and no visible non-base price types
     * costs exactly what it did before this method grew a $qty parameter —
     * the group-discount price is always a candidate, everything else only
     * ever adds a cheaper option on top.
     */
    public function effectivePrice(?User $user = null, int $qty = 1): int
    {
        return $this->cheapestPriceCandidate($user ?? auth()->user(), $qty)['price'];
    }

    /**
     * The price for NOBODY in particular — what an anonymous visitor is shown.
     *
     * For the answers that have no viewer: an export feed, a price list, a
     * marketplace file. Exactly the reason `visible()` exists beside
     * `listed()`, and for the same failure: those run from the admin, where
     * `auth()->user()` is a real account with a customer group and possibly a
     * catalog promotion aimed at it — so `effectivePrice()` there published the
     * price of whoever pressed Export (measured: a manager in a 50% wholesale
     * group exported the catalogue at half price).
     *
     * A sale for everybody still reaches it, and should: a promotion with no
     * condition about the visitor IS what the product costs today.
     */
    public function publicPrice(int $qty = 1): int
    {
        return $this->cheapestPriceCandidate(null, $qty)['price'];
    }

    /**
     * Which candidate produced effectivePrice() — a customer group's name,
     * a price type's name, or null (plain list price, nothing special
     * applied). Storefront-caption use only; not on the hot path anywhere else.
     */
    public function effectivePriceLabel(?User $user = null, int $qty = 1): ?string
    {
        return $this->cheapestPriceCandidate($user ?? auth()->user(), $qty)['label'];
    }

    /**
     * NULL here means "no visitor at all", not "work out who is asking" — the
     * fallback to the logged-in user belongs to the callers that HAVE a
     * visitor, so that publicPrice() can honestly say it has none.
     *
     * @return array{price: int, label: ?string}
     */
    protected function cheapestPriceCandidate(?User $user, int $qty): array
    {
        $percent = $user?->customerGroup?->discount_percent ?? 0;
        $price = $percent > 0 ? round_money($this->price * (100 - $percent) / 100) : $this->price;

        $groupId = $user?->customer_group_id;
        $tierPrice = $this->priceTiers
            ->filter(fn (ProductPriceTier $tier) => $tier->min_qty <= $qty
                && ($tier->customer_group_id === null || $tier->customer_group_id === $groupId))
            ->min('price');

        $best = [
            'price' => $tierPrice !== null ? min($price, $tierPrice) : $price,
            'label' => $percent > 0 ? $user?->customerGroup?->t('name') : null,
        ];

        foreach (static::visiblePriceTypesFor($user) as $type) {
            $candidate = $this->priceForType($type, $user, $qty);
            if ($candidate < $best['price']) {
                $best = ['price' => $candidate, 'label' => $type->t('name')];
            }
        }

        // Two distinct extension points for a module (torgash.promotions) that
        // must not be known to catalog. `price_candidates` are ALTERNATIVES —
        // mutually exclusive options competing on "cheapest wins", the same
        // rule a wholesale tier or a price type plays by. `price_adjust` runs
        // AFTER that winner is settled and LAYERS on top of it — a promotion
        // that stacks (e.g. "10% off, on top of whatever else already won")
        // is not a candidate, because it has no price of its own until the
        // candidate stage has already produced one to adjust.
        foreach (hook_filter('catalog.product.price_candidates', [], ['product' => $this, 'user' => $user, 'qty' => $qty]) as $candidate) {
            if ((int) ($candidate['price'] ?? PHP_INT_MAX) < $best['price']) {
                $best = ['price' => (int) $candidate['price'], 'label' => $candidate['label'] ?? null];
            }
        }

        return hook_filter('catalog.product.price_adjust', $best, ['product' => $this, 'user' => $user, 'qty' => $qty]);
    }

    /**
     * What a discount is "off of". By default whichever is higher — RRP
     * (list_price), if set, or the plain list price.
     */
    public function oldPrice(): int
    {
        // The shop's choice (PLAN §36) lives here and nowhere else, because
        // every struck-through number reads this: card, page, "−X%" labels,
        // wishlist, comparison. `lowest` is the Omnibus rule — the lowest
        // PUBLIC price of the window before the current price began; with no
        // history behind it, today's public price, which makes the discount
        // 0: a reference the shop cannot show is not claimed.
        if (setting('torgash.catalog', 'old_price_source') === 'lowest') {
            return $this->prior_lowest_price ?? $this->publicPrice();
        }

        return max($this->list_price ?? 0, $this->price);
    }

    /**
     * Rounded percent off oldPrice() — 0 when nothing is actually cheaper.
     * Single source of truth for every "−X%" badge (product page, grid
     * cards, and dynamic label text via Label::displayText()) instead of
     * each place re-deriving it slightly differently.
     */
    public function discountPercent(?User $user = null, int $qty = 1): int
    {
        $old = $this->oldPrice();
        $effective = $this->effectivePrice($user, $qty);

        return $old > 0 && $effective < $old ? (int) round((1 - $effective / $old) * 100) : 0;
    }

    /**
     * What this product's availability actually IS right now — not always the
     * same as the stored `availability_status` column: a plain `in_stock` row
     * with nothing left reads as `out_of_stock`, and (with the setting on) one
     * running low reads as `low_stock`, because that nuance is what every shop
     * already had before statuses existed. Every other status is exactly what
     * is stored — a preorder reads as a preorder whatever the number says.
     *
     * This, not the raw column, is what a badge's word AND its `torgash-
     * availability-{key}` CSS hook both have to agree on: a hook that said
     * "in_stock" on a product visibly reading "Out of stock" would be useless
     * for the one thing it exists for — styling the state a shop can actually
     * see (found live: a depleted product still carried the `in_stock` class).
     */
    public function effectiveAvailabilityKey(): string
    {
        if ($this->availability_status !== 'in_stock') {
            return (string) $this->availability_status;
        }

        if ($this->stock <= 0) {
            return 'out_of_stock';
        }

        if (setting('torgash.catalog', 'stock_level_labels')
            && $this->stock <= (int) setting('torgash.catalog', 'stock_low_threshold', 10)) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    /** The shop's own word for {@see effectiveAvailabilityKey()} — `low_stock` is the one key that is never a registered status. */
    public function stockLabel(): string
    {
        $key = $this->effectiveAvailabilityKey();

        return $key === 'low_stock' ? __('Low stock') : app(AvailabilityStatuses::class)->label($key);
    }

    /**
     * Named-price-type resolution, separate from effectivePrice() on
     * purpose: an export pinned to "wholesale" wants that type's price for
     * every row regardless of who's viewing, whereas effectivePrice() is
     * "what does *this* visitor pay right now". The base type delegates
     * straight to effectivePrice() so storefront behavior is unchanged by
     * this method's mere existence.
     */
    public function priceForType(PriceType $type, ?User $user = null, int $qty = 1): int
    {
        if ($type->is_base) {
            return $this->effectivePrice($user, $qty);
        }

        if ($override = $this->priceTypeOverrides->firstWhere('price_type_id', $type->id)) {
            return $override->price;
        }

        if ($this->cost_price && ($rule = $type->matchingRuleFor($this))) {
            $price = $rule->apply($this->cost_price);
        } else {
            $price = $type->adjustment_percent
                ? round_money($this->price * (100 + $type->adjustment_percent) / 100)
                : $this->price;
        }

        $groupId = $user?->customer_group_id;
        $tierPrice = $this->priceTiers
            ->filter(fn (ProductPriceTier $tier) => $tier->price_type_id === $type->id
                && $tier->min_qty <= $qty
                && ($tier->customer_group_id === null || $tier->customer_group_id === $groupId))
            ->min('price');

        return $tierPrice !== null ? min($price, $tierPrice) : $price;
    }

    /**
     * The products of this one's variation group that the storefront offers —
     * see Support\Variations, which also decides the axes and the duplicates.
     */
    public function variationMembers(): Collection
    {
        return app(\Torgash\Catalog\Support\Variations::class)->for($this)['offered'];
    }
}
