<?php

namespace Torgash\Wishlist\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Stock\AvailabilityStatuses;

/**
 * What favourites tell the SHOP (PLAN §35.3): products and numbers, never
 * people — no list, name or address is on this screen.
 *
 * - what is saved most over a period (by when each row was added), beside
 *   how many lists hold it NOW;
 * - demand the shop cannot meet: products in lists that cannot be bought
 *   right now, most-wished first — the one table here that says what to order
 *   in, and who is waiting for the letter;
 * - how many lists there are, and how many asked for each letter.
 */
class StatsController extends Controller
{
    public const PERIODS = [7, 30, 90, 365];

    public function index(Request $request, AvailabilityStatuses $statuses)
    {
        $days = in_array((int) $request->query('days'), self::PERIODS, true) ? (int) $request->query('days') : 30;
        $from = now()->subDays($days - 1)->startOfDay();

        $lists = DB::table('m_wishlist_lists')->selectRaw('
            count(*) as lists,
            count(*) filter (where user_id is not null) as accounts,
            count(*) filter (where notify_price and user_id is not null) as price_letters,
            count(*) filter (where notify_stock and user_id is not null) as stock_letters,
            count(*) filter (where share_token is not null) as shared
        ')->first();

        $items = DB::table('m_wishlist_items')->selectRaw('count(*) as items, count(*) filter (where created_at >= ?) as added', [$from])->first();

        $top = DB::table('m_wishlist_items')->where('created_at', '>=', $from)
            ->groupBy('product_id')->selectRaw('product_id, count(*) as added')
            ->orderByRaw('count(*) desc')->orderBy('product_id')->limit(20)->get();

        $unavailable = DB::table('m_wishlist_items as i')
            ->join('m_wishlist_lists as l', 'l.id', '=', 'i.list_id')
            ->whereNotIn('i.product_id', $statuses->applyOrderable(Product::query())->select('id'))
            ->groupBy('i.product_id')
            ->selectRaw('i.product_id, count(*) as lists, count(*) filter (where l.notify_stock and l.user_id is not null) as waiting')
            ->orderByRaw('count(*) desc')->orderBy('i.product_id')->limit(20)->get();

        $productIds = $top->pluck('product_id')->merge($unavailable->pluck('product_id'))->unique()->all();
        $products = Product::withTrashed()->whereIn('id', $productIds)->withCardData()->get()->keyBy('id');
        $now = DB::table('m_wishlist_items')->whereIn('product_id', $top->pluck('product_id'))
            ->groupBy('product_id')->selectRaw('product_id, count(*) as lists')->pluck('lists', 'product_id');

        $join = fn ($rows) => $rows->map(fn ($row) => (array) $row + ['product' => $products->get($row->product_id)])
            ->filter(fn ($row) => $row['product'] !== null)->values();

        return view('torgash.wishlist::admin.stats', [
            'days' => $days,
            'lists' => $lists,
            'items' => $items,
            'top' => $join($top)->map(fn ($row) => $row + ['now' => (int) ($now[$row['product_id']] ?? 0)]),
            'unavailable' => $join($unavailable),
        ]);
    }
}
