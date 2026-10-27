<?php

namespace Torgash\Compare;

use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Product;

/**
 * The rows of a comparison: every characteristic at least one of the products
 * has, as DATA, so the page and a test read one answer.
 *
 * The same characteristics the product page prints under "Specifications"
 * and no others — a catalogued one only where the shop ticked `specs` for it
 * (Feature::shows(), the rule ProductFacts::specs() obeys too), in the
 * catalogue's own order: its group (a heading), then its place in the group.
 * The product's own free-form characteristics follow under "Other", matched
 * by name, in the order the first product lists them.
 *
 * A row DIFFERS when the products do not all say the same thing — and having
 * no value is a thing to say: "has a fingerprint reader / —" is exactly the
 * difference somebody is comparing for. One product never differs.
 */
class Table
{
    /**
     * @param  Collection<int, Product>  $products  in column order
     * @return list<array{type: 'section', label: string}|array{type: 'feature', label: string, values: array<int, string|null>, differs: bool}>
     */
    public function rows(Collection $products): array
    {
        $products->loadMissing('featureAssignments.feature.group', 'featureAssignments.featureValue');

        $catalogued = [];

        foreach ($products as $product) {
            foreach ($product->featureAssignments as $assignment) {
                $feature = $assignment->feature;
                $value = $assignment->display();

                if (! $feature || ! $feature->shows('specs') || $value === null || $value === '') {
                    continue;
                }

                $catalogued[$feature->id] ??= ['feature' => $feature, 'values' => []];
                $catalogued[$feature->id]['values'][$product->id] = $value;
            }
        }

        // Group first (ungrouped ones lead, under no heading), then the
        // characteristic's own place; ids settle every tie.
        uasort($catalogued, fn ($a, $b) => [
            $a['feature']->group ? 1 : 0, $a['feature']->group?->sort ?? 0, $a['feature']->group_id ?? 0, $a['feature']->sort, $a['feature']->id,
        ] <=> [
            $b['feature']->group ? 1 : 0, $b['feature']->group?->sort ?? 0, $b['feature']->group_id ?? 0, $b['feature']->sort, $b['feature']->id,
        ]);

        $rows = [];
        $heading = null;

        foreach ($catalogued as $entry) {
            $group = $entry['feature']->group;

            if ($group && $group->id !== $heading) {
                $rows[] = ['type' => 'section', 'label' => (string) $group->t('name')];
                $heading = $group->id;
            }

            $rows[] = $this->row((string) $entry['feature']->t('name'), $entry['values'], $products);
        }

        $free = $this->freeForm($products);

        if ($free !== [] && $rows !== []) {
            $rows[] = ['type' => 'section', 'label' => __('Other')];
        }

        foreach ($free as $label => $values) {
            $rows[] = $this->row($label, $values, $products);
        }

        return $rows;
    }

    /**
     * Only the rows that differ, and a heading only where something under it
     * is left.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function differencesOnly(array $rows): array
    {
        $kept = [];
        $pending = null;

        foreach ($rows as $row) {
            if ($row['type'] === 'section') {
                $pending = $row;
            } elseif ($row['differs']) {
                if ($pending) {
                    $kept[] = $pending;
                    $pending = null;
                }

                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * @param  array<int, string>  $values  product id => value
     * @param  Collection<int, Product>  $products
     */
    protected function row(string $label, array $values, Collection $products): array
    {
        $cells = [];

        foreach ($products as $product) {
            $cells[$product->id] = $values[$product->id] ?? null;
        }

        $said = array_map(fn ($v) => $v === null ? null : mb_strtolower(trim($v)), $cells);

        return [
            'type' => 'feature',
            'label' => $label,
            'values' => $cells,
            'differs' => count($cells) > 1 && count(array_unique($said, SORT_REGULAR)) > 1,
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<string, array<int, string>> label => product id => value
     */
    protected function freeForm(Collection $products): array
    {
        $out = [];

        foreach ($products as $product) {
            foreach ($product->featureRows() as $row) {
                if ($row['type'] !== 'feature') {
                    continue;
                }

                $value = is_scalar($row['value'])
                    ? trim((string) $row['value'])
                    : implode(', ', array_map('strval', array_filter((array) $row['value'], 'is_scalar')));

                if ($value !== '') {
                    // By the words the reader sees: the same row in two
                    // products lines up in the page's language.
                    $out[(string) ($row['label'] ?? $row['key'])][$product->id] = $value;
                }
            }
        }

        return $out;
    }
}
