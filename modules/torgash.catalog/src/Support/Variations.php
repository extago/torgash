<?php

namespace Torgash\Catalog\Support;

use Illuminate\Database\Eloquent\Collection;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;

/**
 * A product's variations, answered in ONE place: the storefront's pickers,
 * the choice window, the machine-readable facts and the admin's table all
 * ask this class, so none of them can disagree about what the group is.
 *
 * A group is the shared `variation_group` word — typing the same word on two
 * products is the whole link. What they are told apart BY is not guessed:
 *
 *  - an axis is a GLOBAL characteristic whose purpose is `variation`
 *    (`Feature::PURPOSE_VARIATION`), never a free-form individual attribute.
 *    An import matches characteristics by their code and values by theirs;
 *    a free-form key is whatever the supplier's file happened to call it,
 *    and products that differ in thirty ways must still be switched only by
 *    the two the shop said matter;
 *  - of those, only the ones whose values actually differ inside the group
 *    are shown (a "Color" every member shares switches nothing);
 *  - two members with the SAME answer on every axis cannot both be offered —
 *    nothing would tell them apart. The one created first stays (then the
 *    lower id), the rest are DUPLICATES: still their own pages, but never a
 *    target of a picker, and the admin's table says so in yellow.
 */
class Variations
{
    /**
     * @param  bool  $everyStatus  the admin's view: hidden and disabled members too
     * @return array{
     *     members: Collection<int, Product>,
     *     offered: Collection<int, Product>,
     *     axes: list<array{feature: Feature, options: list<array{key: string, label: string, swatch: ?string, active: bool, product: Product}>}>,
     *     values: array<int, array<int, array{key: string, label: string, swatch: ?string}>>,
     *     duplicates: array<int, int>,
     * }
     */
    public function for(Product $product, bool $everyStatus = false): array
    {
        $members = $this->members($product, $everyStatus);
        $variationFeatures = Feature::query()->where('purpose', Feature::PURPOSE_VARIATION)
            ->orderBy('sort')->orderBy('id')->get()->keyBy('id');

        // Each member's answer per variation characteristic: a KEY to compare
        // by (the value's id for a list, the typed text otherwise) and what to
        // print.
        $values = [];
        foreach ($members as $member) {
            foreach ($member->featureAssignments as $assignment) {
                if (! $variationFeatures->has($assignment->feature_id)) {
                    continue;
                }

                $answer = $this->answer($assignment);
                if ($answer !== null) {
                    $values[$member->id][$assignment->feature_id] = $answer;
                }
            }
        }

        // Axes: the variation characteristics whose answers differ in the group.
        $axisIds = $variationFeatures->keys()->filter(function (int $featureId) use ($values) {
            $keys = [];
            foreach ($values as $answers) {
                if (isset($answers[$featureId])) {
                    $keys[$answers[$featureId]['key']] = true;
                }
            }

            return count($keys) > 1;
        })->values()->all();

        // Duplicates: the same answer on every axis as a member created before.
        $duplicates = [];
        $seen = [];
        $byAge = $members->sortBy(fn (Product $m) => [$m->created_at?->getTimestamp() ?? 0, $m->id]);
        foreach ($byAge as $member) {
            if ($axisIds === []) {
                break;
            }

            $signature = implode('|', array_map(fn ($id) => $values[$member->id][$id]['key'] ?? '∅', $axisIds));
            if (isset($seen[$signature])) {
                $duplicates[$member->id] = $seen[$signature];
            } else {
                $seen[$signature] = $member->id;
            }
        }

        $offered = $members->reject(fn (Product $m) => isset($duplicates[$m->id]))->values();

        $axes = [];
        foreach ($axisIds as $featureId) {
            $options = [];
            foreach ($offered as $member) {
                $answer = $values[$member->id][$featureId] ?? null;
                if ($answer === null || isset($options[$answer['key']])) {
                    continue;
                }

                $options[$answer['key']] = $answer + [
                    'active' => ($values[$product->id][$featureId]['key'] ?? null) === $answer['key'],
                    'product' => $this->target($product, $offered, $values, $axisIds, $featureId, $answer['key']),
                ];
            }

            $axes[] = [
                'feature' => $variationFeatures[$featureId],
                'options' => array_values(collect($options)->sortBy('sort')->map(fn ($o) => array_diff_key($o, ['sort' => 0]))->all()),
            ];
        }

        return [
            'members' => $members,
            'offered' => $offered,
            'axes' => $axes,
            'values' => $values,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * The axes a visitor is offered as a CHOICE (the product page, the choice
     * window, quick view): `for()`'s axes without the ones that have stopped
     * being a choice. A characteristic marked `hide_single_in_stock` drops
     * out when only one of its values is still for sale and this page already
     * holds it — "New / As new" once the new ones are sold out. A page that
     * holds a sold-out value keeps the row: it is the way to the one in stock.
     * The variants table and the facts keep reading `for()`.
     *
     * @return list<array{feature: Feature, options: list<array>}>
     */
    public function shown(Product $product): array
    {
        $group = $this->for($product);

        return array_values(array_filter($group['axes'], function (array $axis) use ($group, $product) {
            $featureId = $axis['feature']->id;
            if (! $axis['feature']->hide_single_in_stock) {
                return true;
            }

            $forSale = $group['offered']
                ->filter(fn (Product $member) => $member->isOrderable())
                ->map(fn (Product $member) => $group['values'][$member->id][$featureId]['key'] ?? null)
                ->filter()->unique()->values();

            return ! ($forSale->count() === 1 && $forSale->first() === ($group['values'][$product->id][$featureId]['key'] ?? null));
        }));
    }

    /**
     * What each product IS within its group, as a line of a basket or an
     * order prints it — "Colour: Purple · Memory: 128 GB": its answers on the
     * variation characteristics, in their order. Only for products in a group
     * (a lone product has nothing it was chosen from); one query for the
     * characteristics and one for the answers, however many products.
     *
     * @param  iterable<Product>  $products
     * @return array<int, list<array{label: string, value: string}>> by product id; a product with none is absent
     */
    public function answersFor(iterable $products): array
    {
        $grouped = (new Collection(is_array($products) ? $products : iterator_to_array($products, false)))
            ->filter(fn ($product) => $product instanceof Product && $product->variation_group)
            ->values();

        if ($grouped->isEmpty()) {
            return [];
        }

        $features = Feature::query()->where('purpose', Feature::PURPOSE_VARIATION)
            ->orderBy('sort')->orderBy('id')->get()->keyBy('id');
        if ($features->isEmpty()) {
            return [];
        }

        $grouped->loadMissing('featureAssignments.featureValue');

        $answers = [];
        foreach ($grouped as $product) {
            $own = [];
            foreach ($product->featureAssignments as $assignment) {
                if ($features->has($assignment->feature_id) && ($answer = $this->answer($assignment)) !== null) {
                    $own[$assignment->feature_id] = $answer['label'];
                }
            }

            foreach ($features as $featureId => $feature) {
                if (isset($own[$featureId])) {
                    $answers[$product->id][] = ['label' => (string) $feature->t('name'), 'value' => $own[$featureId]];
                }
            }
        }

        return $answers;
    }

    /**
     * The best member for switching one axis to one value: it has that value
     * and keeps as many of THIS product's other answers as it can — so moving
     * from black 256 to white lands on white 256, not on white 128.
     */
    protected function target(Product $product, Collection $offered, array $values, array $axisIds, int $featureId, string $key): Product
    {
        $best = null;
        $bestScore = -1;

        foreach ($offered as $member) {
            if (($values[$member->id][$featureId]['key'] ?? null) !== $key) {
                continue;
            }

            $score = 0;
            foreach ($axisIds as $otherId) {
                if ($otherId !== $featureId
                    && isset($values[$product->id][$otherId], $values[$member->id][$otherId])
                    && $values[$product->id][$otherId]['key'] === $values[$member->id][$otherId]['key']) {
                    $score++;
                }
            }

            if ($member->id === $product->id) {
                $score += 0.5; // the page being read wins a tie
            }

            if ($score > $bestScore) {
                $best = $member;
                $bestScore = $score;
            }
        }

        return $best ?? $product;
    }

    /**
     * The axes as the admin's table lists them: the ones whose values make
     * cards in the catalogue first (Colour), then the ones chosen on a card
     * (Size) — so the rows group by card. Within a kind, the storefront's
     * own order.
     *
     * @param  list<array{feature: Feature, options: list<array>}>  $axes  from for()
     * @return list<array{feature: Feature, options: list<array>}>
     */
    public static function axesForTable(array $axes): array
    {
        $choice = fn (array $axis) => $axis['feature']->variation_role === Feature::ROLE_CHOICE;

        return array_values(array_merge(
            array_filter($axes, fn (array $axis) => ! $choice($axis)),
            array_filter($axes, $choice),
        ));
    }

    /**
     * The group's members in the order the admin's table shows them: by
     * card (the answers on the card axes, in those values' own order), then
     * by the values chosen on the card, then by id — the sizes of one colour
     * together, in size order. Each row names its card (the card axes'
     * labels, "Black · 256 GB"), empty when no axis makes cards.
     *
     * @param  array  $group  from for()
     * @param  list<array{feature: Feature, options: list<array>}>  $axes  from axesForTable()
     * @return list<array{product: Product, card: string, cardLabel: string}>
     */
    public static function tableRows(array $group, array $axes): array
    {
        $cardAxes = array_filter($axes, fn (array $axis) => $axis['feature']->variation_role !== Feature::ROLE_CHOICE);
        $positions = [];
        foreach ($axes as $axis) {
            foreach ($axis['options'] as $i => $option) {
                $positions[$axis['feature']->id][$option['key']] = $i;
            }
        }

        $rows = [];
        foreach ($group['members'] as $member) {
            $answers = $group['values'][$member->id] ?? [];
            $order = [];
            foreach ($axes as $axis) {
                $key = $answers[$axis['feature']->id]['key'] ?? null;
                $order[] = $key === null ? PHP_INT_MAX : ($positions[$axis['feature']->id][$key] ?? PHP_INT_MAX - 1);
            }
            $order[] = $member->id;

            $rows[] = [
                'product' => $member,
                'card' => implode('|', array_map(fn (array $axis) => $answers[$axis['feature']->id]['key'] ?? '∅', $cardAxes)),
                'cardLabel' => implode(' · ', array_filter(array_map(fn (array $axis) => $answers[$axis['feature']->id]['label'] ?? null, $cardAxes))),
                'order' => $order,
            ];
        }

        usort($rows, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_map(fn (array $row) => array_diff_key($row, ['order' => 0]), $rows);
    }

    /** @return Collection<int, Product> */
    protected function members(Product $product, bool $everyStatus): Collection
    {
        if (! $product->variation_group) {
            $product->loadMissing('featureAssignments.featureValue');

            return new Collection([$product]);
        }

        return Product::query()
            ->where('variation_group', $product->variation_group)
            ->when(! $everyStatus, fn ($q) => $q->visible())
            ->with('featureAssignments.featureValue')
            ->orderBy('id')
            ->get();
    }

    /** @return array{key: string, label: string, swatch: ?string, sort: int}|null */
    protected function answer(ProductFeature $assignment): ?array
    {
        if ($assignment->feature_value_id && $assignment->featureValue) {
            return [
                'key' => 'v'.$assignment->feature_value_id,
                'label' => (string) $assignment->featureValue->display(),
                'swatch' => $assignment->featureValue->swatch ?: null,
                'sort' => (int) $assignment->featureValue->sort,
            ];
        }

        $text = trim((string) $assignment->value_text);

        // Matched by the base word, which every language's variant shares;
        // shown in the page's language.
        return $text === '' ? null : [
            'key' => 't'.mb_strtolower($text),
            'label' => (string) $assignment->display(),
            'swatch' => null,
            'sort' => 0,
        ];
    }
}
