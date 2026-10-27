<?php

namespace Torgash\Guard\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Guard\Audit\Auditor;
use Torgash\Guard\Audit\Revert;
use Torgash\Guard\Jobs\RevertTask;
use Torgash\Guard\Models\AuditEntry;

/**
 * The trail, read backwards.
 *
 * One screen and no others: the diff is in the row, because the question people
 * arrive with ("what happened to this order", "what did this person do on
 * Tuesday") is a question about a LIST, and a detail page for every entry would
 * mean opening thirty of them to answer it.
 *
 * There is no delete button anywhere on it, and that is the point of the
 * screen. A trail an administrator can tidy up from the admin is not a trail;
 * rows leave here exactly one way, by ageing out under the retention setting.
 */
class AuditController extends Controller
{
    public function index(Request $request, Revert $revert)
    {
        $filters = [
            'user' => (string) $request->query('user', ''),
            'action' => (string) $request->query('action', ''),
            'type' => (string) $request->query('type', ''),
            'id' => (string) $request->query('id', ''),
            'q' => trim((string) $request->query('q', '')),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'task' => (string) $request->query('task', ''),
        ];

        $entries = AuditEntry::with('user')
            ->when($filters['user'] !== '', fn ($q) => $q->where('user_id', $filters['user']))
            ->when($filters['action'] !== '', fn ($q) => $q->where('action', $filters['action']))
            // Everything one background task wrote (its row on an entry links here).
            ->when(ctype_digit($filters['task']), fn ($q) => $q->where('task_id', (int) $filters['task']))
            ->when($filters['type'] !== '', fn ($q) => $q->where('entity_type', $filters['type']))
            // Only ever together with a type: an id on its own means nothing,
            // and this pair is what "everything about this row" is made of.
            ->when($filters['type'] !== '' && $filters['id'] !== '', fn ($q) => $q->where('entity_id', $filters['id']))
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $term = '%'.$filters['q'].'%';

                $q->where(fn ($w) => $w->where('entity_title', 'ilike', $term)
                    ->orWhere('user_name', 'ilike', $term)
                    ->orWhere('entity_id', 'ilike', $term));
            })
            ->when($filters['from'] !== '', fn ($q) => $q->where('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn ($q) => $q->where('created_at', '<=', $filters['to'].' 23:59:59'))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('torgash.guard::admin.audit.index', [
            'entries' => $entries,
            'groups' => $this->runs($entries->getCollection()),
            'filters' => $filters,
            'enabled' => Auditor::enabled(),
            // Which entries get the button: decided from the entry alone, no
            // read of the changed rows — whether a field moved since is asked
            // when the button is pressed.
            'revertible' => $request->user()?->can('guard.revert')
                ? $entries->getCollection()->filter(fn (AuditEntry $entry) => $revert->offered($entry))->pluck('id')->flip()->all()
                : [],
            // What is actually in the table, rather than every model class in
            // the engine: a select offering three hundred names nobody has ever
            // changed is not a filter.
            'types' => AuditEntry::query()
                ->whereNotNull('entity_type')
                ->distinct()
                ->orderBy('entity_type')
                ->pluck('entity_type'),
            // One per PERSON: an account renamed along the way would
            // otherwise be offered twice, under both names.
            'actors' => AuditEntry::query()
                ->whereNotNull('user_id')
                ->selectRaw('user_id, (array_agg(user_name order by id desc))[1] as user_name')
                ->groupBy('user_id')
                ->orderBy('user_name')
                ->get(),
        ]);
    }

    /**
     * Put one change back (Revert): every field it can, and the reason for
     * each it cannot, said out loud — a revert that quietly did half is the
     * worst of both.
     */
    public function revert(AuditEntry $entry, Revert $revert)
    {
        $result = $revert->apply($entry);

        if ($result['gone'] ?? false) {
            return back()->with('error', __('The record no longer exists, so there is nothing to put back.'));
        }

        $reasons = [
            'kept_cut' => __('the trail kept only part of the old value'),
            'changed_since' => __('it has been changed again since'),
            'no_column' => __('the field no longer exists'),
        ];
        $skipped = collect($result['skipped'])->map(fn (string $why, string $field) => $field.' — '.($reasons[$why] ?? $why))->values()->all();

        if ($result['reverted'] === []) {
            return back()->with('error', __('Nothing was put back: :fields.', ['fields' => implode('; ', $skipped) ?: '—']));
        }

        $status = __('Put back: :fields.', ['fields' => implode(', ', $result['reverted'])]);

        if ($skipped !== []) {
            $status .= ' '.__('Left as they are: :fields.', ['fields' => implode('; ', $skipped)]);
        }

        return back()->with('status', $status);
    }

    /**
     * Everything one background task wrote, put back in the background — a
     * renumbering of the whole shop is thousands of entries.
     */
    public function revertTask(int $task)
    {
        if (! AuditEntry::where('task_id', $task)->exists()) {
            return back()->with('error', __('This task left nothing in the trail to put back.'));
        }

        if (! RevertTask::startIfIdle($task)) {
            return back()->with('error', __('Another task is already being put back — wait until it finishes.'));
        }

        return back()->with('status', __('Putting it back in the background — see Background tasks for how it went.'));
    }

    /**
     * Consecutive entries that are one act — the same person doing the same
     * thing to the same row within the same minute — folded into one line.
     * Saving an order moves it three times (items, totals, status); that is
     * one thing the manager did, and six identical lines hide the entry
     * above and below them. Only NEIGHBOURS fold: the order is time, and
     * anything else in between keeps them apart.
     *
     * @return list<list<AuditEntry>>
     */
    protected function runs($entries): array
    {
        $runs = [];
        $key = null;

        foreach ($entries as $entry) {
            $entryKey = implode('|', [$entry->entity_type, $entry->entity_id, $entry->user_id, $entry->action, $entry->created_at?->format('Y-m-d H:i')]);

            if ($entry->entity_id !== null && $entryKey === $key) {
                $runs[count($runs) - 1][] = $entry;
            } else {
                $runs[] = [$entry];
            }

            $key = $entryKey;
        }

        return $runs;
    }
}
