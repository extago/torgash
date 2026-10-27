<?php

namespace Torgash\Catalog\Support;

use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Pricing\Prices;

/**
 * Several products changed at once from the list screen — the familiar
 * "edit selected / global update" half of product management.
 *
 * Every product is saved through its MODEL, one at a time, never with a
 * query-builder update: the price history, the "back in stock" crossing,
 * reaching zero, the search index and the audit all hang off Eloquent
 * events, and a bulk path that walked past them would be the one way of
 * changing a price that nothing downstream hears.
 *
 * Amounts are read in each product's OWN currency — the rule the list's
 * inline edit already lives by, since the screen shows no currency picker:
 * "+10%" moves the number the shop typed, and Prices derives the books from
 * it exactly as a save of the form would.
 */
class BulkEdit
{
    /** How a number changes: set it, move it by an amount, move it by a percent. */
    public const MODES = ['set', 'add', 'percent'];

    /** The steps a computed price may be rounded to, in the product's own currency. */
    public const ROUNDING = [0, 1, 10, 100];

    /** @var array{changed: int, skipped: array<string, int>} */
    protected array $result = ['changed' => 0, 'skipped' => []];

    public function __construct(protected Prices $prices) {}

    /**
     * @param  array{
     *     price?: array{mode: string, value: float}|null,
     *     list_price?: array{mode: string, value: float|null}|null,
     *     round?: int,
     *     stock?: array{mode: string, value: int}|null,
     *     availability_status?: string|null,
     *     status?: string|null,
     *     categories_add?: list<int>, categories_remove?: list<int>,
     *     labels_add?: list<int>, labels_remove?: list<int>,
     * }  $changes
     */
    public function apply(Collection $products, array $changes): void
    {
        // The warehouses module answers "is this stock locked" per product;
        // telling it the whole set first lets it answer from one query.
        if (! empty($changes['stock'])) {
            hook_action('catalog.products.listed', ['products' => $products]);
        }

        foreach ($products as $product) {
            if ($this->applyOne($product, $changes)) {
                $this->result['changed']++;
            }
        }
    }

    /** @return array{changed: int, skipped: array<string, int>} */
    public function result(): array
    {
        return $this->result;
    }

    protected function applyOne(Product $product, array $changes): bool
    {
        $data = [];

        $typed = $this->prices($product, $changes);
        if ($typed === false) {
            // A price that would go below zero: this product's prices are
            // left alone rather than clamped to a number nobody asked for.
            $this->skip('negative_price');
        } elseif ($typed !== []) {
            $data += $this->prices->columns($typed, $product->price_currency);
        }

        if (! empty($changes['stock'])) {
            if (hook_filter('catalog.product.stock_locked', false, ['product' => $product])) {
                $this->skip('stock_locked');
            } else {
                $value = (int) $changes['stock']['value'];
                $data['stock'] = $changes['stock']['mode'] === 'add' ? (int) $product->stock + $value : $value;
            }
        }

        foreach (['availability_status', 'status'] as $column) {
            if (! empty($changes[$column])) {
                $data[$column] = $changes[$column];
            }
        }

        $product->fill($data);
        $saved = $product->isDirty();
        if ($saved) {
            $product->save();
        }

        $related = $this->relations($product, 'categories', $changes)
            | $this->relations($product, 'labels', $changes);

        // A pivot row fires no event of the product's: touching it is what
        // tells the search index and the audit that this product changed.
        if ($related && ! $saved) {
            $product->touch();
        }

        return $saved || $related;
    }

    /**
     * The typed numbers to save — field => major units in the product's own
     * currency — or false when one of them would become negative.
     *
     * @return array<string, float|null>|false
     */
    protected function prices(Product $product, array $changes): array|false
    {
        $typed = [];
        $step = (int) ($changes['round'] ?? 0);

        foreach (['price', 'list_price'] as $field) {
            $change = $changes[$field] ?? null;
            if (! $change) {
                continue;
            }

            if ($change['mode'] === 'clear') {
                $typed[$field] = null;

                continue;
            }

            $value = (float) $change['value'];
            $current = $this->typedMajor($product, $field);

            if ($change['mode'] === 'set') {
                $new = $value;
            } elseif ($current === null) {
                // Nothing to move: a product with no old price keeps none.
                continue;
            } else {
                $new = $change['mode'] === 'percent' ? $current * (1 + $value / 100) : $current + $value;

                if ($step > 0) {
                    $new = round($new / $step) * $step;
                }
            }

            $new = round($new, 2);
            if ($new < 0) {
                return false;
            }

            $typed[$field] = $new;
        }

        return $typed;
    }

    /** The number the shop typed for this field, in major units — null when there is none. */
    protected function typedMajor(Product $product, string $field): ?float
    {
        $minor = $product->price_currency && $product->{$field.'_original'} !== null
            ? $product->{$field.'_original'}
            : $product->{$field};

        return $minor === null ? null : $minor / 100;
    }

    protected function relations(Product $product, string $relation, array $changes): bool
    {
        $add = array_map('intval', $changes[$relation.'_add'] ?? []);
        $remove = array_values(array_diff(array_map('intval', $changes[$relation.'_remove'] ?? []), $add));
        $changed = false;

        if ($add !== []) {
            $changed = $product->{$relation}()->syncWithoutDetaching($add)['attached'] !== [];
        }

        if ($remove !== []) {
            $changed = $product->{$relation}()->detach($remove) > 0 || $changed;
        }

        return $changed;
    }

    protected function skip(string $reason): void
    {
        $this->result['skipped'][$reason] = ($this->result['skipped'][$reason] ?? 0) + 1;
    }
}
