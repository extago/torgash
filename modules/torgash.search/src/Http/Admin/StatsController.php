<?php

namespace Torgash\Search\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Search\Engine;

/**
 * What customers search for, over a period: how much, what finds nothing,
 * what is chosen. "Searched and not found" is the page's reason to exist — a
 * list of what to stock or what to rename — so each of its rows says whether
 * it finds something NOW (the shop may already have fixed it) and offers the
 * two fixes the rules screens hold, prefilled.
 */
class StatsController extends Controller
{
    public const PERIODS = [7, 30, 90, 365];

    public function index(Request $request, Engine $engine)
    {
        $days = in_array((int) $request->query('days'), self::PERIODS, true) ? (int) $request->query('days') : 30;
        $from = now()->subDays($days - 1)->toDateString();
        $base = fn () => DB::table('m_search_stats')->where('day', '>=', $from);

        $totals = $base()->selectRaw('coalesce(sum(searches), 0) as searches, coalesce(sum(zero), 0) as zero,
            coalesce(sum(clicks), 0) as clicks, count(distinct query) as queries')->first();

        $grouped = fn () => $base()->groupBy('query')->selectRaw('query, sum(searches) as searches, sum(zero) as zero,
            sum(clicks) as clicks, (array_agg(found order by day desc))[1] as found');

        $top = $grouped()->orderByRaw('sum(searches) desc')->orderBy('query')->limit(50)->get();
        $missing = $grouped()->havingRaw('sum(zero) > 0')->orderByRaw('sum(zero) desc')->orderBy('query')->limit(30)->get()
            // Asked again now: a synonym or a new product may already answer it.
            ->each(fn ($row) => $row->now = $engine->search($row->query)->total());

        $chosen = DB::table('m_search_clicks')->where('last_at', '>=', $from)
            ->groupBy('product_id')->selectRaw('product_id, sum(clicks) as clicks')
            ->orderByRaw('sum(clicks) desc')->limit(10)->get();
        $products = Product::withTrashed()->whereIn('id', $chosen->pluck('product_id'))->get()->keyBy('id');

        return view('torgash.search::admin.stats', [
            'days' => $days,
            'totals' => $totals,
            'top' => $top,
            'missing' => $missing,
            'chosen' => $chosen->map(fn ($row) => ['product' => $products->get($row->product_id), 'clicks' => (int) $row->clicks])
                ->filter(fn ($row) => $row['product'] !== null)->values(),
            'enabled' => (bool) setting('torgash.search', 'stats_enabled'),
        ]);
    }
}
