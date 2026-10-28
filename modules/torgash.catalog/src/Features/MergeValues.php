<?php

namespace Torgash\Catalog\Features;

use App\Core\Seo\RedirectManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\PriceTypeMarkupRule;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;

/**
 * Several values of one characteristic become one (PLAN §60): "темно-синий"
 * and "Тёмно синий" into "тёмно‑синий", "золотистый" into "золотой".
 *
 * Here, in the module that owns values, because almost everything that holds a
 * value's id is this module's: the products' links, markup rules, labels'
 * conditions, filter pages, the brand page. All of it moves in ONE transaction
 * — a half-merged catalogue (products moved, filter pages still pointing at a
 * deleted value) is worse than either end. What other modules keep (a
 * collection's conditions, a promotion's, 1C's links) they move themselves on
 * `catalog.feature_value.merged`, inside the same transaction, before the
 * merged rows are deleted.
 *
 * Nothing is lost that somebody typed: the main value's EMPTY fields (a
 * language of its name, the brand page's description, meta, logo, address, a
 * swatch) are filled from the merged ones, never overwritten. The merged
 * values' names and codes stay with it as `aliases`, so the next supplier file
 * does not make them again (Spelling::find()) and an old filtered address
 * still finds them (FilterQuery). A merged brand page's address and a filter
 * page that became a twin of another leave a 301 — the same kind of redirect
 * deleting such a page by hand leaves, because merging is the same act.
 *
 * It refuses one thing: making two products of one variation group the same
 * (see refuseClashes()).
 */
class MergeValues
{
    public function __construct(protected RedirectManager $redirects) {}

    /**
     * @param  iterable<FeatureValue|int>  $values  merged into $into, then deleted
     * @return array{values: int, products: int, filter_pages: int, labels: int, markup_rules: int}
     *
     * @throws ValidationException under the key `merge`: values of another
     *                             characteristic, or variations made the same
     */
    public function merge(FeatureValue $into, iterable $values): array
    {
        $ids = Collection::make($values)
            ->map(fn ($value) => $value instanceof FeatureValue ? (int) $value->id : (int) $value)
            ->reject(fn (int $id) => $id === (int) $into->id || $id < 1)
            ->unique()->values()->all();

        return DB::transaction(function () use ($into, $ids) {
            $into = FeatureValue::whereKey($into->id)->lockForUpdate()->firstOrFail();
            $merged = FeatureValue::whereIn('id', $ids)->where('feature_id', $into->feature_id)
                ->orderBy('id')->lockForUpdate()->get();

            if ($merged->isEmpty() || $merged->count() !== count($ids)) {
                throw ValidationException::withMessages(['merge' => __('Pick at least one other value of the same characteristic to merge.')]);
            }

            $feature = $into->feature;
            $mergedIds = $merged->pluck('id')->map(fn ($id) => (int) $id)->all();

            $this->refuseClashes($feature, $into, $mergedIds);

            $products = $this->moveProducts($into, $mergedIds);
            $markupRules = $this->moveMarkupRules($into, $mergedIds);
            $labels = $this->moveLabels($feature, $into, $mergedIds);
            $filterPages = $this->moveFilterPages($feature, $into, $mergedIds);
            $this->takeOver($feature, $into, $merged);

            hook_action('catalog.feature_value.merged', [
                'feature' => $feature,
                'into' => $into,
                'merged' => $mergedIds,
                // The rows as they were, for a listener that needs more than
                // the ids — still in the table until right after this.
                'values' => $merged,
                'products' => $products,
            ]);

            // Through the models: the audit trail records each, and the media
            // library takes a logo the main value did not need with it.
            $merged->each->delete();

            return [
                'values' => $merged->count(),
                'products' => count(array_unique($products)),
                'filter_pages' => $filterPages,
                'labels' => $labels,
                'markup_rules' => $markupRules,
            ];
        });
    }

    /**
     * Variations that the merge would make the same.
     *
     * A variation group tells its products apart by their axes' values
     * (Support\Variations); two members with the same answer on every axis
     * are duplicates, and the newer one silently stops being offered by the
     * picker. If "темно-синий" and "тёмно‑синий" are what tells two products
     * of one group apart, they are either different colours under similar
     * words or one product entered twice — and which is a person's call, so
     * the merge stops and names them. A pair that was ALREADY the same is
     * not the merge's doing and does not stop it.
     *
     * @param  list<int>  $mergedIds
     */
    protected function refuseClashes(Feature $feature, FeatureValue $into, array $mergedIds): void
    {
        if ($feature->purpose !== Feature::PURPOSE_VARIATION) {
            return;
        }

        $groups = Product::query()
            ->whereNotNull('variation_group')->where('variation_group', '<>', '')
            ->whereHas('featureAssignments', fn ($q) => $q->whereIn('feature_value_id', $mergedIds))
            ->distinct()->pluck('variation_group');

        if ($groups->isEmpty()) {
            return;
        }

        $axes = Feature::where('purpose', Feature::PURPOSE_VARIATION)->pluck('id')->all();
        $members = Product::whereIn('variation_group', $groups)
            ->with(['featureAssignments' => fn ($q) => $q->whereIn('feature_id', $axes)])
            ->orderBy('id')->get();

        $clashes = [];

        foreach ($members->groupBy('variation_group') as $group) {
            $seen = [];

            foreach ($group as $product) {
                $before = $after = [];

                foreach ($product->featureAssignments as $assignment) {
                    $answer = $assignment->feature_value_id
                        ? 'v'.$assignment->feature_value_id
                        : 't'.Str::lower(trim((string) $assignment->value_text));
                    $before[$assignment->feature_id] = $answer;
                    $after[$assignment->feature_id] = in_array((int) $assignment->feature_value_id, $mergedIds, true)
                        ? 'v'.$into->id
                        : $answer;
                }

                ksort($before);
                ksort($after);
                $afterKey = json_encode($after);

                if (isset($seen[$afterKey]) && $seen[$afterKey]['before'] !== json_encode($before)) {
                    $clashes[] = [$seen[$afterKey]['product'], $product];
                } else {
                    $seen[$afterKey] ??= ['product' => $product, 'before' => json_encode($before)];
                }
            }
        }

        if ($clashes === []) {
            return;
        }

        $name = fn (Product $product) => trim($product->t('name').($product->sku ? ' ('.$product->sku.')' : ''));

        throw ValidationException::withMessages(['merge' => __('Merging would make variations of one product the same: :pairs. Change the value on one of them, or remove the extra product, then merge.', [
            'pairs' => collect($clashes)->take(5)
                ->map(fn (array $pair) => __('":first" and ":second"', ['first' => $name($pair[0]), 'second' => $name($pair[1])]))
                ->implode('; '),
        ])]);
    }

    /**
     * Every product's link, through the model — the audit trail records the
     * change and the search index hears it (it re-reads a product whose
     * characteristics were saved). A product holds one value per
     * characteristic, so two links of one product never meet here.
     *
     * @param  list<int>  $mergedIds
     * @return list<int> the products that carry the main value now
     */
    protected function moveProducts(FeatureValue $into, array $mergedIds): array
    {
        $products = [];

        ProductFeature::whereIn('feature_value_id', $mergedIds)->lazyById(500)
            ->each(function (ProductFeature $link) use ($into, &$products) {
                $link->update(['feature_value_id' => $into->id]);
                $products[] = (int) $link->product_id;
            });

        return $products;
    }

    /** @param  list<int>  $mergedIds */
    protected function moveMarkupRules(FeatureValue $into, array $mergedIds): int
    {
        $rules = PriceTypeMarkupRule::whereHas('featureValues', fn ($q) => $q->whereIn('m_catalog_feature_values.id', $mergedIds))->get();

        foreach ($rules as $rule) {
            $rule->featureValues()->syncWithoutDetaching([$into->id]);
            $rule->featureValues()->detach($mergedIds);
        }

        return $rules->count();
    }

    /**
     * A label's "characteristic is" rules. The value keeps the type it was
     * stored in (the form writes strings), so a label nobody else touched
     * compares the same as before.
     *
     * @param  list<int>  $mergedIds
     */
    protected function moveLabels(Feature $feature, FeatureValue $into, array $mergedIds): int
    {
        $moved = 0;

        foreach (Label::query()->whereNotNull('conditions')->orderBy('id')->get() as $label) {
            $conditions = (array) $label->conditions;
            $changed = false;

            foreach ((array) ($conditions['rules'] ?? []) as $i => $rule) {
                if (($rule['field'] ?? null) === 'feature'
                    && (int) ($rule['feature_id'] ?? 0) === (int) $feature->id
                    && in_array((int) ($rule['value'] ?? 0), $mergedIds, true)) {
                    $conditions['rules'][$i]['value'] = is_string($rule['value']) ? (string) $into->id : $into->id;
                    $changed = true;
                }
            }

            if ($changed) {
                $label->conditions = $conditions;
                $label->save();
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * A filter page names a set of values, and its fingerprint is that set.
     * Rewritten in place — unless the new set is another page's: then two
     * addresses would answer one question, so one goes, with a 301 to the
     * other and its empty texts filled from the one that went. The page
     * already about the main value stays, unless it is switched off and this
     * one is published: the shop's live address is the one to keep.
     *
     * @param  list<int>  $mergedIds
     */
    protected function moveFilterPages(Feature $feature, FeatureValue $into, array $mergedIds): int
    {
        $touched = 0;
        $gone = [];
        $recount = [];

        $pages = FilterPage::query()->with('category')
            ->whereRaw('"values" -> ? is not null', [(string) $feature->id])
            ->orderBy('id')->get();

        foreach ($pages as $page) {
            $ids = array_map('intval', (array) (((array) $page->values)[$feature->id] ?? []));

            if (isset($gone[$page->id]) || array_intersect($ids, $mergedIds) === []) {
                continue;
            }

            $values = (array) $page->values;
            $values[$feature->id] = array_map(fn (int $id) => in_array($id, $mergedIds, true) ? (int) $into->id : $id, $ids);
            $page->values = FilterPage::canonicalValues($values);
            $page->fingerprint = $page->fingerprint();
            $touched++;

            // Saved one by one, not at the end: the next page's twin is
            // looked up in the table, and a fingerprint still in memory
            // would let two pages take the same one.
            $twin = FilterPage::with('category')->where('fingerprint', $page->fingerprint)->whereKeyNot($page->id)->first();

            if ($twin) {
                [$kept, $lost] = ! $twin->enabled && $page->enabled ? [$page, $twin] : [$twin, $page];
                $this->fillEmpty($kept, $lost, ['description', 'meta_title', 'meta_description', 'h1']);
                $from = $lost->path();
                $lost->delete();
                $gone[$lost->id] = true;
                unset($recount[$lost->id]);
                $this->redirects->put($from, $kept->path());
                $page = $kept;
            }

            $page->save();
            $recount[$page->id] = $page;
        }

        foreach ($recount as $page) {
            $page->forceFill(['products_count' => $page->productsQuery()->count()])->save();
        }

        return $touched;
    }

    /**
     * The main value takes what the merged ones had and it had not: a
     * language of its name, the brand page's texts, a swatch, the page's
     * address, the logo. Then their spellings, and a redirect from every
     * brand address that is going.
     *
     * @param  Collection<int, FeatureValue>  $merged
     */
    protected function takeOver(Feature $feature, FeatureValue $into, Collection $merged): void
    {
        foreach ($merged as $value) {
            $this->fillEmpty($into, $value, ['value', 'description', 'meta_title', 'meta_description', 'h1']);

            foreach (['swatch', 'canonical_url'] as $field) {
                if (blank($into->{$field}) && filled($value->{$field})) {
                    $into->{$field} = $value->{$field};
                }
            }
        }

        // The address with a history, when the main value has none: the
        // merged brand's page simply goes on being the page.
        if (blank($into->slug) && ($addressed = $merged->first(fn (FeatureValue $value) => filled($value->slug)))) {
            $slug = $addressed->slug;
            $addressed->forceFill(['slug' => null])->saveQuietly();
            $into->slug = $slug;
        }

        if (! $into->getFirstMedia('logo') && ($logo = $merged->map(fn (FeatureValue $value) => $value->getFirstMedia('logo'))->filter()->first())) {
            $logo->forceFill(['model_id' => $into->id])->save();
        }

        $aliases = (array) ($into->aliases ?? []);
        foreach ($merged as $value) {
            $aliases[] = [
                'names' => array_filter((array) $value->value, fn ($text) => is_string($text) && $text !== ''),
                'code' => $value->code,
            ];
            array_push($aliases, ...array_values((array) ($value->aliases ?? [])));
        }
        $into->aliases = $aliases;
        $into->save();

        if ($feature->purpose === 'brand' && filled($into->slug)) {
            foreach ($merged as $value) {
                if (filled($value->slug) && $value->slug !== $into->slug) {
                    $this->redirects->put('/brand/'.$value->slug, '/brand/'.$into->slug);
                }
            }
        }
    }

    /**
     * Languages `$to` has no text in, taken from `$from` — per translatable
     * field, never overwriting a language that has one.
     *
     * @param  list<string>  $fields
     */
    protected function fillEmpty(object $to, object $from, array $fields): void
    {
        foreach ($fields as $field) {
            $mine = array_filter((array) ($to->{$field} ?? []), fn ($text) => is_string($text) && trim($text) !== '');
            $theirs = array_filter((array) ($from->{$field} ?? []), fn ($text) => is_string($text) && trim($text) !== '');

            if (array_diff_key($theirs, $mine) !== []) {
                $to->{$field} = $mine + $theirs;
            }
        }
    }
}
