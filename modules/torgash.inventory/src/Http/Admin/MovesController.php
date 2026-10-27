<?php

namespace Torgash\Inventory\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Inventory\Models\Move;

/**
 * The stock journal (PLAN §42): every movement, newest first, narrowed by
 * product, warehouse, cause and period. Nothing here writes; a movement is
 * corrected by the next one, as in any book.
 */
class MovesController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $warehouse = $request->query('warehouse');
        $type = (string) $request->query('type', '');
        $from = $request->date('from');
        $to = $request->date('to');
        $productId = $request->integer('product') ?: null;
        $like = '%'.addcslashes($q, '%_\\').'%';

        $moves = Move::query()
            ->when($productId, fn ($query) => $query->where('product_id', $productId))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('sku', 'ilike', $like)
                ->orWhereIn('product_id', Product::query()->whereRaw('exists (select 1 from jsonb_each_text(name) as t(k, v) where t.v ilike ?)', [$like])->select('id'))))
            ->when($warehouse === 'none', fn ($query) => $query->whereNull('warehouse_id'))
            ->when(is_numeric($warehouse), fn ($query) => $query->where('warehouse_id', (int) $warehouse))
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to->endOfDay()))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        // Names for the page, one query each rather than one per row.
        $items = collect($moves->items());

        return view('torgash.inventory::admin.moves', [
            'moves' => $moves,
            'filters' => ['q' => $q, 'warehouse' => $warehouse, 'type' => $type, 'from' => $from?->toDateString(), 'to' => $to?->toDateString(), 'product' => $productId],
            'product' => $productId ? Product::find($productId) : null,
            'products' => Product::whereIn('id', $items->pluck('product_id')->unique())->get(['id', 'sku', 'name'])->keyBy('id'),
            'warehouses' => DB::table('m_warehouses')->orderBy('sort')->orderBy('id')->get(['id', 'name'])->keyBy('id'),
            'orders' => DB::table('m_checkout_orders')->whereIn('id', $items->pluck('order_id')->filter()->unique())->pluck('order_number', 'id'),
            'types' => Move::TYPES,
        ]);
    }
}
