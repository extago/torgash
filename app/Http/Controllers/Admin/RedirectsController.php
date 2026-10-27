<?php

namespace App\Http\Controllers\Admin;

use App\Core\Seo\RedirectManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The screen a migration starts with.
 *
 * The table has existed since August and filled itself whenever a slug changed,
 * but nobody could see it, add a row or hand it a file — which is the one thing
 * a shop arriving from another engine needs on day one: a list of every address
 * the old shop earned, pointing at where it lives now.
 *
 * And the one thing it needs a month later: which of those addresses anybody
 * still arrives through (`hits`, counted by RedirectManager::resolve() when a
 * row is served), and the list back out as a file.
 *
 * In core, like the table and the manager: a shop has addresses whether or not
 * it has a catalogue, and the rows must not leave with an uninstall.
 */
class RedirectsController extends Controller
{
    public const SORTS = ['recent', 'used', 'unused'];

    public function __construct(protected RedirectManager $redirects) {}

    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'source' => in_array($request->query('source'), RedirectManager::SOURCES, true) ? (string) $request->query('source') : '',
            'sort' => in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'recent',
        ];

        // Grouped: a bare orWhere would walk past the source tab.
        $searched = fn () => DB::table('redirects')->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
            ->where('from_path', 'ilike', '%'.$filters['q'].'%')
            ->orWhere('to_path', 'ilike', '%'.$filters['q'].'%')));

        // Per source, over what the search leaves, so a tab never promises
        // rows the table will not show. Rows from before `source` existed are
        // counted in "All" only.
        $bySource = $searched()->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source');
        $counts = ['' => (int) $bySource->sum()];
        foreach (RedirectManager::SOURCES as $source) {
            $counts[$source] = (int) ($bySource[$source] ?? 0);
        }

        $query = $searched()->when($filters['source'] !== '', fn ($q) => $q->where('source', $filters['source']));

        match ($filters['sort']) {
            'used' => $query->orderByDesc('hits')->orderByDesc('last_hit_at'),
            'unused' => $query->where('hits', 0)->orderBy('created_at'),
            default => $query->orderByDesc('updated_at'),
        };

        return view('admin.redirects.index', [
            'rows' => $query->orderByDesc('id')->paginate(50)->withQueryString(),
            'filters' => $filters,
            'counts' => $counts,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        $saved = $this->redirects->put($validated['from_path'], $validated['to_path']);

        return $this->answer($saved, __('Redirect saved.'));
    }

    /**
     * A row corrected in place. The old address may change too, and then the
     * row it was is gone — otherwise a typo fixed in the list would leave the
     * typo answering beside the fix.
     */
    public function update(Request $request, int $id)
    {
        $row = DB::table('redirects')->find($id);
        abort_unless($row, 404);

        $validated = $this->validated($request);
        $from = $this->redirects->normalise($validated['from_path']);
        $to = $this->redirects->normalise($validated['to_path'], external: true);

        if ($from === '' || $to === '' || $from === $to) {
            return $this->answer(false, '');
        }

        DB::transaction(function () use ($row, $from, $to) {
            if ($from !== $row->from_path) {
                $this->redirects->forget((int) $row->id);
            }

            // Keeps whoever wrote it: correcting an automatic row does not
            // make it a hand-written one.
            $this->redirects->put($from, $to, $row->source ?? 'manual');
        });

        return $this->answer(true, __('Redirect saved.'));
    }

    public function destroy(int $id)
    {
        $this->redirects->forget($id);

        return redirect()->back()->with('status', __('Redirect removed.'));
    }

    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['delete', 'keep'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        // "Keep for ever": an automatic row becomes the shop's own, which the
        // daily sweep (RedirectManager::prune) never removes.
        if ($validated['action'] === 'keep') {
            $kept = DB::table('redirects')->whereIn('id', $validated['ids'])->where('source', 'auto')->update(['source' => 'manual', 'updated_at' => now()]);

            return back()->with('status', trans_choice(':count redirect will be kept for ever.|:count redirects will be kept for ever.', $kept, ['count' => $kept]));
        }

        $removed = DB::table('redirects')->whereIn('id', $validated['ids'])->delete();

        return back()->with('status', trans_choice(':count redirect removed.|:count redirects removed.', $removed, ['count' => $removed]));
    }

    /**
     * A pasted or uploaded list. Both, because a shop with ninety rows has them
     * in a spreadsheet cell and a shop with nine thousand has them in a file,
     * and asking the first one to make a file is asking it not to bother.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'nullable|file|max:5120',
            'pasted' => 'nullable|string',
        ]);

        $csv = $request->hasFile('file')
            ? (string) file_get_contents($request->file('file')->getRealPath())
            : (string) $request->input('pasted', '');

        if (trim($csv) === '') {
            return redirect()->route('admin.redirects.index')->with('error', __('Nothing to import.'));
        }

        $result = $this->redirects->import($csv);

        return redirect()->route('admin.redirects.index', ['source' => 'import'])->with('status', __(
            ':imported imported, :refused refused.',
            $result,
        ));
    }

    /**
     * The list as a file — in the shape the import reads (old, new first), so
     * it goes back into this or another shop unchanged; how often each row was
     * used rides along after them for whoever is deciding what to keep.
     */
    public function export(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['from', 'to', 'source', 'hits', 'last_hit_at'], escape: '');

            DB::table('redirects')->orderBy('id')->chunkById(1000, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [$row->from_path, $row->to_path, $row->source, $row->hits, $row->last_hit_at], escape: '');
                }
            });

            fclose($out);
        }, 'redirects-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{from_path: string, to_path: string} */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'from_path' => 'required|string|max:255',
            'to_path' => 'required|string|max:255',
        ]);
    }

    protected function answer(bool $saved, string $message)
    {
        return redirect()->back()->with(
            $saved ? 'status' : 'error',
            $saved ? $message : __('That pair cannot work: an address cannot redirect to itself.'),
        );
    }
}
