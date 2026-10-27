<?php

namespace Torgash\Comments\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Comments\CommentTargets;
use Torgash\Comments\Models\Comment;

class CommentsController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);

        $statusCounts = $this->query(['status' => 'all'] + $filters)
            ->selectRaw('status, count(*) as n')->groupBy('status')
            ->pluck('n', 'status')->map(fn ($n) => (int) $n);

        $comments = $this->query($filters)
            ->with('replies')
            ->when($filters['sort'] === 'old', fn ($q) => $q->oldest('id'), fn ($q) => $q->latest('id'))
            ->paginate(30)
            ->withQueryString();

        // What each comment is about — resolved ONCE per distinct target, not
        // twice per row (the title and the link each looked it up).
        $targets = collect($comments->items())
            ->map(fn (Comment $comment) => $comment->target_type.':'.$comment->target_id)
            ->unique()
            ->mapWithKeys(function (string $key) {
                [$type, $id] = explode(':', $key, 2);

                return [$key => CommentTargets::describe($type, (int) $id)];
            });

        return view('torgash.comments::admin.index', [
            'comments' => $comments,
            'filters' => $filters,
            'statusCounts' => $statusCounts->all() + ['all' => (int) $statusCounts->sum()],
            'foundCount' => $comments->total(),
            'targets' => CommentTargets::all(),
            'described' => $targets,
        ]);
    }

    /** @return array{status: string, q: string, target_type: string, answered: string, sort: string} */
    protected function filters(Request $request): array
    {
        $status = (string) $request->input('status', Comment::PENDING);
        $type = (string) $request->input('target_type', '');

        return [
            // The queue is what the screen opens on — "waiting" is its point.
            'status' => in_array($status, [...Comment::STATUSES, 'all'], true) ? $status : Comment::PENDING,
            'q' => trim((string) $request->input('q', '')),
            'target_type' => $type !== '' && CommentTargets::exists($type) ? $type : '',
            'answered' => in_array($request->input('answered'), ['yes', 'no'], true) ? (string) $request->input('answered') : '',
            'sort' => $request->input('sort') === 'old' ? 'old' : 'new',
        ];
    }

    /**
     * The one reading of the filters — the list, the tab counts and "every
     * comment found" in the bulk bar all ask it, so what a bulk act touches is
     * exactly what the screen said it found. Top-level comments only: replies
     * are the shop's own, published as they are written.
     */
    protected function query(array $f)
    {
        return Comment::query()->whereNull('parent_id')
            ->when($f['status'] !== 'all', fn ($q) => $q->where('status', $f['status']))
            ->when($f['target_type'] !== '', fn ($q) => $q->where('target_type', $f['target_type']))
            ->when($f['answered'] === 'yes', fn ($q) => $q->whereHas('replies', fn ($r) => $r->where('is_staff', true)))
            ->when($f['answered'] === 'no', fn ($q) => $q->whereDoesntHave('replies', fn ($r) => $r->where('is_staff', true)))
            ->when($f['q'] !== '', function ($q) use ($f) {
                $term = '%'.addcslashes($f['q'], '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('body', 'ilike', $term)
                    ->orWhere('author_name', 'ilike', $term)
                    ->orWhere('author_email', 'ilike', $term));
            });
    }

    /**
     * Several at once — the ticked ones, or EVERY comment the filters found
     * ("publish everything waiting on this page's article"). Deleting takes the
     * replies with it (the key cascades).
     */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in([Comment::APPROVED, Comment::REJECTED, 'delete'])],
            'ids' => 'array',
            'ids.*' => 'integer',
            'scope' => 'nullable|in:found',
        ]);

        $query = $request->input('scope') === 'found'
            ? $this->query($this->filters($request))
            : Comment::query()->whereNull('parent_id')->whereIn('id', $validated['ids'] ?? []);

        $count = 0;
        $query->orderBy('id')->chunkById(200, function ($comments) use ($validated, &$count) {
            foreach ($comments as $comment) {
                $validated['action'] === 'delete'
                    ? $comment->delete()
                    : $comment->update(['status' => $validated['action']]);
                $count++;
            }
        });

        return back()->with('status', $validated['action'] === 'delete'
            ? trans_choice(':count comment deleted.|:count comments deleted.', $count, ['count' => $count])
            : trans_choice(':count comment updated.|:count comments updated.', $count, ['count' => $count]));
    }

    /** Approve or reject. The two acts the screen exists for; in place from the list. */
    public function status(Request $request, Comment $comment)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Comment::STATUSES)],
        ]);

        $comment->update(['status' => $validated['status']]);

        if ($request->header('HX-Request')) {
            return view('torgash.comments::admin._status', ['comment' => $comment]);
        }

        return back()->with('status', __('Comment updated.'));
    }

    /**
     * The shop's own answer, under the comment it answers. Written as an
     * ordinary comment with `is_staff` — a separate table for staff replies
     * would make "show me this conversation" a union query for ever — and
     * published straight away, because the person publishing it is the
     * moderator.
     */
    public function reply(Request $request, Comment $comment)
    {
        $validated = $request->validate(['body' => 'required|string|max:5000']);

        Comment::create([
            'target_type' => $comment->target_type,
            'target_id' => $comment->target_id,
            'parent_id' => $comment->parent_id ?: $comment->id,
            'user_id' => $request->user()?->id,
            'author_name' => $request->user()?->name ?: __('Shop'),
            'body' => $validated['body'],
            'status' => Comment::APPROVED,
            'is_staff' => true,
            'storefront_id' => $comment->storefront_id,
        ]);

        // An answered comment is one the shop stands behind: publishing the
        // reply and leaving the question in the queue would put half a
        // conversation on the page.
        if ($comment->status !== Comment::APPROVED) {
            $comment->update(['status' => Comment::APPROVED]);
        }

        return back()->with('status', __('Reply published.'));
    }

    public function destroy(Comment $comment)
    {
        // Replies go with it: the FK cascades, which is the honest reading of
        // "delete this conversation".
        $comment->delete();

        return back()->with('status', __('Comment deleted.'));
    }
}
