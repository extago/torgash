<?php

namespace Torgash\Catalog\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Pricing\Prices;

/**
 * "Add a variation" on a product's Variations tab: new members of its group
 * made FROM it, one per combination of the answers chosen.
 *
 * Not a duplicate. A copy carries the description, the photos and the tabs
 * along, and a variation must not: the engine shows a member's content from
 * its source when the member has none of its own (`Product::contentFrom()`),
 * so the new product is made EMPTY of content and told whom it takes it
 * from — this product, or the product this one already takes it from (never
 * a chain). The moment it gets photos of its own, they win; the description
 * stays shared until somebody types one on the member.
 *
 * What IS copied are the facts of the model that every variation shares:
 * the group word, the categories and labels, the prices (list, cost, the
 * tiers), weight and size, the tax and availability, the characteristics
 * other than the ones chosen here. Its SKU is the model's to assign (or the
 * numbering module's), its slug is made from its name, its external id is
 * nobody's yet, its counters start at zero.
 *
 * Which combinations: the cartesian product of the values ticked per
 * characteristic — red × 40, 42, 44 is three products of one new card. A
 * combination the group already holds (the same answer on every
 * characteristic asked about — this product's own included) is skipped and
 * counted, so the staff can tick the whole size row without thinking about
 * which ones exist.
 */
class VariationMaker
{
    /** More products than this from one click is a mistake, not a range. */
    public const MAX = 50;

    public function __construct(protected Variations $variations, protected Prices $prices) {}

    /**
     * @param  array<int, list<int|string>>  $answers  feature id => the values to make members for: a listed value's id, or the typed text
     * @param  array{price?: string|float|int|null, stock?: int|null, activate?: bool}  $trade
     * @return array{created: list<Product>, existed: int}
     *
     * @throws InvalidArgumentException nothing chosen, a value of another characteristic, too many
     */
    public function make(Product $from, array $answers, array $trade = []): array
    {
        if (! $from->exists || ! $from->variation_group) {
            throw new InvalidArgumentException(__('Give this product a variation group first.'));
        }

        $features = Feature::query()->where('purpose', Feature::PURPOSE_VARIATION)->get()->keyBy('id');

        // Only variation characteristics, only non-empty choices, the listed
        // values proven to be that characteristic's own.
        $chosen = [];
        foreach ($answers as $featureId => $values) {
            $feature = $features->get((int) $featureId);
            if (! $feature) {
                continue;
            }

            $values = array_values(array_unique(array_filter(array_map(
                fn ($v) => is_int($v) || ctype_digit((string) $v) ? (int) $v : trim((string) $v),
                (array) $values,
            ), fn ($v) => $v !== '' && $v !== 0)));

            if ($values === []) {
                continue;
            }

            $ids = array_filter($values, 'is_int');
            if ($ids !== []) {
                $known = FeatureValue::query()->where('feature_id', $feature->id)->whereIn('id', $ids)->pluck('id')->all();
                if (count($known) !== count($ids)) {
                    throw new InvalidArgumentException(__('A value chosen does not belong to :feature.', ['feature' => $feature->t('name')]));
                }
            }

            $chosen[$feature->id] = $values;
        }

        if ($chosen === []) {
            throw new InvalidArgumentException(__('Choose at least one value that tells the new variation apart.'));
        }

        $combos = $this->combinations($chosen);
        if (count($combos) > self::MAX) {
            throw new InvalidArgumentException(__('That is :n products at once; the most one click makes is :max.', ['n' => count($combos), 'max' => self::MAX]));
        }

        // What the group already holds, by the same keys Variations::answer() uses.
        $group = $this->variations->for($from, everyStatus: true);
        $featureIds = array_keys($chosen);
        $taken = [];
        foreach ($group['members'] as $member) {
            $taken[$this->signature(array_map(fn (int $id) => $group['values'][$member->id][$id]['key'] ?? null, $featureIds))] = true;
        }

        $from->loadMissing('featureAssignments.featureValue', 'categories', 'labels', 'priceTiers');
        $ownAnswers = $from->featureAssignments->keyBy('feature_id');

        $created = [];
        $existed = 0;

        DB::transaction(function () use ($combos, $featureIds, &$taken, &$created, &$existed, $from, $ownAnswers, $trade) {
            foreach ($combos as $combo) {
                $signature = $this->signature(array_map(fn (int $id) => $this->key($combo[$id]), $featureIds));
                if (isset($taken[$signature])) {
                    $existed++;

                    continue;
                }
                $taken[$signature] = true;

                $created[] = $this->one($from, $combo, $ownAnswers, $trade);
            }
        });

        return ['created' => $created, 'existed' => $existed];
    }

    /**
     * @param  array<int, int|string>  $combo  feature id => value id or text
     * @param  Collection<int, ProductFeature>  $ownAnswers
     */
    protected function one(Product $from, array $combo, $ownAnswers, array $trade): Product
    {
        $name = $this->name($from, $combo, $ownAnswers);
        $baseLocale = content_base_locale();

        $product = $from->replicate([
            ...Product::GENERATED_COLUMNS, ...Product::DERIVED_COLUMNS,
            'created_at', 'updated_at', 'deleted_at',
            // Content: shown from the source, never copied (Product::contentFrom()).
            'description', 'short_description', 'features', 'features_layout', 'features_translations',
            'image_url', 'share_image',
            // Its own, nobody else's.
            'sku', 'slug', 'external_id', 'meta_title', 'meta_description', 'h1', 'canonical_url',
            'views_count', 'sales_count', 'popularity',
        ]);

        $product->name = $name;
        $product->sku = '';
        $product->slug = $this->freeSlug(Slugs::make($name[$baseLocale] ?? (reset($name) ?: 'variation')));
        $product->stock = max(0, (int) ($trade['stock'] ?? 0));
        $product->status = ! empty($trade['activate']) ? Product::STATUS_ACTIVE : Product::STATUS_DISABLED;
        $product->content_source_id = $from->content_source_id ?: $from->id;
        $product->views_count = 0;
        $product->sales_count = 0;
        $product->popularity = 0;

        if (($trade['price'] ?? null) !== null && $trade['price'] !== '') {
            // Typed in this product's own currency, like the list's quick edit:
            // the one place that arithmetic lives.
            $product->fill($this->prices->columns(['price' => $trade['price']], $from->price_currency));
        }

        $product->save();

        $product->categories()->sync($from->categories->pluck('id'));
        $product->labels()->sync($from->labels->pluck('id'));

        foreach ($from->priceTiers as $tier) {
            $copy = $tier->replicate(['product_id']);
            $product->priceTiers()->save($copy);
        }

        // Its characteristics: the ones chosen here, and every other one of
        // this product's as it stands.
        foreach ($ownAnswers as $featureId => $assignment) {
            if (array_key_exists($featureId, $combo)) {
                continue;
            }

            $product->featureAssignments()->create($assignment->only(['feature_id', 'feature_value_id', 'value_text', 'value_translations']));
        }

        foreach ($combo as $featureId => $value) {
            $product->featureAssignments()->create(is_int($value)
                ? ['feature_id' => $featureId, 'feature_value_id' => $value]
                : ['feature_id' => $featureId, 'value_text' => $value]);
        }

        $product->unsetRelation('featureAssignments');

        // The form's own "saved" announcement, with a request that carries no
        // form field: the numbering module applies its category template now
        // that the categories are in, the price history records the first
        // price; a module whose field is absent leaves the product alone.
        hook_action('catalog.product.saved', ['product' => $product, 'request' => request()]);

        return $product;
    }

    /**
     * The new member's name: this product's, with the value that changed
     * swapped in where the old one is written ("… 256 GB Black" → "… 512 GB
     * Black"), appended when it is not. Per language of the name.
     *
     * @return array<string, string>
     */
    protected function name(Product $from, array $combo, $ownAnswers): array
    {
        $name = (array) ($from->name ?? []);
        $baseLocale = content_base_locale();

        foreach ($combo as $featureId => $value) {
            $own = $ownAnswers->get($featureId);
            $ownValue = $own ? ($own->feature_value_id ? (int) $own->feature_value_id : (string) $own->value_text) : null;
            if ($ownValue !== null && $this->key($ownValue) === $this->key($value)) {
                continue;
            }

            $new = $this->labels($value);
            $old = $ownValue !== null ? $this->labels($ownValue, $own->value_translations) : [];

            foreach ($name as $locale => $text) {
                $newLabel = $new[$locale] ?? $new[$baseLocale] ?? reset($new);
                $oldLabel = $old[$locale] ?? $old[$baseLocale] ?? (reset($old) ?: null);
                if ($newLabel === false || $newLabel === null || $newLabel === '') {
                    continue;
                }

                $swapped = $oldLabel ? preg_replace('/'.preg_quote($oldLabel, '/').'/iu', $newLabel, (string) $text, 1) : null;
                $name[$locale] = $swapped !== null && $swapped !== $text ? $swapped : trim($text.' '.$newLabel);
            }
        }

        return $name;
    }

    /** A value's words per language: a listed value's own, or the typed text with its translations. */
    protected function labels(int|string $value, ?array $translations = null): array
    {
        if (is_int($value)) {
            return array_filter((array) (FeatureValue::find($value)?->value ?? []), 'is_string');
        }

        return array_filter([content_base_locale() => $value] + (array) ($translations ?? []), fn ($v) => is_string($v) && $v !== '');
    }

    /** The same key Variations::answer() compares by. */
    protected function key(int|string $value): string
    {
        return is_int($value) ? 'v'.$value : 't'.mb_strtolower(trim($value));
    }

    /** @param  list<?string>  $keys */
    protected function signature(array $keys): string
    {
        return implode('|', array_map(fn ($k) => $k ?? '∅', $keys));
    }

    /**
     * @param  array<int, list<int|string>>  $chosen
     * @return list<array<int, int|string>>
     */
    protected function combinations(array $chosen): array
    {
        $combos = [[]];
        foreach ($chosen as $featureId => $values) {
            $next = [];
            foreach ($combos as $combo) {
                foreach ($values as $value) {
                    $next[] = $combo + [$featureId => $value];
                }
            }
            $combos = $next;
        }

        return $combos;
    }

    /** $slug, or $slug-2, -3… — the first nobody has, the bin included. */
    protected function freeSlug(string $slug): string
    {
        $slug = $slug !== '' ? $slug : 'variation';
        $candidate = $slug;

        for ($n = 2; Product::withTrashed()->where('slug', $candidate)->exists(); $n++) {
            $candidate = $slug.'-'.$n;
        }

        return $candidate;
    }
}
