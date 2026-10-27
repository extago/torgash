<?php

namespace Torgash\Warehouses\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Warehouses\Models\Warehouse;
use Torgash\Warehouses\StockAllocator;

class WarehouseStockController extends Controller
{
    /**
     * One page of products with an editable quantity for this warehouse —
     * the practical way to move a batch of products onto a warehouse, versus
     * hunting them down one product-edit screen at a time.
     *
     * `here=1` narrows to what this warehouse already has a row for, which is
     * what a count of the shelf wants; the whole catalogue is for putting new
     * products on it.
     */
    public function index(Request $request, Warehouse $warehouse)
    {
        $q = trim((string) $request->query('q', ''));
        $here = $request->boolean('here');

        $products = Product::query()
            ->with('media')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('sku', 'ilike', '%'.addcslashes($q, '%_\\').'%')
                // Every language the name is written in, not two of them.
                ->orWhereRaw('name::text ilike ?', ['%'.addcslashes($q, '%_\\').'%'])))
            ->when($here, fn ($query) => $query->whereIn('id', DB::table('m_warehouse_product_stock')
                ->where('warehouse_id', $warehouse->id)->select('product_id')))
            ->orderBy('sku')
            ->paginate(30)
            ->withQueryString();

        $ids = $products->pluck('id');
        $rows = DB::table('m_warehouse_product_stock')->whereIn('product_id', $ids)->get(['warehouse_id', 'product_id', 'qty']);

        return view('torgash.warehouses::admin.stock', [
            'warehouse' => $warehouse,
            'products' => $products,
            'filters' => ['q' => $q, 'here' => $here],
            'hereCount' => DB::table('m_warehouse_product_stock')->where('warehouse_id', $warehouse->id)->count(),
            // Here, everywhere else, and whether the product is split at all —
            // a product with no row anywhere keeps its stock on the product
            // form, and its box on this screen starts EMPTY, not at 0.
            'stock' => $rows->where('warehouse_id', $warehouse->id)->pluck('qty', 'product_id'),
            'elsewhere' => $rows->where('warehouse_id', '!=', $warehouse->id)->groupBy('product_id')->map->sum('qty'),
            'split' => $rows->pluck('product_id')->unique()->flip(),
        ]);
    }

    /**
     * A count-on-hand correction, written straight to the pivot (not through
     * Product::adjustStock — this is not a sale or a return, so there is no
     * delta or allocation to record) and then every touched product's
     * aggregate resynced.
     *
     * Only what CHANGED is written. The form carries each box's starting
     * value beside it, and a box left as it was, or left empty, is not an
     * answer: writing every box on the page used to put a 0 row on every
     * product shown, which opted each into per-warehouse stock at zero and
     * wiped the stock it had — one Save emptied a page of the catalogue.
     *
     * A product getting its FIRST row here keeps what it had: that stock is
     * put in the default warehouse before this one's number is written. On the
     * default warehouse itself the typed number is the count, so it replaces.
     */
    public function update(Request $request, Warehouse $warehouse)
    {
        $typed = (array) $request->input('qty', []);
        $was = (array) $request->input('was', []);
        $changed = [];

        foreach ($typed as $productId => $qty) {
            $qty = trim((string) $qty);

            if ($qty === '' || ! preg_match('/^-?\d+$/', $qty) || $qty === trim((string) ($was[$productId] ?? ''))) {
                continue;
            }

            $changed[(int) $productId] = (int) $qty;
        }

        if ($changed === []) {
            return back()->with('status', __('Nothing changed.'));
        }

        // Through the allocator, which splits a product not split yet (its own
        // column's units go to the default warehouse first) and announces each
        // movement — a person typing a number is a correction the journal
        // keeps (PLAN §42).
        $allocator = app(StockAllocator::class);
        $existing = Product::whereIn('id', array_keys($changed))->pluck('id')->flip();

        DB::transaction(fn () => app(\Torgash\Catalog\Stock\StockMoves::class)->because(['type' => 'edit'], function () use ($allocator, $warehouse, $changed, $existing) {
            foreach ($changed as $productId => $qty) {
                if ($existing->has($productId)) {
                    $allocator->set($productId, $warehouse->id, $qty);
                }
            }
        }));

        return back()->with('status', trans_choice(':count quantity saved.|:count quantities saved.', count($changed), ['count' => count($changed)]));
    }
}
