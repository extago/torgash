<?php

namespace Torgash\Catalog\Filters;

use App\Core\Content\Placeholders;
use Illuminate\Support\Str;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\FilterRule;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;

/**
 * Turning rules into pages.
 *
 * The one rule that shapes everything else here: **it never deletes.** A
 * generated page is a published address the moment it is crawled, and a run
 * that quietly withdrew one because a supplier's stock moved would 404 a page
 * search engines already hold — the worst of the three possible answers, and
 * the hardest to notice. So a run ADDS what is missing, refreshes what it
 * already made, and takes a page that has fallen below its rule's minimum OUT
 * OF THE SITEMAP rather than out of existence. Removing a page for good is the
 * admin's deliberate act, and that one leaves a 301 behind it.
 *
 * Same shape as Torgash\Blocks\ThemeDefaults, and for the same reason: pressing
 * the button twice must do nothing the first press did not.
 *
 * `noindex` is never written here. It is the shop's own switch, and a run that
 * flipped it back would argue with the admin once a night.
 *
 * A rule's axis is a characteristic's values, a list of towns (PLAN §30.9), or
 * a list of price brackets (PLAN §30.14), and everything below treats the
 * three as one thing with one difference each: a town does not narrow the
 * products (a city page is measured by its category, not by what it filters),
 * and a price bracket narrows by comparison rather than by equality.
 */
class Generator
{
    /** @var array<string, true> slugs already taken, by "category_id/slug" */
    protected array $taken = [];

    /** @var array<int, true> Pages a rule counted in this run. */
    protected array $counted = [];

    /**
     * @param  callable(FilterRule, Category): void|null  $tick  called once per category examined
     * @return array{created: int, refreshed: int, withdrawn: int, skipped: int}
     */
    public function run(?FilterRule $only = null, ?callable $tick = null): array
    {
        $counts = ['created' => 0, 'refreshed' => 0, 'withdrawn' => 0, 'skipped' => 0];

        $rules = $only
            ? collect([$only])
            : FilterRule::where('enabled', true)->orderBy('position')->orderBy('id')->get();

        foreach ($rules as $rule) {
            $rule->loadMissing('feature.values');

            // What this rule makes a page PER: a characteristic's values, a
            // town, or a price bracket. One axis, and the rule itself says
            // which — see FilterRule::axis(). A rule with none makes nothing,
            // which is what a half-filled form leaves behind.
            $axis = match ($rule->axis()) {
                FilterRule::AXIS_FEATURE => $rule->feature ? $rule->feature->values->all() : [],
                FilterRule::AXIS_PRICE => $rule->priceBounds(),
                default => $rule->placeIds(),
            };

            if ($axis === []) {
                continue;
            }

            $categories = Category::listed()->whereIn('id', $rule->categoryIds())->get();

            foreach ($categories as $category) {
                foreach ($axis as $index => $item) {
                    $this->one($rule, $category, $item, $counts, $index);
                }

                $tick && $tick($rule, $category);
            }
        }

        // A full run also recounts what no rule reached — a page written by
        // hand, or one whose rule has been switched off. Otherwise its number
        // is whatever it was on the day it was made, and the admin list warns
        // "no products" about a page that is full of them.
        if ($only === null) {
            $this->recountTheRest($counts);
        }

        return $counts;
    }

    protected function recountTheRest(array &$counts): void
    {
        FilterPage::query()
            ->with('category')
            ->chunkById(200, function ($pages) use (&$counts) {
                foreach ($pages as $page) {
                    // Skipped here rather than excluded in SQL: a shop with a page
                    // per town has more ids than a query may bind.
                    if (isset($this->counted[$page->id]) || ! $page->category) {
                        continue;
                    }

                    $page->forceFill(['products_count' => $page->productsQuery()->count()])->save();
                    $counts['refreshed']++;
                }
            });
    }

    /**
     * One page of the rule's axis: one value of its characteristic, one town,
     * or one price bracket.
     *
     * @param  FeatureValue|int|array{min: int|null, max: int|null}  $item
     * @param  array<string, int>  $counts
     */
    protected function one(FilterRule $rule, Category $category, FeatureValue|int|array $item, array &$counts, int $index = 0): void
    {
        $value = $item instanceof FeatureValue ? $item : null;
        $priceBounds = is_array($item) ? $item : null;
        $placeId = $value || $priceBounds ? null : (int) $item;
        $priceMin = $priceBounds['min'] ?? null;
        $priceMax = $priceBounds['max'] ?? null;

        $values = $value ? [$rule->feature_id => [$value->id]] : [];
        $fingerprint = FilterPage::fingerprintFor((int) $category->id, $values, $placeId, [], $priceMin, $priceMax);

        // A city page holds the SAME products as its category — the town is
        // words, not a filter — so what is counted is the category itself. A
        // price bracket DOES narrow, the same comparison the sidebar and a
        // hand-made price page both use. `min_products` still applies to
        // either: an empty category is not worth a page per town, and a
        // bracket nothing falls into is not worth a page either.
        $count = match (true) {
            $value !== null => $this->countProducts($category, (int) $rule->feature_id, (int) $value->id),
            $priceBounds !== null => $this->countProducts($category, priceMin: $priceMin, priceMax: $priceMax),
            default => $this->countProducts($category),
        };

        $page = FilterPage::where('fingerprint', $fingerprint)->first();

        if (! $page) {
            if ($count < max(1, (int) $rule->min_products)) {
                $counts['skipped']++;

                return;
            }

            $this->create($rule, $category, $value, $placeId, $values, $fingerprint, $count, $priceMin, $priceMax, $index);
            $counts['created']++;

            return;
        }

        // Somebody else's page — written by hand, or made by another rule.
        // Its count is still worth refreshing; nothing else here is ours.
        $this->counted[$page->id] = true;

        if ((int) $page->rule_id !== (int) $rule->id) {
            $page->forceFill(['products_count' => $count])->save();
            $counts['refreshed']++;

            return;
        }

        $thin = $count < max(1, (int) $rule->min_products);

        $page->forceFill([
            'products_count' => $count,
            // Back into the sitemap when it refills, out of it when it thins —
            // and never out of existence. See the class docblock.
            'in_sitemap' => $rule->in_sitemap && ! $thin,
        ])->save();

        $counts[$thin ? 'withdrawn' : 'refreshed']++;
    }

    protected function create(
        FilterRule $rule,
        Category $category,
        ?FeatureValue $value,
        ?int $placeId,
        array $values,
        string $fingerprint,
        int $count,
        ?int $priceMin = null,
        ?int $priceMax = null,
        int $index = 0,
    ): void {
        // `place_id`, not a place model: the town's own words reach the slug
        // and the name through the `{location}` token, which `torgash.locations`
        // registers and this module knows nothing about. Price bounds reach
        // them the same way, through `{price_min}`/`{price_max}` — bare numbers
        // this module registers itself.
        $context = ['category' => $category];

        if ($value) {
            $context['filter_values'] = collect([$value]);
        }
        if ($placeId) {
            $context['place_id'] = $placeId;
        }
        if ($priceMin !== null) {
            $context['price_min'] = $priceMin;
        }
        if ($priceMax !== null) {
            $context['price_max'] = $priceMax;
        }

        $created = FilterPage::create([
            'category_id' => $category->id,
            'rule_id' => $rule->id,
            'place_id' => $placeId,
            'slug' => $this->slug($rule, $category, $context, $value),
            'values' => FilterPage::canonicalValues($values),
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'fingerprint' => $fingerprint,
            'name' => $this->name($rule, $context),
            'products_count' => $count,
            'in_sitemap' => (bool) $rule->in_sitemap,
            // Copied once, at creation, and never refreshed below — unlike
            // `in_sitemap`, which is recomputed because thinning is measured.
            // Whether the category advertises a page is editorial, and a page
            // taken out of the strip by hand must survive tonight's run.
            'in_list' => (bool) $rule->in_list,
            'enabled' => true,
            // A price bracket has no `sort` column to read — it is a row in a
            // list the shop typed, in the order they typed it, and that order
            // is the one thing worth keeping in the link strip.
            'position' => $value ? (int) ($value->sort ?? 0) : ($priceMin === null && $priceMax === null ? 0 : $index),
        ]);

        $this->counted[$created->id] = true;
    }

    /**
     * The address, which is the one thing about a generated page that may never
     * change afterwards — so it is worked out once, here, and a collision with
     * a page that already owns the slug is resolved by numbering rather than by
     * overwriting somebody else's address.
     */
    protected function slug(FilterRule $rule, Category $category, array $context, ?FeatureValue $value): string
    {
        $default = match (true) {
            $value !== null => '{filter}',
            $rule->axis() === FilterRule::AXIS_PRICE => '{price_min}-{price_max}',
            default => '{location:1}',
        };
        $template = trim((string) $rule->slug_template) ?: $default;

        // A template that names nothing THIS axis can answer would expand to
        // the empty string for every page in the run, and they would then all
        // collide on the numbered fallback below. The column's own default is
        // `{filter}`, so that is exactly what a rule switched to towns or
        // prices arrives holding.
        $stale = match (true) {
            (bool) $value => false,
            $rule->axis() === FilterRule::AXIS_PRICE => ! str_contains($template, '{price_min') && ! str_contains($template, '{price_max'),
            default => ! str_contains($template, '{location'),
        };

        if ($stale) {
            $template = $default;
        }

        // The address is written in the BASE language, whatever the job's own
        // locale happens to be: a run under `ru` would otherwise give the
        // Russian towns Cyrillic-transliterated slugs and the same rule re-run
        // under `en` would want different ones, and an address may not move.
        $was = app()->getLocale();

        try {
            app()->setLocale(content_base_locale());
            $slug = Str::slug(app(Placeholders::class)->expand($template, $context, false));
        } finally {
            app()->setLocale($was);
        }

        // Str::slug() transliterates Cyrillic and Greek, but not CJK — a value
        // named only in one of those alphabets slugs to the empty string, which
        // would then collide with every other such value. Measured: 'Вертекс'
        // becomes 'verteks', '日本語' becomes ''.
        $slug = $slug ?: match (true) {
            $value !== null => $value->slug ?: 'f'.$value->id,
            $rule->axis() === FilterRule::AXIS_PRICE => 'pr-'.($context['price_min'] ?? '').'-'.($context['price_max'] ?? ''),
            default => 'p'.(int) ($context['place_id'] ?? 0),
        };

        $base = $slug;
        $n = 1;

        while (isset($this->taken[$category->id.'/'.$slug])
            || FilterPage::where('category_id', $category->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        $this->taken[$category->id.'/'.$slug] = true;

        return $slug;
    }

    /**
     * What the page is called, per language.
     *
     * Empty when the rule says nothing, and that is a real answer: FilterPage::
     * title() then names it after the values it stands for, which is what a
     * shop that has not written anything actually wants.
     *
     * @return array<string, string>|null
     */
    protected function name(FilterRule $rule, array $context): ?array
    {
        $names = [];
        $was = app()->getLocale();

        foreach ((array) $rule->page_name as $locale => $template) {
            if (trim((string) $template) === '') {
                continue;
            }

            // IN that language, not merely stored under it. Every token here
            // reads a translatable field — `{category}` above all — and a job
            // runs in the app's default locale, so without this the Russian
            // name comes out reading "Laptops Vertex". Measured live.
            try {
                app()->setLocale($locale);
                $rendered = trim(app(Placeholders::class)->expand((string) $template, $context, false));
            } finally {
                app()->setLocale($was);
            }

            if ($rendered !== '') {
                $names[$locale] = $rendered;
            }
        }

        return $names ?: null;
    }

    /** How many products a visitor would actually find there. */
    protected function countProducts(Category $category, ?int $featureId = null, ?int $valueId = null, ?int $priceMin = null, ?int $priceMax = null): int
    {
        // The subtree, exactly as the page itself will show it: counting the
        // branch's own products while the page lists everything under it is how
        // `products_count` becomes a number that contradicts the screen.
        return FilterPages::applyPriceBounds(
            Product::visible()
                ->whereHas('categories', fn ($q) => $q->whereIn(
                    'm_catalog_categories.id',
                    app(CategoryTree::class)->descendants((int) $category->id),
                ))
                ->when($featureId, fn ($q) => $q->whereHas('featureAssignments', fn ($f) => $f
                    ->where('feature_id', $featureId)->where('feature_value_id', $valueId))),
            $priceMin,
            $priceMax,
        )->count();
    }
}
