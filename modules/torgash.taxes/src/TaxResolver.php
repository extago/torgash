<?php

namespace Torgash\Taxes;

use Illuminate\Support\Facades\DB;
use Torgash\Taxes\Models\Tax;

/**
 * Which taxes apply to what, memoised for one request.
 *
 * Registered as a singleton rather than kept in statics: a static outlives the
 * request under a persistent worker and would hand the next visitor the tax
 * table as it was when the worker booted. Same reason as Currencies and
 * PriceTypes in core.
 *
 * The tax table itself is read whole and once — a handful of rows consulted
 * for every line of every cart. The per-product overrides are fetched in one
 * query for a whole announced page, the same lazy prime() idiom the warehouses
 * module uses for stock: a grid that never asks about tax pays nothing.
 */
class TaxResolver
{
    /** @var array<int, Tax>|null id => tax */
    protected ?array $all = null;

    /** @var array<int, list<Tax>> product_id => taxes, once answered */
    protected array $memo = [];

    /** @var array<int, true> ids announced by prime() but not looked up yet */
    protected array $pending = [];

    /** @return list<Tax> */
    public function forProduct(int $productId): array
    {
        if (isset($this->memo[$productId])) {
            return $this->memo[$productId];
        }

        // Everything announced so far plus the product actually being asked
        // about, in one query.
        $ids = array_keys($this->pending + [$productId => true]);
        $this->pending = [];

        $this->load($ids);

        return $this->memo[$productId];
    }

    /** @return list<Tax> */
    public function forShipping(): array
    {
        return array_values(array_filter($this->all(), fn (Tax $tax) => $tax->applies_to_shipping));
    }

    /** @return list<Tax> */
    public function defaults(): array
    {
        return array_values(array_filter($this->all(), fn (Tax $tax) => $tax->applies_by_default));
    }

    /** @return array<int, Tax> */
    public function all(): array
    {
        return $this->all ??= Tax::query()
            ->where('enabled', true)
            ->orderBy('position')
            ->get()
            ->keyBy('id')
            ->all();
    }

    /** True when any tax is configured at all, so callers can skip the work. */
    public function active(): bool
    {
        return $this->all() !== [];
    }

    /**
     * Announces a page's product set. Deliberately does NOT query — a listing
     * that never asks about tax would otherwise pay for a lookup nobody reads;
     * the first forProduct() that misses fetches the lot.
     *
     * @param  iterable<int>  $productIds
     */
    public function prime(iterable $productIds): void
    {
        foreach ($productIds as $id) {
            $id = (int) $id;

            if (! isset($this->memo[$id])) {
                $this->pending[$id] = true;
            }
        }
    }

    /**
     * Answers for every id asked about, including the ones with no override
     * rows — those get the defaults memoised, so a product without a custom
     * tax is not re-queried on every line it appears in.
     *
     * @param  list<int>  $ids
     */
    protected function load(array $ids): void
    {
        $rows = DB::table('m_taxes_product_tax')->whereIn('product_id', $ids)->get();

        $found = [];

        foreach ($rows as $row) {
            // intval, not a strict compare: pdo_pgsql hands integer columns
            // back as strings.
            $found[(int) $row->product_id][] = (int) $row->tax_id;
        }

        foreach ($ids as $id) {
            $this->memo[$id] = isset($found[$id])
                ? array_values(array_filter(
                    array_map(fn (int $taxId) => $this->all()[$taxId] ?? null, $found[$id]),
                ))
                : $this->defaults();
        }
    }
}
