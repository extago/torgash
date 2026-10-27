<?php

namespace Torgash\Sku;

use App\Core\Tasks\TrackedJob;
use Illuminate\Support\Facades\DB;
use Throwable;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;

/**
 * Renumbering the products already in the shop, in the background (a shop of
 * fifty thousand products is minutes of work, and every one is a save through
 * the model so the audit keeps the old SKU).
 *
 * Two scopes, chosen on the module's screen:
 * - `fallback`: only the SKUs nobody chose — the catalogue's `P-<id>` given to
 *   a product created blank before this module was there (or while it was off);
 * - `all`: every product, the SKU typed by hand included — what a shop asks
 *   for when it starts its numbering over. The screen warns what that means
 *   for an import, 1C and a marketplace that know the old SKUs.
 * Either one optionally narrowed to a category (and everything under it).
 *
 * And a third, the whole shop only — `restart`: every SKU cleared and made
 * again from 1. Renumbering "all" in place cannot do that: the running
 * number goes on from where it was, and the SKU a product should get is
 * often still held by another product not yet renumbered, so the generator
 * skips it and the numbering comes out with holes and out of order. So the
 * running numbers are reset and every SKU is first PARKED — the old one with
 * `~~` in front, one quiet statement — which frees all of them at once; then
 * each product is given its new one through the model, with the parked
 * prefix taken off the original first, so the save (and whatever listens
 * to it) sees old → new and never the parking. A run that stops
 * (cancelled, failed) puts back every old SKU nobody has taken since;
 * the screen counts any that stay parked.
 */
class Renumber extends TrackedJob
{
    /** In front of an old SKU while `restart` runs (see above). */
    public const PARKED = '~~';

    public function __construct(
        public string $scope = 'fallback',
        public ?int $categoryId = null,
        public bool $resumed = false,
        public int $done = 0,
    ) {}

    public function label(): string
    {
        return match ($this->scope) {
            'restart' => 'Clear every SKU and number the products again',
            'all' => 'Renumber products by the SKU template',
            default => 'Give products without a chosen SKU one by the template',
        };
    }

    public function module(): ?string
    {
        return 'torgash.sku';
    }

    protected function run(): void
    {
        if ($this->scope === 'restart') {
            $this->restart();

            return;
        }

        $query = Product::query()->orderBy('id');

        if ($this->categoryId) {
            $ids = app(CategoryTree::class)->descendants($this->categoryId);
            $query->whereHas('categories', fn ($q) => $q->whereIn('m_catalog_categories.id', $ids));
        }

        if ($this->scope !== 'all') {
            $query = self::fallbackOnly($query);
        }

        $this->total((clone $query)->count());
        $assigner = app(Assigner::class);
        $done = 0;

        foreach ($query->lazyById(200) as $product) {
            $assigner->renumber($product);
            $this->progress(++$done);
        }
    }

    protected function restart(): void
    {
        if (! $this->resumed) {
            DB::table('m_sku_counters')->delete();
            Product::query()
                ->where('sku', 'not like', self::PARKED.'%')
                ->whereRaw('length(sku) <= 253')
                ->update(['sku' => DB::raw("'".self::PARKED."' || sku")]);
        }

        $this->total(Product::query()->count());
        $assigner = app(Assigner::class);
        $done = $this->done;

        try {
            foreach (self::parked(Product::query())->orderBy('id')->lazyById(200) as $product) {
                $old = substr((string) $product->sku, strlen(self::PARKED));
                $product->sku = $old;
                $product->syncOriginalAttribute('sku');

                if (! $assigner->renumber($product)) {
                    // The template made nothing (or the old SKU again): the
                    // old one back — unless another product has it by now.
                    Product::query()->whereKey($product->getKey())
                        ->update(['sku' => Product::skuTaken($old, $product->getKey()) ? 'P-'.$product->getKey() : $old]);
                }

                $this->progress(++$done);

                if ($this->timeIsUp()) {
                    $this->continueWith(new static('restart', null, true, $done));

                    return;
                }
            }
        } catch (Throwable $e) {
            self::unpark();

            throw $e;
        }
    }

    /** Products still holding a parked SKU. */
    public static function parked(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('sku', 'like', self::PARKED.'%');
    }

    /** Every parked SKU back as it was, where nobody has taken it since. */
    public static function unpark(): void
    {
        $from = strlen(self::PARKED) + 1;

        DB::statement(
            "UPDATE m_catalog_products p SET sku = substr(p.sku, {$from})
             WHERE p.sku LIKE ? AND NOT EXISTS (SELECT 1 FROM m_catalog_products q WHERE q.sku = substr(p.sku, {$from}))",
            [self::PARKED.'%'],
        );
    }

    /** Products whose SKU is the catalogue's own `P-<id>` — the same test as Product::hasFallbackSku(), in SQL. */
    public static function fallbackOnly(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('sku', 'like', 'P-%')->whereRaw("sku ~ ('^P-' || id || '(-[0-9]+)?$')");
    }
}
