<?php

namespace Torgash\Guard\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Guard\Blocking\Blocks;
use Torgash\Guard\Blocking\Signals;
use Torgash\Guard\Models\Block;

/**
 * The screen for the part of the guard that can act.
 *
 * It opens on what is standing right now — including the addresses that are
 * only being WATCHED, because in watch mode those rows are the entire feature:
 * "this is what I would have refused, and this is how many requests that would
 * have been". A shop reads that before it turns enforcement on, which is the
 * order the whole part was designed in.
 *
 * The Release button here is a convenience and never the answer to being locked
 * out — that is `php artisan guard:unblock`, and the screen says so out loud
 * rather than assuming anybody read the docs.
 */
class BlocksController extends Controller
{
    public const TABS = ['standing', 'enforced', 'observed', 'past', 'all'];

    public function index(Request $request, Blocks $blocks)
    {
        $filters = $this->filters($request);

        // Counted over the search but across every tab, so switching tabs never
        // changes the numbers on them.
        $counts = [];
        foreach (self::TABS as $tab) {
            $counts[$tab] = $this->tab($this->search(Block::query(), $filters), $tab)->count();
        }

        $query = $this->tab($this->search(Block::query(), $filters), $filters['tab']);

        return view('torgash.guard::admin.blocks.index', [
            'blocks' => (clone $query)->orderByDesc('updated_at')->paginate(30)->withQueryString(),
            'foundIds' => (clone $query)->limit(1000)->pluck('id')->all(),
            'filters' => $filters,
            'counts' => $counts,
            'mode' => $blocks->mode(),
            // Printed beside the "block by hand" box and marked on its row: the
            // one mistake this screen makes easy is blocking the address you
            // are sitting on.
            'yourIp' => $request->ip(),
            // What a row about YOU would say: your IPv6 address is a /64 there.
            'yourSubject' => $blocks->subject((string) $request->ip()),
        ]);
    }

    /**
     * By hand.
     *
     * A manual block is ENFORCED even while the module is in watch mode, and
     * that is not an inconsistency: watch mode is a statement about the guard's
     * own judgement, not about the shop owner's. Somebody typing an address in
     * has already looked at it.
     */
    public function store(Request $request, Blocks $blocks)
    {
        $data = $request->validate([
            // An address, or an IPv6 network written the way the list writes
            // one (`2001:db8:1:2::/64`) — copied from a row, it has to be
            // accepted back.
            'ip' => ['required', 'string', function ($attribute, $value, $fail) use ($blocks) {
                $subject = $blocks->subject((string) $value);

                if (! filter_var($blocks->isNetwork($subject) ? strstr($subject, '/', true) : $subject, FILTER_VALIDATE_IP)) {
                    $fail(__('validation.ip', ['attribute' => __('Address')]));
                }
            }],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'note' => ['nullable', 'string', 'max:250'],
        ]);

        $block = $blocks->block($data['ip'], Signals::MANUAL, [
            'by_hand' => true,
            'minutes' => $data['minutes'] ?? null,
            'note' => $data['note'] ?? null,
            'user_id' => $request->user()?->getKey(),
            'user_name' => $request->user()?->name,
        ]);

        if (! $block) {
            return back()->with('status', __('That address is on the never-block list, so nothing was done.'));
        }

        if ($block->ip === $blocks->subject((string) $request->ip())) {
            return back()->with('status', __('Blocked — and that is the address you are on. You are signed in, so nothing changes for you now; use "php artisan guard:unblock" if you ever cannot get back in.'));
        }

        return back()->with('status', $blocks->isNetwork($block->ip)
            ? __('Blocked the whole network :network — an IPv6 address is one of many its owner holds.', ['network' => $block->ip])
            : __('Blocked.'));
    }

    public function release(Block $block, Blocks $blocks)
    {
        $blocks->release($block->ip);

        return back()->with('status', __('Let back in.'));
    }

    /**
     * The ticked rows at once, through the same two acts as the row buttons —
     * `Blocks` clears the rate-limit counters on release and flushes its memo,
     * which a query-builder update would skip.
     */
    public function bulk(Request $request, Blocks $blocks)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['release', 'enforce'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $rows = Block::whereIn('id', $validated['ids'])->get();

        foreach ($rows as $block) {
            $validated['action'] === 'release' ? $blocks->release($block->ip) : $blocks->enforce($block);
        }

        return back()->with('status', $validated['action'] === 'release' ? __('Let back in.') : __('Now blocked for real.'));
    }

    /** "I agree with this one" — an observed row becomes a real block. */
    public function enforce(Block $block, Blocks $blocks)
    {
        $blocks->enforce($block);

        return back()->with('status', __('Now blocked for real.'));
    }

    /** @return array{q: string, tab: string, signal: string} */
    protected function filters(Request $request): array
    {
        // `all=1` is the address the list had before it had tabs.
        $tab = $request->boolean('all') ? 'all' : (string) $request->query('tab', 'standing');

        return [
            'q' => trim((string) $request->query('q', '')),
            'tab' => in_array($tab, self::TABS, true) ? $tab : 'standing',
            'signal' => in_array($request->query('signal'), [...Signals::COUNTED, Signals::MANUAL], true) ? (string) $request->query('signal') : '',
        ];
    }

    protected function search(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['signal'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('reason', $filters['signal'])
                ->orWhereJsonContainsKey('signals->'.$filters['signal'])))
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $like = '%'.addcslashes($filters['q'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('ip', 'ilike', $like)->orWhere('note', 'ilike', $like)->orWhere('user_name', 'ilike', $like)
                    // A whole IPv6 address pasted from a log finds the /64 row it belongs to.
                    ->orWhere('ip', app(Blocks::class)->subject($filters['q'])));
            });
    }

    /**
     * "Standing" is both kinds of active row on purpose: in watch mode the
     * observed ones are the whole feature, and hiding them behind a second tab
     * would open the screen on an empty list.
     */
    protected function tab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'standing' => $query->active(),
            'enforced' => $query->active()->where('mode', Block::ENFORCED),
            'observed' => $query->active()->where('mode', Block::OBSERVED),
            'past' => $query->where(fn ($q) => $q->whereNotNull('released_at')->orWhere('until', '<=', now())),
            default => $query,
        };
    }
}
