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
