<?php

namespace Torgash\Catalog\Models;

use App\Core\Content\Placeholders;
use App\Core\Seo\HasPageMeta;
use App\Core\Seo\PageSubject;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Torgash\Catalog\Filters\Flags;
use Torgash\Catalog\Filters\FilterPages;
use Torgash\Catalog\Support\CategoryTree;

/**
 * "Apple laptops" as an address rather than as a query string.
 *
 * The page IS its filter: `values` is the set of characteristic values it
 * stands for, and everything else on the row exists because a published address
 * needs it — a slug, a name to be linked by, and the ordinary SEO columns every
 * other entity in this shop carries.
 *
 * Two rules worth stating here, because both are easy to get subtly wrong:
 *
 * **The values are canonical.** Feature ids and value ids are integers, both
 * lists are sorted, and {@see fingerprintFor()} is the one string that
 * identifies the combination. Postgres does not keep jsonb key order, so the
 * jsonb column can never be compared to another one — every "is this the same
 * set" question goes through the fingerprint.
 *
 * **A visitor may narrow it further.** The address states a set; the sidebar's
 * price, stock and extra characteristics are still query parameters on top of
 * it, and core's canonical drops them — so `/catalog/laptops/apple?price_max=…`
 * canonicalises to `/catalog/laptops/apple`, which is exactly right.
 *
 * **With a `place_id` it is a CITY page** (PLAN §30.9) — "Ноутбуки в Киеве".
 * The same row and the same route, because it is the same sentence about a
 * published address, but the two differ in one way that matters: a filter page
 * holds FEWER products than its category and a city page holds the same ones
 * under different words. So a city page is never the canonical address of a
 * filtered listing (no query string means "in Kyiv", so nothing duplicates it),
 * and its own `values` may be empty, which for a filter page would be
 * meaningless.
 *
 * **A price range is the fourth thing a page can stand for** (PLAN §30.14):
 * `price_min`/`price_max`, minor units, the shop's own books — never the
 * customer's typed currency, or the currency switcher would multiply published
 * addresses. NULL is an open boundary, and canonicalisation is by EXACT
 * boundary: "up to 29 999" is not "up to 30 000", because that is a different,
 * SMALLER set of products, and softening the match would misdirect a crawler
 * the same way an unknown characteristic value would.
 */
class FilterPage extends Model implements PageSubject
{
    use HasPageMeta, HasTranslations;

    public const PAGE_META_KIND = 'filter_page';

    public const GEO_PAGE_META_KIND = 'geo_page';

    protected $table = 'm_catalog_filter_pages';

    protected $guarded = [];

    /** @var Collection<int, FeatureValue>|null memo; see featureValues() */
    protected ?Collection $valueRecords = null;

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'flags' => 'array',
            'name' => 'array',
            'description' => 'array',
            'meta_title' => 'array',
            'meta_description' => 'array',
            'h1' => 'array',
            'noindex' => 'bool',
            'in_sitemap' => 'bool',
            'in_list' => 'bool',
            'enabled' => 'bool',
            'price_min' => 'integer',
            'price_max' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(FilterRule::class, 'rule_id');
    }

    /**
     * Feature id => value ids, with both sides ordered and integer-typed.
     *
     * Everything that writes this column goes through here, and everything that
     * compares two sets compares their fingerprints — see the class docblock.
     *
     * @param  array<int|string, mixed>  $values
     * @return array<int, list<int>>
     */
    public static function canonicalValues(array $values): array
    {
        $canonical = [];

        foreach ($values as $featureId => $valueIds) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $valueIds))));

            if ($ids === [] || ! (int) $featureId) {
                continue;
            }

            sort($ids);
            $canonical[(int) $featureId] = $ids;
        }

        ksort($canonical);

        return $canonical;
    }

    /**
     * The one string that says "this category, these values, this town" — see
     * the class docblock.
     *
     * The town is appended only when there IS one, so every fingerprint written
     * before city pages existed still reads the same. That is not tidiness: the
     * column is UNIQUE, and a changed formula would have needed a migration to
     * rewrite live rows, with a window in which two pages claimed one address.
     */
    public static function fingerprintFor(
        int $categoryId,
        array $values,
        ?int $placeId = null,
        array $flags = [],
        ?int $priceMin = null,
        ?int $priceMax = null,
    ): string {
        $parts = [];

        foreach (self::canonicalValues($values) as $featureId => $ids) {
            $parts[] = $featureId.':'.implode(',', $ids);
        }

        $flags = self::canonicalFlags($flags);

        return $categoryId.'|'.implode(';', $parts)
            .($placeId ? '|@'.$placeId : '')
            .($flags === [] ? '' : '|#'.implode(',', $flags))
            .($priceMin === null && $priceMax === null ? '' : '|$'.$priceMin.','.$priceMax);
    }

    /**
     * The yes/no filters this page stands for — sorted, unique, strings.
     *
     * Sorted for the reason the values are: the fingerprint is the identity of
     * the combination, and "in stock and on sale" may not be a different page
     * from "on sale and in stock".
     *
     * @param  iterable<mixed>  $flags
     * @return list<string>
     */
    public static function canonicalFlags(iterable $flags): array
    {
        $keys = [];

        foreach ($flags as $key) {
            $key = trim((string) $key);

            if ($key !== '') {
                $keys[] = $key;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    public function fingerprint(): string
    {
        return self::fingerprintFor(
            (int) $this->category_id,
            (array) $this->values,
            $this->place_id ? (int) $this->place_id : null,
            $this->flagSet(),
            $this->price_min,
            $this->price_max,
        );
    }

    public function hasPriceRange(): bool
    {
        return $this->price_min !== null || $this->price_max !== null;
    }

    /** Minor units to a bare number — no currency sign, no thousands separator: an address may not move, so nothing locale-specific touches it. */
    public static function bareAmount(int $minor): string
    {
        return rtrim(rtrim(number_format($minor / 100, 2, '.', ''), '0'), '.');
    }

    /** The typed major-unit form back to minor units, or null for blank/unparsable input. */
    public static function minorAmount(mixed $typed): ?int
    {
        $typed = trim((string) $typed);

        return $typed === '' || ! is_numeric($typed) ? null : (int) round((float) $typed * 100);
    }

    /** @return array<int, list<int>> */
    public function valueSet(): array
    {
        return self::canonicalValues((array) $this->values);
    }

    /** @return list<string> */
    public function flagSet(): array
    {
        return self::canonicalFlags((array) ($this->flags ?? []));
    }

    public function isGeo(): bool
    {
        return (int) $this->place_id > 0;
    }

    /**
     * Which kind of page this is, for the SEO templates and the audit screen.
     *
     * Answered from the row rather than from the class, which is the whole
     * reason both live in one table: a shop writes one template for "a city
     * page" and another for "a filter page", and they are not the same
     * sentence — "Ноутбуки в Киеве" against "Ноутбуки Apple".
     */
    public function pageMetaKind(): string
    {
        return $this->isGeo() ? self::GEO_PAGE_META_KIND : self::PAGE_META_KIND;
    }

    /**
     * What else the page's own tokens may ask about — read by the SEO module's
     * Templates::context().
     *
     * The place goes over as an ID and nothing else, which is all this module
     * stores: `{location:6}` is registered by `torgash.locations`, so the
     * catalogue never names that module's classes and the token simply resolves
     * to nothing on a shop with no place registry.
     *
     * @return array<string, mixed>
     */
    public function pageMetaContext(): array
    {
        return $this->isGeo() ? ['place_id' => (int) $this->place_id] : [];
    }

    /** Narrows a product query the same way the sidebar's own checkboxes would. */
    public function applyTo(Builder $query): Builder
    {
        foreach ($this->valueSet() as $featureId => $valueIds) {
            $query->whereHas('featureAssignments', fn ($q) => $q
                ->where('feature_id', $featureId)
                ->whereIn('feature_value_id', $valueIds));
        }

        // ...and the yes/no filters, through the registry that owns them —
        // never a copy of their SQL here, or "on sale" would mean one thing in
        // the panel and another at this address. A key nobody answers for
        // matches NOTHING: the module that knew what it meant is gone, and
        // quietly widening the page into its whole category is the one answer
        // that misleads (§102's rule about an unknown code, applied to a
        // published address).
        $query = app(Flags::class)->applyKeys($query, $this->flagSet());

        // ...and the price range, through the SAME comparison the sidebar's own
        // boxes use (PLAN §30.14) — a page and a panel disagreeing about what
        // "under 30 000" means would be the same drift the flags registry
        // already exists to prevent.
        return FilterPages::applyPriceBounds($query, $this->price_min, $this->price_max);
    }

    /**
     * What this page holds, for nobody in particular.
     *
     * `visible()` rather than `listed()`: this is what the generator counts and
     * what the sitemap decides on, and neither has a visitor whose assortment
     * could narrow it.
     */
    public function productsQuery(): Builder
    {
        return $this->applyTo(
            Product::visible()->whereHas('categories', fn ($q) => $q->whereIn(
                'm_catalog_categories.id',
                app(CategoryTree::class)->descendants((int) $this->category_id),
            ))
        );
    }

    /**
     * The values this page stands for, as records — for the crumb, the name and
     * the admin list.
     *
     * Memoised, and primable from outside ({@see primeValues()}): a category
     * page draws a link per published page and each link asks for its words, so
     * a bare implementation is one query per page in the list.
     */
    public function featureValues(): Collection
    {
        if ($this->valueRecords !== null) {
            return $this->valueRecords;
        }

        $ids = array_merge(...array_values($this->valueSet())) ?: [];

        return $this->valueRecords = $ids === []
            ? collect()
            : FeatureValue::with('feature')->whereIn('id', $ids)->get();
    }

    /**
     * One query for a whole list of pages: every value they mention, handed
     * back to each page. The idiom Measurements::prime() already uses.
     *
     * @param  iterable<int, self>  $pages
     */
    public static function primeValues(iterable $pages): void
    {
        $ids = [];

        foreach ($pages as $page) {
            foreach ($page->valueSet() as $valueIds) {
                $ids = [...$ids, ...$valueIds];
            }
        }

        $values = $ids === []
            ? collect()
            : FeatureValue::with('feature')->whereIn('id', array_unique($ids))->get()->keyBy('id');

        foreach ($pages as $page) {
            $mine = array_merge(...array_values($page->valueSet())) ?: [];

            $page->valueRecords = collect($mine)
                ->map(fn (int $id) => $values->get($id))->filter()->values();
        }
    }

    /**
     * What to call the page: whatever the shop typed, else the values
     * themselves, else the address. Never empty — this is a crumb and a link.
     */
    public function title(?string $locale = null): string
    {
        if ($name = $this->t('name', $locale)) {
            return $name;
        }

        $words = $this->featureValues()->map(fn (FeatureValue $v) => $v->display($locale))->filter();

        // A flag is one of the words the page is named by — "Laptops, in
        // stock" — and it is asked of the registry, so a key whose module has
        // gone reads as itself rather than disappearing from the name of a
        // page that still applies it.
        foreach ($this->flagSet() as $flag) {
            $words = $words->push(app(Flags::class)->label($flag));
        }

        if ($price = $this->priceLabel()) {
            $words = $words->push($price);
        }

        // The town joins the list rather than being wrapped in a phrase: "в
        // Киеве" is a sentence, and its word order is the template's business
        // and each language's own. This is the fallback for a page nobody has
        // written a name for, and it has to read the same in every language.
        if ($town = $this->placeName($locale)) {
            $words = $words->push($town);
        }

        return $words->isNotEmpty() ? $words->implode(', ') : $this->slug;
    }

    /**
     * The town's name in the nominative, or '' — through the placeholder
     * registry, which is where `torgash.locations` publishes it.
     *
     * Asked of the registry rather than of a model this module may not name,
     * and answered with '' on a shop with no place registry installed: the page
     * then reads as whatever else it is about, which is the same answer it gave
     * before city pages existed.
     */
    public function placeName(?string $locale = null): string
    {
        if (! $this->isGeo()) {
            return '';
        }

        $expand = fn () => trim(app(Placeholders::class)->expand(
            '{location:1}',
            $this->pageMetaContext(),
            false,
        ));

        if ($locale === null || $locale === app()->getLocale()) {
            return $expand();
        }

        // IN that language, not merely stored under it: a declined form is a
        // property of the language it is in, so the token has to be expanded
        // with the locale switched — the same rule the generator's name
        // templates live by.
        $was = app()->getLocale();

        try {
            app()->setLocale($locale);

            return $expand();
        } finally {
            app()->setLocale($was);
        }
    }

    /**
     * The price range as a word for the auto-generated title — bare numbers,
     * because a model has no visitor to format money for (the same reason
     * ProductText never calls format_price()). A page whose name a rule or an
     * admin actually TYPED never reaches this; it exists for a hand-made page
     * that only ticked the boxes.
     */
    protected function priceLabel(): string
    {
        if (! $this->hasPriceRange()) {
            return '';
        }

        if ($this->price_min !== null && $this->price_max !== null) {
            return self::bareAmount($this->price_min).'–'.self::bareAmount($this->price_max);
        }

        return $this->price_max !== null
            ? __('up to :amount', ['amount' => self::bareAmount($this->price_max)])
            : __('from :amount', ['amount' => self::bareAmount((int) $this->price_min)]);
    }

    public function path(): string
    {
        return '/catalog/'.$this->category->slug.'/'.$this->slug;
    }

    public function url(): string
    {
        return route('catalog.filter', [$this->category->slug, $this->slug]);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }
}
