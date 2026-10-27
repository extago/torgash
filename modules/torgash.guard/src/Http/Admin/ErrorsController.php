<?php

namespace Torgash\Guard\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Torgash\Guard\Models\ErrorGroup;

/**
 * The screen the whole first part exists for.
 *
 * Unresolved first and most recent first inside that, because the question
 * somebody opens this with is "is anything broken right now", not "what has
 * ever been broken".
 *
 * Three tabs: still open, open but the code it happened in has been touched
 * since (probably fixed — look at these first and mark them), dealt with. The
 * middle one is a question about FILES, so it is answered in PHP over the open
 * rows — there are dozens, not thousands, and a list that has thousands open
 * has a bigger problem than paging.
 */
class ErrorsController extends Controller
{
    public const TABS = ['open', 'changed', 'resolved'];

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $open = $this->search(ErrorGroup::open(), $filters)->orderByDesc('last_seen_at')->limit(1000)->get();
        $changed = $open->filter(fn (ErrorGroup $error) => $error->codeState() !== null)->values();

        $rows = match ($filters['tab']) {
            'changed' => $changed,
            'resolved' => $this->search(ErrorGroup::whereNotNull('resolved_at'), $filters)->orderByDesc('last_seen_at')->limit(1000)->get(),
            default => $open,
        };

        return view('torgash.guard::admin.errors.index', [
            'errors' => $this->page($rows, $request),
            'filters' => $filters,
            'tabCounts' => [
                'open' => $open->count(),
                'changed' => $changed->count(),
                'resolved' => $this->search(ErrorGroup::whereNotNull('resolved_at'), $filters)->count(),
            ],
            'foundIds' => $rows->pluck('id')->all(),
        ]);
    }

    public function show(ErrorGroup $error)
    {
        return view('torgash.guard::admin.errors.show', ['error' => $error]);
    }

    /**
     * "I have dealt with this."
     *
     * Hides the row rather than deleting it, and the recorder clears the stamp
     * again the next time the same thing happens — a problem that comes back
     * has to come back onto the list, or marking things resolved is just a way
     * of not looking at them.
     */
    public function resolve(ErrorGroup $error)
    {
        $error->update(['resolved_at' => now()]);

        return back()->with('status', __('Marked as dealt with. It will come back if it happens again.'));
    }

    public function reopen(ErrorGroup $error)
    {
        $error->update(['resolved_at' => null]);

        return back()->with('status', __('Back on the list.'));
    }

    /** Several at once — the ticked ones. Through the models, so the audit hears each. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['resolve', 'reopen', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $errors = ErrorGroup::whereIn('id', $validated['ids'])->get();

        match ($validated['action']) {
            'resolve' => $errors->each(fn (ErrorGroup $error) => $error->update(['resolved_at' => now()])),
            'reopen' => $errors->each(fn (ErrorGroup $error) => $error->update(['resolved_at' => null])),
            'delete' => $errors->each->delete(),
        };

        return back()->with('status', trans_choice(':count error updated.|:count errors updated.', $errors->count(), ['count' => $errors->count()]));
    }

    public function destroy(ErrorGroup $error)
    {
        $error->delete();

        return redirect()->route('admin.guard.errors.index')->with('status', __('Deleted.'));
    }

    /**
     * Clearing the list. Only what is currently shown as resolved: "delete
     * everything" on a screen whose whole job is to tell you what is broken is
     * a button for hiding from it.
     */
    public function clearResolved()
    {
        $deleted = ErrorGroup::whereNotNull('resolved_at')->delete();

        return back()->with('status', __(':n resolved errors deleted.', ['n' => $deleted]));
    }

    /** @return array{q: string, tab: string, level: string} */
    protected function filters(Request $request): array
    {
        // `resolved=1` is the address the tabs had before there were three.
        $tab = $request->boolean('resolved') ? 'resolved' : (string) $request->query('tab', 'open');

        return [
            'q' => trim((string) $request->query('q', '')),
            'tab' => in_array($tab, self::TABS, true) ? $tab : 'open',
            'level' => (string) $request->query('level', ''),
        ];
    }

    protected function search(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['level'] !== '', fn ($q) => $q->where('level', $filters['level']))
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $like = '%'.addcslashes($filters['q'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('message', 'ilike', $like)->orWhere('class', 'ilike', $like)->orWhere('file', 'ilike', $like));
            });
    }

    protected function page(Collection $rows, Request $request): LengthAwarePaginator
    {
        $perPage = 30;
        $page = max(1, (int) $request->query('page', 1));

        return (new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, [
            'path' => $request->url(),
        ]))->withQueryString();
    }
}
