<?php

namespace Torgash\Guard\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Torgash\Guard\Files\Uploads;
use Torgash\Guard\Invariants\Invariants;
use Torgash\Guard\Models\Finding;

/**
 * "Is this shop sound?", on one screen.
 *
 * Every registered statement is listed whether or not it is currently broken,
 * and that is the point: a screen that only shows problems cannot tell "nothing
 * is wrong" from "nothing was ever checked", and those are the two most
 * different answers there are. So the default tab is ALL of them (broken
 * first), and the tabs' counts are the summary — "never checked" is one of them
 * on purpose.
 *
 * The verdict of a statement is worked out HERE, once, rather than in the
 * template: the tab counts, the filter and the row's icon all ask it, and three
 * copies of "what counts as broken" is how a tab says 3 over a list of 4.
 */
class InvariantsController extends Controller
{
    public const TABS = ['all', 'broken', 'unchecked', 'sound', 'hidden'];

    /** The one statement that is never run unasked (see Files\Uploads). */
    public const SELF_TEST = 'guard.php_not_executed_in_uploads';

    public function index(Request $request, Invariants $invariants)
    {
        $filters = $this->filters($request);
        $findings = $invariants->findings();
        $states = $invariants->states();

        $statements = [];

        foreach ($invariants->grouped() as $owner => $group) {
            foreach ($group['checks'] as $key => $invariant) {
                $rows = collect($findings[$key] ?? []);
                $state = $states[$key] ?? null;
                $live = $rows->whereNull('ignored_at');

                $statements[$key] = [
                    'key' => $key,
                    'owner' => $owner,
                    'group' => $group['label'],
                    'label' => Invariants::localized($invariant['label'], $key),
                    'explain' => Invariants::localized($invariant['explain'] ?? []),
                    'warning' => ($invariant['severity'] ?? 'error') === 'warning',
                    'manual' => (bool) $invariant['manual'],
                    'state' => $state,
                    'verdict' => match (true) {
                        ! $state => 'unchecked',
                        (bool) $state->error => 'failed',
                        $live->isNotEmpty() => 'broken',
                        default => 'sound',
                    },
                    'live' => $live->values(),
                    'hidden' => $rows->whereNotNull('ignored_at')->values(),
                ];
            }
        }

        // Counted over the SEARCH but across every tab, so switching tabs never
        // changes the numbers on them.
        $searched = array_filter($statements, fn (array $s) => $this->matches($s, $filters));

        $counts = [
            'all' => count($searched),
            'broken' => count(array_filter($searched, fn ($s) => in_array($s['verdict'], ['broken', 'failed'], true))),
            'unchecked' => count(array_filter($searched, fn ($s) => $s['verdict'] === 'unchecked')),
            'sound' => count(array_filter($searched, fn ($s) => $s['verdict'] === 'sound')),
            // Findings, not statements: "marked fine" is said about a row.
            'hidden' => array_sum(array_map(fn ($s) => $s['hidden']->count(), $searched)),
        ];

        $shown = array_filter($searched, fn (array $s) => match ($filters['tab']) {
            'broken' => in_array($s['verdict'], ['broken', 'failed'], true),
            'unchecked' => $s['verdict'] === 'unchecked',
            'sound' => $s['verdict'] === 'sound',
            'hidden' => $s['hidden']->isNotEmpty(),
            default => true,
        });

        // Broken first, then what has never run, then the sound ones: the order
        // a person reads this in. Grouped by owner inside that ordering.
        $rank = ['failed' => 0, 'broken' => 1, 'unchecked' => 2, 'sound' => 3];
        $groups = collect($shown)
            ->sortBy(fn ($s) => $rank[$s['verdict']])
            ->groupBy('owner')
            ->sortBy(fn ($rows) => [$rows->min(fn ($s) => $rank[$s['verdict']]), $rows->first()['group']]);

        $uploads = new Uploads;

        return view('torgash.guard::admin.invariants.index', [
            'groups' => $groups,
            'filters' => $filters,
            'counts' => $counts,
            'owners' => collect($statements)->pluck('group', 'owner')->sort(),
            'lastRun' => collect($states)->pluck('last_run_at')->filter()->max(),
            'server' => $uploads->server(),
            'snippets' => $uploads->snippets(),
        ]);
    }

    /**
     * Look now — all of them, or the one whose key was posted.
     *
     * Synchronous on purpose: the checks are capped, so this answers in about a
     * second, and putting it on the queue would make the button do nothing at
     * all on an install with no worker running.
     */
    public function run(Request $request, Invariants $invariants)
    {
        $key = $request->input('key');

        if ($key === self::SELF_TEST) {
            return $this->selfTest($invariants);
        }

        $found = $invariants->run($key ? (string) $key : null);

        return back()->with('status', $found === 0
            ? __('Checked. Nothing is wrong.')
            : __('Checked. Problems found: :n', ['n' => $found]));
    }

    /**
     * The one check that is never run unasked (see Files\Uploads): it writes a
     * probe into the uploads directory and asks the web server for it over
     * HTTP, so a nightly sweep on a server with one FastCGI worker would be
     * putting a question to itself it cannot answer until it times out.
     */
    public function selfTest(Invariants $invariants)
    {
        $found = $invariants->run(self::SELF_TEST);

        return back()->with('status', $found === 0
            ? __('The self-test passed: nothing is executed where uploads go.')
            : __('The self-test found something. See the statement below.'));
    }

    /**
     * "We know, and it is fine."
     *
     * It hides the one row, never the statement — a shop that has one genuinely
     * odd order should not stop being told about the next one. The exemption
     * lives on the finding, so it disappears the day the thing stops being
     * wrong, which is right: an exemption for something that is no longer
     * broken is a trap for whoever reads this next year.
     */
    public function ignore(Finding $finding)
    {
        $finding->update(['ignored_at' => now()]);

        return back()->with('status', __('Hidden. It will come back if it changes.'));
    }

    public function unignore(Finding $finding)
    {
        $finding->update(['ignored_at' => null]);

        return back()->with('status', __('Back on the list.'));
    }

    /** The ticked findings at once. Through the models, so the audit hears each. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['ignore', 'unignore'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $rows = Finding::whereIn('id', $validated['ids'])->get();
        $rows->each(fn (Finding $finding) => $finding->update([
            'ignored_at' => $validated['action'] === 'ignore' ? now() : null,
        ]));

        return back()->with('status', $validated['action'] === 'ignore'
            ? __('Hidden. It will come back if it changes.')
            : __('Back on the list.'));
    }

    /** @return array{q: string, tab: string, module: string} */
    protected function filters(Request $request): array
    {
        $tab = (string) $request->query('tab', 'all');

        return [
            'q' => trim((string) $request->query('q', '')),
            'tab' => in_array($tab, self::TABS, true) ? $tab : 'all',
            'module' => (string) $request->query('module', ''),
        ];
    }

    /**
     * The search reads what is on the screen: the sentence in the reader's
     * language, its key, and the findings under it — "SRV-R420" finds the
     * statement that complains about it.
     */
    protected function matches(array $statement, array $filters): bool
    {
        if ($filters['module'] !== '' && $statement['owner'] !== $filters['module']) {
            return false;
        }

        if ($filters['q'] === '') {
            return true;
        }

        $haystack = [$statement['label'], $statement['key'], $statement['explain']];

        foreach ($statement['live']->concat($statement['hidden']) as $finding) {
            $haystack[] = $finding->titleText();
            $haystack[] = (string) $finding->detailText();
        }

        return Str::contains(implode("\n", $haystack), $filters['q'], ignoreCase: true);
    }
}
