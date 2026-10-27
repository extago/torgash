<?php

namespace Torgash\Catalog\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Catalog\Support\Labels;

/**
 * A product badge ("New", "Sale", ...), assignable manually and/or via a
 * rule list evaluated live against real product columns/relations — see
 * applyConditions(). No static/dynamic split or cron-cached
 * "generated_ids": every field here (price, stock, age, category, feature)
 * is a cheap indexed comparison, so re-querying per page is fine and keeps
 * one single source of truth for what a rule matches (admin preview and
 * storefront rendering both go through applyConditions()).
 */
class Label extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia;

    protected $table = 'm_catalog_labels';

    protected $guarded = [];

    public const OPERATORS = [
        'eq' => '=', 'neq' => '<>', 'lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>=',
    ];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Product label';
    }

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'section_title' => 'array',
            'conditions' => 'array',
            'custom_colors' => 'array',
            'tooltip' => 'array',
            'enabled' => 'bool',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * How a label is drawn: words in a badge, a picture, or a TILE — a square
     * with a picture (its own upload, SVG included, or a Lucide `icon`) and
     * the words under it: the pictogram ("RAM / 32 GB").
     */
    public const STYLES = ['text', 'image', 'tile'];

    /** A tile's side per size step, px. */
    public const TILE_SIDES = ['xs' => 44, 'sm' => 52, 'md' => 60, 'lg' => 72];

    /** @param  'list'|'detail'  $place */
    public function tileSide(string $place): int
    {
        return self::TILE_SIDES[$this->sizeFor($place)];
    }

    /**
     * The tile's accent: one of the theme's tones, or the shop's own light
     * background colour — the icon wears it, the tile a faint wash of it.
     */
    public function tileAccent(): string
    {
        return $this->usesCustomColors()
            ? (string) ($this->custom_colors['light']['bg'] ?? 'var(--color-primary)')
            : 'var(--color-'.$this->color.')';
    }

    /** A text badge's steps — daisyUI's badge sizes. `sm` is what every label was before sizes existed. */
    public const SIZES = ['xs', 'sm', 'md', 'lg'];

    /** What a picture is cut to. `as_is` keeps the file's own proportions. */
    public const SHAPES = ['as_is', 'square', 'rounded', 'circle'];

    /** The colour that means "the shop's own hex pair" rather than one of the theme's. */
    public const CUSTOM_COLOR = 'custom';

    /**
     * The DB defaults, mirrored: Postgres does not hand them back after an
     * INSERT, and a label created in code would otherwise draw at no size.
     */
    protected $attributes = [
        'size_list' => 'sm',
        'size_detail' => 'sm',
        'image_height_list' => 28,
        'image_height_detail' => 28,
        'image_shape' => 'as_is',
    ];

    /** @param  'list'|'detail'  $place */
    public function sizeFor(string $place): string
    {
        $size = $place === 'detail' ? $this->size_detail : $this->size_list;

        return in_array($size, self::SIZES, true) ? $size : 'sm';
    }

    /** @param  'list'|'detail'  $place */
    public function imageHeightFor(string $place): int
    {
        return max(12, min(160, (int) ($place === 'detail' ? $this->image_height_detail : $this->image_height_list) ?: 28));
    }

    /**
     * The picture's box: its height always, and for every shape but `as_is`
     * the same width, the file cropped into it — a flag drawn from a
     * rectangle is still a circle.
     *
     * @return array{style: string, class: string}
     */
    public function imageBox(string $place): array
    {
        $h = $this->imageHeightFor($place);
        $shape = in_array($this->image_shape, self::SHAPES, true) ? $this->image_shape : 'as_is';

        // The radius travels in the style with the size: it is a number
        // worked out here, not a class the stylesheet has to have compiled.
        return $shape === 'as_is'
            ? ['style' => "height: {$h}px; width: auto;", 'class' => '']
            : ['style' => "height: {$h}px; width: {$h}px; object-fit: cover; border-radius: ".match ($shape) {
                'rounded' => '22%',
                'circle' => '50%',
                default => '0',
            }.';', 'class' => ''];
    }

    public function usesCustomColors(): bool
    {
        return $this->color === self::CUSTOM_COLOR;
    }

    /**
     * The shop's own colours as custom properties for `.torgash-label-custom`
     * (app.css), which swaps to the `-dark` pair under the dark theme. Only
     * `#rrggbb` gets through: this lands in a style attribute.
     */
    public function colorStyle(): string
    {
        if (! $this->usesCustomColors()) {
            return '';
        }

        $hex = fn ($v) => is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? $v : null;
        $c = $this->custom_colors ?? [];
        $vars = array_filter([
            '--lbl-bg' => $hex($c['light']['bg'] ?? null),
            '--lbl-fg' => $hex($c['light']['text'] ?? null),
            '--lbl-bg-dark' => $hex($c['dark']['bg'] ?? null),
            '--lbl-fg-dark' => $hex($c['dark']['text'] ?? null),
        ]);

        return implode('; ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($vars), $vars));
    }

    /**
     * The badge's classes for a text label, colour and size together, so the
     * storefront and the admin cannot draw two different badges.
     */
    public function badgeClass(string $place): string
    {
        return 'badge torgash-label badge-'.$this->sizeFor($place).' '
            .($this->usesCustomColors() ? 'torgash-label-custom' : 'badge-'.$this->color);
    }

    /** The hint over the badge, at one locale — empty when none is written. */
    public function tooltipText(?string $locale = null): string
    {
        return trim((string) $this->t('tooltip', $locale));
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile();
    }

    public function imageUrl(): ?string
    {
        return $this->getFirstMediaUrl('image') ?: null;
    }

    /** Manually-assigned products, independent of any rule list below. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'm_catalog_label_product');
    }

    public function hasRules(): bool
    {
        return ! empty($this->conditions['rules']);
    }

    /**
     * Can applyConditions() answer this label's rules on its own? Not when one
     * of them is "discount", which only exists on a loaded product — see
     * matchedIdsFor(). A caller that needs a QUERY (a product block, which is
     * paginated by nature) has to know it is being handed only half the rules.
     */
    public function rulesAreSql(): bool
    {
        return ! collect($this->conditions['rules'] ?? [])->contains(fn ($r) => ($r['field'] ?? null) === 'discount');
    }

    public function isActiveNow(): bool
    {
        if (! $this->enabled) {
            return false;
        }
        $now = now();

        return (! $this->starts_at || $now->gte($this->starts_at))
            && (! $this->ends_at || $now->lte($this->ends_at));
    }

    /**
     * The badge's translated text with per-product placeholders substituted
     * — [discount]/[price]/[old_price], the usual sticker idiom
     * (an admin types "-[discount]%" into the Name field, nothing fancier
     * than str_replace at render time). A label with no placeholders in its
     * text behaves exactly as before this method existed.
     */
    public function displayText(Product $product, ?string $locale = null): string
    {
        $text = $this->t('name', $locale) ?: $this->name_for_admin;

        $text = strtr($text, [
            '[discount]' => (string) $product->discountPercent(),
            '[price]' => format_price($product->effectivePrice()),
            '[old_price]' => format_price($product->oldPrice()),
            '[views]' => (string) $product->views_count,
            '[sales]' => (string) $product->sales_count,
        ]);

        // [feature:ram] — the product's own answer for the characteristic with
        // that code, as the specifications print it ("32 GB"): what makes a
        // label a pictogram ("RAM 32 GB") rather than one word for everybody.
        return preg_replace_callback(self::FEATURE_TOKEN, fn (array $m) => (string) self::featureAnswer($product, $m[1]), $text);
    }

    /** `[feature:<code>]` inside a label's text. */
    public const FEATURE_TOKEN = '/\[feature:([a-z0-9_-]+)\]/i';

    /** Does this label's text ask for a characteristic in any language? */
    public function usesFeatures(): bool
    {
        foreach ([...array_values((array) $this->name), (string) $this->name_for_admin] as $text) {
            if (preg_match(self::FEATURE_TOKEN, (string) $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is there an answer behind every characteristic the text asks for? A
     * label reading "RAM " on a product that has no RAM is worse than none,
     * so such a label is simply not drawn on it (forProducts()).
     */
    public function answeredFor(Product $product): bool
    {
        preg_match_all(self::FEATURE_TOKEN, (string) ($this->t('name') ?: $this->name_for_admin), $found);

        foreach ($found[1] as $code) {
            if (trim((string) self::featureAnswer($product, $code)) === '') {
                return false;
            }
        }

        return true;
    }

    protected static function featureAnswer(Product $product, string $code): ?string
    {
        $assignment = $product->featureAssignments
            ->first(fn ($pf) => strcasecmp((string) $pf->feature?->code, $code) === 0);

        return $assignment?->display();
    }

    /**
     * Heading for the storefront section this label produces (the homepage
     * tabs). The explicit section_title if the merchant set one, otherwise a
     * best-effort from the badge text — see plainName() for why that is only
     * a fallback and not the real answer.
     */
    public function sectionTitle(?string $locale = null): string
    {
        return $this->t('section_title', $locale) ?: $this->plainName($locale);
    }

    /**
     * The label's name with no product behind it — an admin dropdown, or a
     * section heading with no section_title set. displayText() cannot be used
     * there: placeholders like [discount] are resolved per product, and a
     * heading has no single product, so the raw token leaked straight onto the
     * storefront ("Sale -[discount]%" as a tab title). Strips the tokens and
     * any punctuation left dangling around them, so "Sale -[discount]%" reads
     * "Sale" — a guess at a heading, which is why section_title exists.
     */
    public function plainName(?string $locale = null): string
    {
        $text = $this->t('name', $locale) ?: $this->name_for_admin;
        $text = preg_replace('/\s*[-−–—]?\s*\[[a-z_]+(?::[a-z0-9_-]+)?\]\s*%?/iu', ' ', $text);
        $text = trim(preg_replace('/\s{2,}/u', ' ', $text));

        // A name that was nothing but a placeholder leaves nothing to show.
        return $text !== '' ? $text : $this->name_for_admin;
    }

    /**
     * Constrains $query to products this label's rule list matches. Callers
     * decide how to bound it further (whereIn on a page's product ids for
     * storefront rendering, or paginate directly for the admin preview).
     */
    public function applyConditions(Builder $query): Builder
    {
        $rules = $this->conditions['rules'] ?? [];

        if (empty($rules)) {
            return $query->whereRaw('1 = 0');
        }

        $useOr = ($this->conditions['match'] ?? 'all') === 'any';

        return $query->where(function (Builder $q) use ($rules, $useOr) {
            foreach ($rules as $rule) {
                $this->applyRule($q, $rule, $useOr ? 'orWhere' : 'where');
            }
        });
    }

    protected function applyRule(Builder $q, array $rule, string $method): void
    {
        $operator = self::OPERATORS[$rule['operator'] ?? 'eq'] ?? '=';
        $value = $rule['value'] ?? null;

        match ($rule['field'] ?? null) {
            'price' => $q->{$method}('price', $operator, (int) round(((float) $value) * 100)),
            'stock' => $q->{$method}('stock', $operator, (int) $value),
            'age_days' => $q->{$method}('created_at', $operator, now()->subDays((int) $value)),
            'views' => $q->{$method}('views_count', $operator, (int) $value),
            'sales' => $q->{$method}('sales_count', $operator, (int) $value),
            'category' => $q->{$method.'Has'}('categories', fn ($cq) => $cq->whereIn(
                'id',
                app(CategoryTree::class)->descendants(array_map('intval', (array) $value)),
            )),
            'feature' => $q->{$method.'Has'}(
                'featureAssignments',
                fn ($fq) => $fq->where('feature_id', (int) ($rule['feature_id'] ?? 0))
                    ->where('feature_value_id', (int) $value)
            ),
            // Distinct from the "discount" placeholder rule below: this only
            // fires when the product actually carries an RRP (list_price)
            // above its own price — not a group/wholesale-price-type
            // discount, which has no RRP involved at all. Both real
            // columns, so this stays fully SQL — no PHP fallback needed.
            'rrp' => $q->{$method}(fn ($rq) => $rq->whereNotNull('list_price')->whereColumn('list_price', '>', 'price')),
            default => null,
        };
    }

    /**
     * Labels applying to each of $products — manual assignment ∪ rule
     * matches — as [product_id => Collection<Label> sorted by "sort"].
     * Runs at most one query per SQL-evaluable rule-bearing label
     * (whereIn-bounded to this page's product ids), plus one for manual
     * pivots. A label whose rules include "discount" skips SQL entirely —
     * see matchedIdsFor().
     */
    public static function forProducts(Collection $products): array
    {
        $ids = $products->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }

        $labels = app(Labels::class)->active();
        if ($labels->isEmpty()) {
            return [];
        }

        // Asked once per product a request, however many grids show it.
        $manualByProduct = app(Labels::class)->manual($ids->map(fn ($id) => (int) $id)->all(), fn (array $unknown) => DB::table('m_catalog_label_product')
            ->whereIn('product_id', $unknown)
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('label_id')->map(fn ($id) => (int) $id)->all())
            ->all());

        // A label that prints a characteristic needs the products' answers —
        // read for the whole set at once, never per card.
        $byId = $products->keyBy('id');
        if ($labels->contains(fn (Label $label) => $label->usesFeatures())) {
            // Wrapped: a caller may hand a plain collection; the models in it
            // are the same instances, so the answers land on them.
            (new \Illuminate\Database\Eloquent\Collection($products->all()))
                ->loadMissing('featureAssignments.feature', 'featureAssignments.featureValue');
        }

        // Every label whose rules are SQL asked in ONE query for the grid
        // (a UNION ALL of their conditions) — a page of eight grids and four
        // such labels used to ask thirty-two times.
        $sqlLabels = $labels->filter(fn (Label $label) => $label->hasRules() && $label->rulesAreSql());
        $sqlMatched = $sqlLabels->isEmpty() ? [] : app(Labels::class)->matchedAll(
            $sqlLabels->map(fn (Label $label) => (int) $label->id)->values()->all(),
            $ids->map(fn ($id) => (int) $id)->all(),
            function (array $unknown) use ($sqlLabels) {
                $union = null;
                foreach ($sqlLabels as $label) {
                    $part = $label->applyConditions(Product::query())->whereIn('id', $unknown)
                        ->toBase()->select('id')->selectRaw((int) $label->id.' as label_id');
                    $union = $union ? $union->unionAll($part) : $part;
                }

                return $union->get()->groupBy('label_id')
                    ->map(fn ($rows) => $rows->pluck('id')->map(fn ($id) => (int) $id)->all())
                    ->all();
            },
        );

        $result = [];
        foreach ($labels as $label) {
            $matchedIds = match (true) {
                ! $label->hasRules() => [],
                $label->rulesAreSql() => $sqlMatched[(int) $label->id] ?? [],
                default => $label->matchedIdsFor($products),
            };
            $asksFeatures = $label->usesFeatures();

            foreach ($ids as $id) {
                $isManual = in_array((int) $label->id, $manualByProduct[$id] ?? [], true);
                if (($isManual || in_array($id, $matchedIds, true))
                    && (! $asksFeatures || $label->answeredFor($byId[$id]))) {
                    $result[$id][] = $label;
                }
            }
        }

        return $result;
    }

    /**
     * "discount" isn't a stored column (Product's price model resolves it
     * from effectivePrice()/list_price at read time, not a flat percent
     * like some engines'), so it can't join the SQL where() applyConditions()
     * builds. A label containing a "discount" rule is evaluated entirely in
     * PHP instead, against the already-loaded $products — sticker add-ons
     * elsewhere draw the identical line (Constant=SQL-cacheable
     * vs Dynamic=per-render PHP), just without a separate admin-facing
     * toggle: which path runs is inferred from the rule fields used.
     * PHP mode supports discount/price/stock only — category/feature/
     * age_days can't combine with a discount rule in the same label for
     * now; keeping every rule pure-SQL (the common case) is unaffected.
     * The SQL ones are asked together by forProducts(); this is the PHP path.
     */
    protected function matchedIdsFor(Collection $products): array
    {
        $rules = $this->conditions['rules'] ?? [];

        $useOr = ($this->conditions['match'] ?? 'all') === 'any';

        return $products->filter(function (Product $product) use ($rules, $useOr) {
            $results = collect($rules)->map(fn ($rule) => $this->matchesRulePhp($product, $rule));

            return $useOr ? $results->contains(true) : ! $results->contains(false);
        })->pluck('id')->all();
    }

    protected function matchesRulePhp(Product $product, array $rule): bool
    {
        if (($rule['field'] ?? null) === 'rrp') {
            return $product->list_price && $product->list_price > $product->price;
        }

        $actual = match ($rule['field'] ?? null) {
            'discount' => $product->discountPercent(),
            'price' => $product->price / 100,
            'stock' => $product->stock,
            'views' => $product->views_count,
            'sales' => $product->sales_count,
            default => null, // category/feature/age_days: not supported mixed with a discount rule
        };

        if ($actual === null) {
            return false;
        }

        $value = (float) ($rule['value'] ?? 0);

        return match ($rule['operator'] ?? 'eq') {
            'eq' => $actual == $value,
            'neq' => $actual != $value,
            'lt' => $actual < $value,
            'lte' => $actual <= $value,
            'gt' => $actual > $value,
            'gte' => $actual >= $value,
            default => false,
        };
    }
}
