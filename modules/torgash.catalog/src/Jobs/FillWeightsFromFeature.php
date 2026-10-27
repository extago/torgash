<?php

namespace Torgash\Catalog\Jobs;

use App\Core\Tasks\TrackedJob;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\Measurements;

/**
 * Copies the weight the resolver reads out of a characteristic into the weight
 * field itself.
 *
 * Optional by design — Measurements answers from the characteristic anyway.
 * What this buys is that the number becomes ordinary catalogue data: visible in
 * the product form, editable per product, exported by exim, and readable
 * without the setting that found it. It also means a shop can point the setting
 * at a spec, fill once, and then correct the handful of products the supplier
 * got wrong.
 *
 * Only products whose weight is empty are touched: a value an admin typed is
 * never overwritten by one a supplier's spreadsheet suggested.
 */
class FillWeightsFromFeature extends TrackedJob
{
    public function label(): string
    {
        return 'Fill product weights from a characteristic';
    }

    public function module(): ?string
    {
        return 'torgash.catalog';
    }

    protected function run(): void
    {
        $measurements = app(Measurements::class);

        $query = Product::withTrashed()->whereNull('weight');
        $this->total($query->count());

        $done = 0;
        $filled = 0;

        // Chunked rather than cursor()d: the resolver's global-feature path is
        // one query per chunk (prime), and writing inside an open cursor on the
        // same table is what makes a "why did it stop halfway" bug.
        $query->chunkById(500, function ($products) use ($measurements, &$done, &$filled) {
            $measurements->prime($products);

            foreach ($products as $product) {
                $weight = $measurements->weightOf($product);

                if ($weight !== null) {
                    // Silent write: touching timestamps on a whole catalogue
                    // would rewrite "recently changed" for every product.
                    $product->timestamps = false;
                    $product->weight = $weight;
                    $product->save();
                    $filled++;
                }

                $this->progress(++$done);
            }
        });

        $this->result(
            ['examined' => $done, 'filled' => $filled],
            ':filled of :examined products got a weight',
            ['filled' => $filled, 'examined' => $done],
        );
    }
}
