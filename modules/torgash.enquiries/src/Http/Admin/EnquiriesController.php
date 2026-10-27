<?php

namespace Torgash\Enquiries\Http\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Torgash\Enquiries\Kinds;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\PostMessage;
use Torgash\Enquiries\Status;

/**
 * The inbox. One list for every kind, because a shop that takes callbacks AND
 * product questions has one person answering both, and two screens is how the
 * quieter one goes unread for a month.
 */
class EnquiriesController extends Controller
{
    /**
     * The tabs over the list, in the order a manager works through them. Each
     * is a set of statuses, except `awaiting`, which is a question about the
     * conversation (who spoke last) and not a status at all.
     */
    public const VIEWS = ['awaiting', 'open', Status::NEW, Status::IN_PROGRESS, Status::DONE, Status::REJECTED, 'all'];

    /**
     * The inbox opens on the QUEUE, not on the archive.
     *
     * What a manager comes here for is what nobody has dealt with yet, and a
     * year of answered callbacks piled on top of it is how the one unanswered
     * question goes unseen. So an address with no state in it means the OPEN
     * ones.
     *
     * The state is a TAB (`?view=`). The older address shapes are still read,
     * because they are in bookmarks and in the bell of an older build:
     * `status[]` + `status_set` (the tick boxes that came before the tabs — an
     * empty set with the marker meaning every state) and a bare `?status=done`.
     * A set that happens to be one of the tabs lights that tab; any other set
     * is shown as asked, with no tab lit.
     */
    public function index(Request $request)
    {
        [$view, $statuses] = $this->view($request);

        $filters = [
            'view' => $view,
            'kind' => (string) $request->query('kind', ''),
            'manager' => (string) $request->query('manager', ''),
            'q' => trim((string) $request->query('q', '')),
            // An account — the customer card links here with its own id.
            'customer' => ctype_digit((string) $request->query('customer', '')) ? (string) $request->query('customer') : '',
        ];

        // Every filter but the tab, so each tab's count answers "how many of
        // THESE are there" — a search for a surname reads across the tabs.
        $base = fn () => $this->filtered(Enquiry::query(), $filters, $request->user());

        $byStatus = $base()->selectRaw('status, count(*) as n')->groupBy('status')
            ->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $counts = [
            'awaiting' => $base()->awaitingReply()->count(),
            'open' => (int) $byStatus->only(Status::OPEN)->sum(),
            'all' => (int) $byStatus->sum(),
        ];
        foreach (Status::ALL as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        $query = $base();
        if ($view === 'awaiting') {
            $query->awaitingReply();
        } elseif ($statuses !== null) {
            $query->whereIn('status', $statuses);
        }

        $enquiries = $query->with(['manager', 'user', 'lastMessage'])
            ->withCount('messages')
            // The queue is first come, first served; everything else newest first.
            ->when($view === 'awaiting', fn ($q) => $q->oldest('id'), fn ($q) => $q->latest('id'))
            ->paginate(30)
            ->withQueryString();

        // The page's whole set, announced before a row is drawn — the same
        // contract catalog.products.listed has, and for the same reason: a kind
        // that adds something per row (a return's state) answers it in one
        // query instead of turning a page of thirty into thirty.
        hook_action('enquiries.listed', ['enquiries' => $enquiries->items()]);

        return view('torgash.enquiries::admin.index', [
            'enquiries' => $enquiries,
            'filters' => $filters,
            'counts' => $counts,
            'kinds' => Kinds::all(),
            'managers' => $this->managers(),
            'isDefault' => $view === 'open' && $filters['kind'] === '' && $filters['manager'] === '' && $filters['q'] === '' && $filters['customer'] === '',
            'filterCustomer' => $filters['customer'] !== '' ? User::find((int) $filters['customer']) : null,
        ]);
    }

    /**
     * Which tab, and the statuses it stands for (null = no status filter).
     *
     * @return array{0: string, 1: list<string>|null}
     */
    protected function view(Request $request): array
    {
        $sets = [
            'open' => Status::OPEN,
            Status::NEW => [Status::NEW],
            Status::IN_PROGRESS => [Status::IN_PROGRESS],
            Status::DONE => [Status::DONE],
            Status::REJECTED => [Status::REJECTED],
        ];

        $asked = (string) $request->query('view', '');
        if (in_array($asked, self::VIEWS, true)) {
            return [$asked, $sets[$asked] ?? null];
        }

        if (! $request->has('status_set') && ! $request->has('status')) {
            return ['open', Status::OPEN];
        }

        $set = array_values(array_intersect(Status::ALL, Arr::wrap($request->query('status', []))));
        if ($set === []) {
            return ['all', null];
        }

        foreach ($sets as $key => $statuses) {
            if (array_values(array_intersect(Status::ALL, $statuses)) === $set) {
                return [$key, $statuses];
            }
        }

        return ['', $set];
    }

    /** Kind, manager, customer and search — everything the list narrows by except the tab. */
    protected function filtered($query, array $filters, ?User $me)
    {
        return $query
            ->when(($filters['customer'] ?? '') !== '', fn ($q) => $q->where('user_id', (int) $filters['customer']))
            ->when($filters['kind'] !== '', fn ($q) => $q->where('kind', $filters['kind']))
            ->when($filters['manager'] === 'me', fn ($q) => $q->where('manager_id', $me?->id))
            ->when($filters['manager'] === 'none', fn ($q) => $q->whereNull('manager_id'))
            ->when(ctype_digit($filters['manager']), fn ($q) => $q->where('manager_id', (int) $filters['manager']))
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $term = '%'.addcslashes($filters['q'], '%_\\').'%';

                $q->where(function ($w) use ($filters, $term) {
                    $w->where('name', 'ilike', $term)
                        ->orWhere('phone', 'ilike', $term)
                        ->orWhere('email', 'ilike', $term)
                        // What they typed into the form, and anything said
                        // since — "the one who asked about the red kettle".
                        ->orWhereRaw('fields::text ilike ?', [$term])
                        ->orWhereHas('messages', fn ($m) => $m->where('body', 'ilike', $term));

                    if (preg_match('/^#?(\d{1,18})$/', $filters['q'], $m)) {
                        $w->orWhere('id', (int) $m[1]);
                    }
                });
            });
    }

    /** Who can be put in charge of an enquiry: staff, by name. */
    protected function managers()
    {
        return User::staff()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Status in place, from the list. The same rules as the form beside the
     * conversation: answered_at is stamped by the first move into a finished
     * state and never moved again.
     */
    public function setStatus(Request $request, Enquiry $enquiry)
    {
        $validated = $request->validate(['status' => ['required', Rule::in(Status::ALL)]]);

        $this->moveTo($enquiry, $validated['status'])->save();

        return view('torgash.enquiries::admin._status', ['enquiry' => $enquiry]);
    }

    /** Who deals with it, in place. `me` is the person clicking. */
    public function setManager(Request $request, Enquiry $enquiry)
    {
        $enquiry->manager_id = $this->managerId($request);
        $enquiry->save();

        return view('torgash.enquiries::admin._manager', [
            'enquiry' => $enquiry->load('manager'),
            'managers' => $this->managers(),
        ]);
    }

    /** "I will take this one" — from the enquiry page, one click. */
    public function take(Request $request, Enquiry $enquiry)
    {
        $enquiry->manager_id = $request->user()->id;
        if ($enquiry->status === Status::NEW) {
            $enquiry->status = Status::IN_PROGRESS;
        }
        $enquiry->save();

        return back()->with('status', __('The enquiry is yours.'));
    }

    /**
     * Several at once, through the models — so the audit and the change hooks
     * hear each one, exactly as they would from the enquiry's own page.
     */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in([...Status::ALL, 'assign', 'close', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $enquiries = Enquiry::whereIn('id', $validated['ids'])->get();
        $manager = $validated['action'] === 'assign' ? $this->managerId($request) : null;

        foreach ($enquiries as $enquiry) {
            match ($validated['action']) {
                'delete' => $enquiry->delete(),
                'close' => $this->lock($enquiry),
                'assign' => $enquiry->forceFill(['manager_id' => $manager])->save(),
                default => $this->moveTo($enquiry, $validated['action'])->save(),
            };
        }

        return back()->with('status', $validated['action'] === 'delete'
            ? trans_choice(':count enquiry deleted.|:count enquiries deleted.', $enquiries->count(), ['count' => $enquiries->count()])
            : trans_choice(':count enquiry updated.|:count enquiries updated.', $enquiries->count(), ['count' => $enquiries->count()]));
    }

    /** A manager id from the request: an id, `me`, or empty for nobody. */
    protected function managerId(Request $request): ?int
    {
        $value = (string) $request->input('manager_id', '');

        if ($value === 'me') {
            return $request->user()->id;
        }

        if ($value === '') {
            return null;
        }

        $request->validate(['manager_id' => ['integer', 'exists:users,id']]);

        return (int) $value;
    }

    /** One status move, with the one side effect a status has. */
    protected function moveTo(Enquiry $enquiry, string $status): Enquiry
    {
        $enquiry->status = $status;

        if (! Status::isOpen($status) && ! $enquiry->answered_at) {
            $enquiry->answered_at = now();
        }

        return $enquiry;
    }

    public function show(Enquiry $enquiry)
    {
        return view('torgash.enquiries::admin.show', [
            'enquiry' => $enquiry->load(['manager', 'user', 'storefront', 'messages.user', 'lastMessage']),
            'managers' => $this->managers(),
            // The rest of what this person has asked, newest first — a
            // manager answering the third question about one order should
            // see the first two.
            'samePerson' => Enquiry::samePersonAs($enquiry)->with('lastMessage')->latest('id')->limit(10)->get(),
            // "Next" is the queue's own order — the oldest one still owed an
            // answer — so working through the inbox is one button, not a
            // trip back to the list after every reply.
            'next' => Enquiry::awaitingReply()->whereKeyNot($enquiry->id)->oldest('id')->first(['id']),
            'queueSize' => Enquiry::awaitingReply()->count(),
        ]);
    }

    /**
     * The shop's half of the conversation.
     *
     * A separate form from the one below, and the reason is the difference
     * between the two acts: this one is addressed to the customer — and is
     * what gets emailed to them once §29.4/3 lands — while that one is the shop
     * talking to itself (a state, an owner, a private note). Everything that follows from a reply — the
     * timestamp, the state, the owner, the hook — belongs to PostMessage, not
     * to this controller, because the account screen and the API do the same
     * thing and must not each grow their own version of it.
     */
    public function reply(Request $request, Enquiry $enquiry, PostMessage $messages)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $messages->fromStaff($enquiry, $request->user(), $validated['body'], $request->file('attachments'));

        // With nobody to read it the same row is a line in the shop's call
        // log (Enquiry::canBeAnswered()), and the message says which it was.
        $logged = ! $enquiry->canBeAnswered();

        if ($request->boolean('and_close')) {
            $this->lock($enquiry->refresh());

            return back()->with('status', $logged ? __('Written down and the enquiry closed.') : __('Reply sent and the conversation closed.'));
        }

        return back()->with('status', $logged ? __('Written down.') : __('Reply added.'));
    }

    /**
     * "Resolved" — one button for what is otherwise three fields and a save.
     *
     * It answers a real problem rather than saving two clicks: an enquiry that
     * is merely *done* can be written into again, and a customer coming back to
     * a year-old thread lands on a context nobody in the office remembers. This
     * ends the conversation for both sides at once.
     */
    public function close(Enquiry $enquiry)
    {
        $this->lock($enquiry);

        return back()->with('status', __('Closed. Nobody can add to this conversation.'));
    }

    protected function lock(Enquiry $enquiry): bool
    {
        $enquiry->status = Status::DONE;
        $enquiry->answered_at ??= now();
        $enquiry->locked_at = now();

        return $enquiry->save();
    }

    /**
     * And back again, because a manager will sometimes be wrong about that.
     *
     * The status is left alone: reopening says "this can be written into", not
     * "this is unresolved" — and if it is unresolved, the select beside it says
     * so in one move.
     */
    public function reopen(Enquiry $enquiry)
    {
        $enquiry->locked_at = null;

        // A rejected enquiry is locked by its status alone, so unlocking one
        // has to move the status too or the button would appear to do nothing.
        if ($enquiry->status === Status::REJECTED) {
            $enquiry->status = Status::IN_PROGRESS;
        }

        $enquiry->save();

        return back()->with('status', __('Reopened.'));
    }

    /**
     * Status, who is dealing with it, and what they wrote down — one form,
     * because they are one act ("I am taking this and here is what happened").
     *
     * answered_at is stamped by whichever comes first, a reply or the first
     * move into a finished state, and is never moved again: it answers "how
     * long did this person wait", and a status corrected twice must not keep
     * resetting that.
     */
    public function update(Request $request, Enquiry $enquiry)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Status::ALL)],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $enquiry->fill(Arr::except($validated, 'status'));
        $this->moveTo($enquiry, $validated['status'])->save();

        return back()->with('status', __('Saved.'));
    }

    public function destroy(Enquiry $enquiry)
    {
        $enquiry->delete();

        return redirect()->route('admin.enquiries.index')->with('status', __('Enquiry deleted.'));
    }
}
