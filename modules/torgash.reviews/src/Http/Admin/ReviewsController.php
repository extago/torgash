<?php

namespace Torgash\Reviews\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Torgash\Reviews\Aggregates;
use Torgash\Reviews\Moderate;
use Torgash\Reviews\Models\Review;
use Torgash\Reviews\Photos;
use Torgash\Reviews\PostReply;

class ReviewsController extends Controller
{
    public function __construct(protected Aggregates $aggregates) {}

    /**
     * The queue, not the archive — and a queue that stays usable at a thousand.
     *
     * With nothing in the address the screen shows what is WAITING — the same
     * decision the enquiries inbox makes, and for the same reason: a list that
     * opens on everything makes the four rows that need a person indis-
     * tinguishable from the four hundred that do not. Around it, what a
     * moderator actually narrows by: words (the text, the author, the product
     * or its SKU), the mark, whether the shop has answered, a verified
     * purchase, photographs, one product; and an order — worst first is how a
     * shop finds the complaints.
     */
    public function index(Request $request)
    {
        $filters = $this->filters($request);

        $statusCounts = $this->query(array_merge($filters, ['status' => 'all']))
            ->selectRaw('status, count(*) as n')->groupBy('status')
            ->pluck('n', 'status')->map(fn ($n) => (int) $n);

        $reviews = $this->sorted($this->query($filters), $filters['sort'])
            ->with(['product', 'ratings.criterion', 'replies', 'media'])
            ->paginate(30)
            ->withQueryString();

        return view('torgash.reviews::admin.index', [
            'reviews' => $reviews,
            'filters' => $filters,
            'status' => $filters['status'],
            'statusCounts' => $statusCounts->all() + ['all' => (int) $statusCounts->sum()],
            'pendingCount' => Review::reviews()->waiting()->count(),
            'foundCount' => $reviews->total(),
            'product' => $filters['product'] ? \Torgash\Catalog\Models\Product::find($filters['product']) : null,
        ]);
    }

    /** @return array<string, mixed> */
    protected function filters(Request $request): array
    {
        $status = (string) $request->input('status', Review::PENDING);

        return [
            'status' => in_array($status, [...Review::STATUSES, 'all'], true) ? $status : Review::PENDING,
            'q' => trim((string) $request->input('q', '')),
            'rating' => in_array($request->input('rating'), ['1', '2', '3', '4', '5', 'low', 'high'], true) ? (string) $request->input('rating') : '',
            'answered' => in_array($request->input('answered'), ['yes', 'no'], true) ? (string) $request->input('answered') : '',
            'verified' => $request->boolean('verified'),
            'photos' => $request->boolean('photos'),
            'product' => (int) $request->input('product', 0) ?: null,
            'sort' => in_array($request->input('sort'), ['old', 'low', 'high', 'helpful'], true) ? (string) $request->input('sort') : 'new',
        ];
    }

    /**
     * The one reading of the filters — the list, the tab counts and "every
     * review found" in the bulk bar all ask it, so what a bulk act touches is
     * exactly what the screen said it found.
     *
     * @param  array<string, mixed>  $f
     */
    protected function query(array $f)
    {
        return Review::reviews()
            ->when($f['status'] !== 'all', fn ($q) => $q->where('status', $f['status']))
            ->when($f['product'], fn ($q) => $q->where('product_id', $f['product']))
            ->when($f['verified'], fn ($q) => $q->where('verified', true))
            ->when($f['photos'], fn ($q) => $q->whereHas('media', fn ($m) => $m->where('collection_name', Photos::COLLECTION)))
            ->when($f['rating'] !== '', function ($q) use ($f) {
                match ($f['rating']) {
                    'low' => $q->where('rating', '<', 3),
                    'high' => $q->where('rating', '>=', 4),
                    // One star is 1.0–1.9, as the stars draw it.
                    default => $q->where('rating', '>=', (int) $f['rating'])->where('rating', '<', (int) $f['rating'] + 1),
                };
            })
            ->when($f['answered'] === 'yes', fn ($q) => $q->whereHas('replies', fn ($r) => $r->where('is_staff', true)))
            ->when($f['answered'] === 'no', fn ($q) => $q->whereDoesntHave('replies', fn ($r) => $r->where('is_staff', true)))
            ->when($f['q'] !== '', function ($q) use ($f) {
                $term = '%'.addcslashes($f['q'], '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('body', 'ilike', $term)
                    ->orWhere('pros', 'ilike', $term)
                    ->orWhere('cons', 'ilike', $term)
                    ->orWhere('author_name', 'ilike', $term)
                    ->orWhereHas('product', fn ($p) => $p->where('sku', 'ilike', $term)->orWhereRaw('name::text ilike ?', [$term])));
            });
    }

    protected function sorted($query, string $sort)
    {
        return match ($sort) {
            'old' => $query->oldest('id'),
            'low' => $query->orderBy('rating')->latest('id'),
            'high' => $query->orderByDesc('rating')->latest('id'),
            'helpful' => $query->orderByDesc('helpful_yes')->latest('id'),
            default => $query->latest('id'),
        };
    }

    /**
     * Several at once — the ticked ones, or EVERY review the filters found
     * ("publish all the five-star ones waiting"). Each through Moderate, so the
     * averages and the announcement are what a single click would have done.
     */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in([Review::PUBLISHED, Review::REJECTED, 'delete'])],
            'ids' => 'array',
            'ids.*' => 'integer',
            'scope' => 'nullable|in:found',
        ]);

        $query = $request->input('scope') === 'found'
            ? $this->query($this->filters($request))
            : Review::reviews()->whereIn('id', $validated['ids'] ?? []);

        $count = 0;
        $touched = [];
        $query->orderBy('id')->chunkById(200, function ($reviews) use ($validated, &$count, &$touched) {
            foreach ($reviews as $review) {
                if ($validated['action'] === 'delete') {
                    $touched[(int) $review->product_id] = true;
                    $review->delete();
                } else {
                    app(Moderate::class)->decide($review, $validated['action']);
                }
                $count++;
            }
        });

        foreach (array_keys($touched) as $productId) {
            $this->aggregates->recalculate($productId);
        }

        return back()->with('status', $validated['action'] === 'delete'
            ? trans_choice(':count review deleted.|:count reviews deleted.', $count, ['count' => $count])
            : trans_choice(':count review updated.|:count reviews updated.', $count, ['count' => $count]));
    }

    /**
     * Publish or reject — the two acts this screen exists for.
     *
     * Either way the product's average is recomputed, because both directions
     * change it: rejecting a published one-star review is exactly as much of a
     * change as publishing it was. From the list's in-place badge it answers
     * with the badge.
     */
    public function status(Request $request, Review $review)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Review::STATUSES)],
            'moderation_note' => 'nullable|string|max:1000',
        ]);

        // Through the one place a status moves, which is also where the
        // average is recomputed and the change announced — the API calls the
        // same method, so the two cannot drift.
        app(Moderate::class)->decide($review, $validated['status'], $validated['moderation_note'] ?? null);

        if ($request->header('HX-Request')) {
            return view('torgash.reviews::admin._status', ['review' => $review]);
        }

        return back()->with('status', __('Review updated.'));
    }

    /**
     * The shop's public answer, under the review it answers.
     *
     * A row in the same table with `is_staff`, exactly as the comments module
     * does it: a separate table for replies would make "show me this review
     * with what the shop said" a union query for ever. It carries no rating,
     * so it moves no average.
     *
     * Publishing a reply publishes the review it answers. Half a conversation
     * on a page — an answer to a complaint nobody can read — is worse than
     * either half alone, and a shop that does not want the complaint published
     * rejects it instead of answering it.
     */
    public function reply(Request $request, Review $review, PostReply $replies)
    {
        $validated = $request->validate(['body' => 'required|string|max:5000']);

        // Through the one place a reply is written, exactly as the storefront
        // goes: the staff mark, the notification to whoever wrote the review
        // and the announcement are not things two screens should each
        // remember.
        $replies->fromShop($review, $request->user(), $validated['body']);

        if ($review->status !== Review::PUBLISHED) {
            app(Moderate::class)->decide($review, Review::PUBLISHED);
        }

        return back()->with('status', __('Reply published.'));
    }

    /**
     * Deleting is for what should never have been stored — a real name, a
     * phone number, an address somebody typed into a public box. Rejecting is
     * for everything else, and it keeps the record of what was said.
     */
    public function destroy(Review $review)
    {
        $productId = (int) $review->product_id;

        $review->delete();
        $this->aggregates->recalculate($productId);

        return back()->with('status', __('Review deleted.'));
    }

    /**
     * Recount every product's stored mark from its published reviews.
     *
     * The average is denormalised — a row per product, moved whenever a review
     * is published, rejected or deleted — so it is a number that CAN drift, and
     * `reviews.aggregate_matches_reviews` is the statement that notices when it
     * has. A statement with nothing to press is one the shop reads twice and
     * then stops reading, so this is that button.
     *
     * Synchronous: it touches the products that HAVE reviews (or a row), which
     * is a small fraction of a catalogue, and a button that needs a queue
     * worker does nothing on most installs (the same call the nightly run makes
     * of every other check).
     */
    public function recount()
    {
        // Every product with a review or a row, a variation group once — the
        // same walk switching `share_variations` makes. A row whose last
        // review has gone is visited too, and recalculate() deletes it.
        $products = $this->aggregates->recalculateAll();

        return back()->with('status', __('Recounted :n product(s).', ['n' => $products]));
    }
}
