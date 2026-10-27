<?php

namespace Torgash\Search\Admin;

use Illuminate\Support\Facades\DB;

/**
 * Search on the admin dashboard: what visitors looked for and did not find —
 * the list a shop acts on (a synonym, a product it does not carry yet), read
 * from the same per-day statistics the Search statistics screen shows.
 */
class DashboardWidgets
{
    public function register(): void
    {
        hook_listen('core.admin.dashboard.widgets', function (array $widgets) {
            $widgets[] = [
                'key' => 'search.not_found',
                'title' => 'Searches that found nothing',
                'icon' => 'search-x',
                'size' => 'third',
                'position' => 70,
                'can' => 'search.manage',
                'link' => ['url' => route('admin.search-engine.stats'), 'label' => 'Statistics'],
                'render' => fn () => $this->render(),
            ];

            return $widgets;
        });
    }

    protected function render(): string
    {
        $since = now()->subDays(7)->toDateString();

        $totals = DB::table('m_search_stats')->where('day', '>=', $since)
            ->selectRaw('coalesce(sum(searches), 0) as searches, coalesce(sum(zero), 0) as zero')->first();

        $rows = DB::table('m_search_stats')->where('day', '>=', $since)
            ->select('query', DB::raw('sum(zero) as zero'))
            ->groupBy('query')->havingRaw('sum(zero) > 0')
            ->orderByDesc('zero')->orderBy('query')->limit(6)->get()
            ->map(fn ($row) => [
                'label' => $row->query,
                'url' => route('admin.search-engine.index', ['q' => $row->query]),
                'value' => trans_choice('{1} :count time|[2,*] :count times', (int) $row->zero, ['count' => (int) $row->zero]),
            ])->all();

        $share = $totals->searches > 0 ? (int) round($totals->zero / $totals->searches * 100) : 0;

        return view('admin.dashboard.list', [
            'stats' => [
                ['label' => __('searches in 7 days'), 'value' => (int) $totals->searches],
                ['label' => __('found nothing'), 'value' => $share.'%', 'tone' => $share >= 20 ? 'bad' : null],
            ],
            'rows' => $rows,
            'empty' => __('Every search found something.'),
            'footer' => [['label' => __('Add a synonym'), 'url' => route('admin.search-engine.rules.index', 'synonyms')]],
        ])->render();
    }
}
