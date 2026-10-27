<?php

namespace Torgash\ShopReviews\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Torgash\ShopReviews\Models\ShopReview;
use Torgash\ShopReviews\Moderate;
use Torgash\ShopReviews\Stats;
use Torgash\ShopReviews\Submit;

class ReviewsController extends Controller
{
    public function __construct(protected Moderate $moderate) {}

    /** The queue first: with nothing in the address the screen opens on what is waiting. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);

        $statusCounts = $this->query(array_merge($filters, ['status' => 'all']))
            ->selectRaw('status, count(*) as n')->groupBy('status')
            ->pluck('n', 'status')->map(fn ($n) => (int) $n);

        $reviews = $this->query($filters)
            ->when($filters['sort'] === 'low', fn ($q) => $q->orderBy('rating'))
            ->when($filters['sort'] === 'high', fn ($q) => $q->orderByDesc('rating'))
            ->latest('created_at')->latest('id')
            ->with('ratings.criterion')
            ->paginate(30)->withQueryString();

        return view('torgash.shopreviews::admin.index', [
            'reviews' => $reviews,
            'filters' => $filters,
            'statusCounts' => $statusCounts->all() + ['all' => (int) $statusCounts->sum()],
            'foundCount' => $reviews->total(),
            'summary' => app(Stats::class)->summary(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function filters(Request $request): array
    {
        $status = (string) $request->input('status', ShopReview::PENDING);

        return [
            'status' => in_array($status, [...ShopReview::STATUSES, 'all'], true) ? $status : ShopReview::PENDING,
            'q' => trim((string) $request->input('q', '')),
            'rating' => in_array($request->input('rating'), ['low', 'high'], true) ? (string) $request->input('rating') : '',
            'answered' => in_array($request->input('answered'), ['yes', 'no'], true) ? (string) $request->input('answered') : '',
            'sort' => in_array($request->input('sort'), ['low', 'high'], true) ? (string) $request->input('sort') : 'new',
        ];
    }

    /** @param array<string, mixed> $f */
    protected function query(array $f)
    {
        return ShopReview::query()
            ->when($f['status'] !== 'all', fn ($q) => $q->where('status', $f['status']))
            ->when($f['rating'] === 'low', fn ($q) => $q->where('rating', '<', 3))
            ->when($f['rating'] === 'high', fn ($q) => $q->where('rating', '>=', 4))
            ->when($f['answered'] === 'yes', fn ($q) => $q->whereNotNull('reply'))
            ->when($f['answered'] === 'no', fn ($q) => $q->whereNull('reply'))
            ->when($f['q'] !== '', function ($q) use ($f) {
                $term = '%'.addcslashes($f['q'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('body', 'ilike', $term)->orWhere('pros', 'ilike', $term)
                    ->orWhere('cons', 'ilike', $term)->orWhere('author_name', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term)->orWhere('city', 'ilike', $term));
            });
    }

    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in([ShopReview::PUBLISHED, ShopReview::REJECTED, 'delete'])],
            'ids' => 'array',
            'ids.*' => 'integer',
            'scope' => 'nullable|in:found',
        ]);

        $query = $request->input('scope') === 'found'
            ? $this->query($this->filters($request))
            : ShopReview::whereIn('id', $validated['ids'] ?? []);

        $count = 0;
        $query->orderBy('id')->chunkById(200, function ($reviews) use ($validated, &$count) {
            foreach ($reviews as $review) {
                $validated['action'] === 'delete'
                    ? $this->moderate->delete($review)
                    : $this->moderate->decide($review, $validated['action']);
                $count++;
            }
        });

        return back()->with('status', $validated['action'] === 'delete'
            ? trans_choice(':count review deleted.|:count reviews deleted.', $count, ['count' => $count])
            : trans_choice(':count review updated.|:count reviews updated.', $count, ['count' => $count]));
    }

    public function status(Request $request, ShopReview $review)
    {
        $validated = $request->validate(['status' => ['required', Rule::in(ShopReview::STATUSES)]]);

        $this->moderate->decide($review, $validated['status']);

        if ($request->header('HX-Request')) {
            return view('torgash.shopreviews::admin._status', ['review' => $review]);
        }

        return back()->with('status', __('Review updated.'));
    }

    public function reply(Request $request, ShopReview $review)
    {
        $validated = $request->validate([
            'reply' => 'nullable|string|max:5000',
            'reply_author' => 'nullable|string|max:120',
        ]);

        $this->moderate->reply($review, $request->user(), (string) ($validated['reply'] ?? ''), $validated['reply_author'] ?? null);

        return back()->with('status', trim((string) ($validated['reply'] ?? '')) === '' ? __('The answer is removed.') : __('Answer published.'));
    }

    public function destroy(ShopReview $review)
    {
        $this->moderate->delete($review);

        return back()->with('status', __('Review deleted.'));
    }

    /**
     * A review written somewhere else — on Google, on the phone, in the old
     * shop — typed in by the staff with its own date and where it came from.
     * Published at once: the person typing it is the moderator.
     */
    public function create()
    {
        return view('torgash.shopreviews::admin.create', ['criteria' => Submit::criteria()]);
    }

    public function store(Request $request)
    {
        $criteria = Submit::criteria();

        $data = $request->validate([
            'author_name' => 'required|string|max:120',
            'city' => 'nullable|string|max:120',
            'source' => 'nullable|string|max:60',
            'written_at' => 'nullable|date',
            'body' => 'required|string|max:5000',
            'pros' => 'nullable|string|max:2000',
            'cons' => 'nullable|string|max:2000',
            'rating' => [$criteria->isEmpty() ? 'required' : 'nullable', 'integer', 'min:1', 'max:5'],
            'criteria' => 'nullable|array',
            'criteria.*' => 'nullable|integer|min:1|max:5',
            'recommend' => 'nullable|in:0,1',
            'will_return' => 'nullable|in:0,1',
        ]);

        $scores = collect((array) ($data['criteria'] ?? []))
            ->only($criteria->pluck('id')->all())
            ->map(fn ($v) => (int) $v)->filter(fn ($v) => $v >= 1 && $v <= 5);

        if ($scores->isEmpty() && empty($data['rating'])) {
            return back()->withInput()->withErrors(['rating' => __('Please rate the shop.')]);
        }

        DB::transaction(function () use ($data, $scores) {
            $at = ! empty($data['written_at']) ? Carbon::parse($data['written_at']) : now();

            $review = ShopReview::create([
                'author_name' => trim($data['author_name']),
                'city' => trim((string) ($data['city'] ?? '')) ?: null,
                'source' => trim((string) ($data['source'] ?? '')) ?: null,
                'body' => trim($data['body']),
                'pros' => trim((string) ($data['pros'] ?? '')) ?: null,
                'cons' => trim((string) ($data['cons'] ?? '')) ?: null,
                'rating' => $scores->isEmpty() ? (float) $data['rating'] : round($scores->avg(), 1),
                'recommend' => isset($data['recommend']) ? (bool) $data['recommend'] : null,
                'will_return' => isset($data['will_return']) ? (bool) $data['will_return'] : null,
                'status' => ShopReview::PUBLISHED,
                'locale' => admin_content_locale(),
                'created_at' => $at,
            ]);

            foreach ($scores as $criterionId => $value) {
                $review->ratings()->create(['criterion_id' => (int) $criterionId, 'value' => $value]);
            }
        });

        Stats::forget();

        return redirect()->route('admin.shopreviews.index', ['status' => ShopReview::PUBLISHED])->with('status', __('Review added.'));
    }
}
