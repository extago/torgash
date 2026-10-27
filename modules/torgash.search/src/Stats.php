<?php

namespace Torgash\Search;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Support\Popularity;
use Throwable;

/**
 * What customers search for, counted — and what they choose from it.
 *
 * A SEARCH is counted once per visitor per query per day: the results page
 * (its first page — the next ones are the same search), or a product opened
 * straight from the live window, whose query was just as real though no
 * results page was ever asked for. Typing is not searching: the window's
 * questions while somebody types "н", "но", "ноу" are never counted. Nothing
 * from what looks like a crawler (the catalogue's own test, Popularity).
 *
 * A CLICK is a product page opened while that product was on screen as an
 * answer — the window or the results page — within half an hour. Which
 * answers were on screen is kept in the visitor's session (the last few
 * lists), so no link carries a tracking parameter and a product opened from
 * a category is never counted. Each product counts once per list.
 *
 * Stored anonymously, per query per day (see the stats migration); the
 * clicks also per query per PRODUCT, which is what lifts a product for that
 * query (`ClicksRetriever`). Recording never breaks a page: a failure is
 * logged and the answer goes out.
 */
class Stats
{
    protected const COUNTED = 'torgash.search.counted';

    protected const SHOWN = 'torgash.search.shown';

    /** How long an answer on screen can still be clicked, seconds. */
    public const CLICK_WINDOW = 1800;

    public static function enabled(): bool
    {
        return (bool) setting('torgash.search', 'stats_enabled');
    }

    /** The key a query is counted under: its words, as the engine reads them. */
    public static function key(SearchQuery $query): string
    {
        return mb_substr(implode(' ', $query->words), 0, 200);
    }

    /**
     * A search was made and these products were shown for it.
     *
     * @param  list<int>  $shownIds  what is on screen (or about to be)
     */
    public function searched(SearchResult $result, array $shownIds, Request $request): void
    {
        $this->remember($result, $shownIds, $request, counted: $this->count($result, $request));
    }

    /**
     * The live window showed these — not a search yet, but a click on one of
     * them makes it one.
     *
     * @param  list<int>  $shownIds
     */
    public function shown(SearchResult $result, array $shownIds, Request $request): void
    {
        $this->remember($result, $shownIds, $request, counted: false);
    }

    /** A product page was opened. If it was an answer on screen, that is a click. */
    public function opened(int $productId, Request $request): void
    {
        if (! self::enabled() || ! $request->hasSession()) {
            return;
        }

        $lists = (array) $request->session()->get(self::SHOWN, []);

        foreach (array_reverse(array_keys($lists)) as $i) {
            $list = $lists[$i];

            if (($list['at'] ?? 0) < time() - self::CLICK_WINDOW || ! in_array($productId, (array) ($list['ids'] ?? []), true)) {
                continue;
            }

            // Once per list: coming back to the page is not choosing again.
            $lists[$i]['ids'] = array_values(array_diff($list['ids'], [$productId]));
            $request->session()->put(self::SHOWN, $lists);

            if (! ($list['counted'] ?? false)) {
                $this->countKey((string) $list['q'], (int) ($list['total'] ?? 0), $request);
            }

            $this->safely(function () use ($list, $productId) {
                DB::statement(
                    'insert into m_search_stats (query, day, clicks) values (?, current_date, 1)
                     on conflict (query, day) do update set clicks = m_search_stats.clicks + 1',
                    [$list['q']],
                );
                DB::statement(
                    'insert into m_search_clicks (query, product_id, clicks, last_at) values (?, ?, 1, now())
                     on conflict (query, product_id) do update set clicks = m_search_clicks.clicks + 1, last_at = now()',
                    [$list['q'], $productId],
                );
            });

            return;
        }
    }

    /**
     * Queries people search for that begin with what is being typed (or have
     * a word that does), most searched first — only ones that FIND something:
     * suggesting a dead end is worse than suggesting nothing.
     *
     * @return list<string>
     */
    public function popular(?SearchQuery $typed, int $limit, int $days = 30): array
    {
        if (! self::enabled() || $limit <= 0) {
            return [];
        }

        $min = max(1, (int) setting('torgash.search', 'popular_min_searches'));
        $query = DB::table('m_search_stats')
            ->where('day', '>=', now()->subDays($days)->toDateString())
            ->groupBy('query')
            ->havingRaw('sum(searches) >= ?', [$min])
            ->havingRaw('sum(searches) > sum(zero)')
            ->orderByRaw('sum(searches) desc')
            ->orderBy('query')
            ->limit($limit);

        if ($typed !== null) {
            $key = self::key($typed);

            if ($key === '') {
                return [];
            }

            $like = addcslashes($key, '\\%_');
            $query->where(fn ($q) => $q->where('query', 'like', $like.'%')->orWhere('query', 'like', '% '.$like.'%'))
                ->where('query', '!=', $key);
        }

        return $query->pluck('query')->all();
    }

    /** @return array<int, int> product id => clicks, for exactly this query */
    public function clicksFor(SearchQuery $query): array
    {
        return DB::table('m_search_clicks')->where('query', self::key($query))
            ->pluck('clicks', 'product_id')->map(fn ($n) => (int) $n)->all();
    }

    /** Forget what is older than the shop keeps. */
    public function prune(): void
    {
        $days = (int) setting('torgash.search', 'stats_keep_days');

        if ($days <= 0) {
            return;
        }

        DB::table('m_search_stats')->where('day', '<', now()->subDays($days)->toDateString())->delete();
        DB::table('m_search_clicks')->where('last_at', '<', now()->subDays($days))->delete();
    }

    /** @param  list<int>  $shownIds */
    protected function remember(SearchResult $result, array $shownIds, Request $request, bool $counted): void
    {
        $key = self::key($result->query);

        if (! self::enabled() || $key === '' || ! $request->hasSession() || $this->isBot($request)) {
            return;
        }

        $lists = (array) $request->session()->get(self::SHOWN, []);
        $last = end($lists);

        // The window asks again with every pause in typing, and a later batch
        // of the same answer is the same list, grown.
        if ($last && $last['q'] === $key && $last['at'] >= time() - self::CLICK_WINDOW) {
            $lists[array_key_last($lists)]['ids'] = array_slice(array_values(array_unique([...$last['ids'], ...$shownIds])), 0, 200);
            $lists[array_key_last($lists)]['counted'] = $last['counted'] || $counted;
            $lists[array_key_last($lists)]['at'] = time();
        } else {
            $lists[] = ['q' => $key, 'ids' => array_slice(array_values($shownIds), 0, 200), 'total' => $result->total(), 'counted' => $counted, 'at' => time()];
        }

        $request->session()->put(self::SHOWN, array_slice($lists, -5));
    }

    /** Counts the search once per visitor per query per day; answers whether it is counted now. */
    protected function count(SearchResult $result, Request $request): bool
    {
        $key = self::key($result->query);

        if (! self::enabled() || $key === '' || ! $request->hasSession() || $this->isBot($request)) {
            return false;
        }

        return $this->countKey($key, $result->total(), $request);
    }

    protected function countKey(string $key, int $found, Request $request): bool
    {
        $counted = (array) $request->session()->get(self::COUNTED, []);
        $today = now()->toDateString();

        if (($counted[$key] ?? null) === $today) {
            return true;
        }

        $counted[$key] = $today;
        $request->session()->put(self::COUNTED, array_slice($counted, -100, null, true));

        $this->safely(fn () => DB::statement(
            'insert into m_search_stats (query, day, searches, zero, found) values (?, current_date, 1, ?, ?)
             on conflict (query, day) do update set
                searches = m_search_stats.searches + 1,
                zero = m_search_stats.zero + excluded.zero,
                found = excluded.found',
            [$key, $found === 0 ? 1 : 0, $found],
        ));

        return true;
    }

    protected function isBot(Request $request): bool
    {
        return app(Popularity::class)->isBot($request);
    }

    protected function safely(callable $write): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
