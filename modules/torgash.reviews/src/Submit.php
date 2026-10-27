<?php

namespace Torgash\Reviews;

use App\Core\Support\FormGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Models\Product;
use Torgash\Reviews\Models\Review;

/**
 * The one place a review is created — the shape `Submit` has in the enquiries
 * and comments modules, and `PlaceOrder` has for an order.
 *
 * What it owns that no caller may repeat: the eligibility rules, the guard,
 * the moderation decision, the verified-purchase mark, the per-criterion
 * scores and the average computed from them, and the announcement. A second
 * door (the API is the obvious next one) gets all of it for free.
 */
class Submit
{
    /** The form's name, so the rate limiter counts it apart from the other public forms. */
    public const FORM = 'review';

    public function __construct(
        protected FormGuard $guard,
        protected Eligibility $eligibility,
        protected Criteria $criteria,
        protected Aggregates $aggregates,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Request $request, Product $product): Review
    {
        $user = $request->user();

        if ($refusal = $this->eligibility->refusal($user, $product)) {
            throw ValidationException::withMessages(['review' => $refusal]);
        }

        // Honeypot, form age, rate limit and whatever a module adds through
        // core.form.check. A review form is behind a login, so this is the
        // second lock rather than the first — which is why it is still here:
        // accounts are cheap to make in bulk, and this is what makes using
        // them slow.
        if ($stop = $this->guard->check($request, \App\Core\Access\AccountContact::label($user), self::FORM)) {
            throw ValidationException::withMessages(['review' => $stop['message'] ?? __('Please try again in a moment.')]);
        }

        $criteria = $this->criteria->forProduct($product);

        $data = Validator::make($request->all(), [
            'body' => 'nullable|string|max:5000',
            'pros' => 'nullable|string|max:2000',
            'cons' => 'nullable|string|max:2000',
            // The overall mark, asked for directly when the shop has no
            // criteria at all. With criteria it is computed, never posted:
            // a client that could send both would be sending two answers to
            // one question.
            'rating' => [$criteria->isEmpty() ? 'required' : 'nullable', 'integer', 'min:1', 'max:5'],
            'recommend' => ['nullable', Rule::in(Review::RECOMMEND)],
            'criteria' => 'nullable|array',
            'criteria.*' => 'nullable|integer|min:1|max:5',
        ])->validate();

        $scores = $this->scores($criteria, (array) ($data['criteria'] ?? []));

        // Nothing said and nothing scored is not a review. Said separately
        // from the validator because "any of these four" is not a rule a field
        // list can express, and the message has to name what to do.
        if ($scores === [] && ($data['rating'] ?? null) === null) {
            throw ValidationException::withMessages(['review' => __('Please rate the product.')]);
        }

        $rating = $scores === []
            ? (float) $data['rating']
            : round(array_sum($scores) / count($scores), 1);

        $review = Review::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            // A snapshot: the review outlives the account's current name, and
            // a renamed customer must not silently re-sign what they wrote.
            'author_name' => $user->name ?: __('Customer'),
            'body' => $data['body'] ?? null,
            'pros' => setting('torgash.reviews', 'pros_cons', true) ? ($data['pros'] ?? null) : null,
            'cons' => setting('torgash.reviews', 'pros_cons', true) ? ($data['cons'] ?? null) : null,
            'rating' => $rating,
            'recommend' => setting('torgash.reviews', 'recommendation', true) ? ($data['recommend'] ?? null) : null,
            'status' => setting('torgash.reviews', 'premoderate', true) ? Review::PENDING : Review::PUBLISHED,
            // Worked out now and frozen: an order cancelled next year does not
            // retract the fact that this person had bought the thing when they
            // wrote about it.
            'verified' => $this->eligibility->hasBought($user, $product),
            'storefront_id' => current_storefront()?->id,
            'locale' => app()->getLocale(),
        ]);

        foreach ($scores as $criterionId => $value) {
            $review->ratings()->create(['criterion_id' => $criterionId, 'value' => $value]);
        }

        // After the row exists, because a photograph hangs off it. A refused
        // file is a WARNING and never a rejection — the same call the
        // enquiries module makes, and for the same reason: somebody who wrote
        // four paragraphs and attached a 30 MB picture must keep the four
        // paragraphs.
        if ($refused = app(Photos::class)->store($review, $request->file('photos'))) {
            session()->flash('warning', implode(' ', array_unique($refused)));
        }

        $this->guard->record($request, \App\Core\Access\AccountContact::label($user), self::FORM);

        // Only a published review moves the number on the card; a pending one
        // is counted the moment a moderator lets it through.
        if ($review->status === Review::PUBLISHED) {
            $this->aggregates->recalculate((int) $product->id);
        }

        Notify::about($review);

        hook_action('reviews.review.created', ['review' => $review, 'product' => $product]);

        return $review;
    }

    /**
     * The scores that belong to THIS product's criteria, keyed by id.
     *
     * Posted values for a criterion this product is not rated by are dropped
     * rather than stored: the form is rebuilt per product, so anything else is
     * a stale page or a hand-made request, and either way it would put "Sound"
     * on a jumper.
     *
     * @param  \Illuminate\Support\Collection<int, \Torgash\Reviews\Models\Criterion>  $criteria
     * @param  array<int|string, mixed>  $posted
     * @return array<int, int>
     */
    protected function scores($criteria, array $posted): array
    {
        $out = [];

        foreach ($criteria as $criterion) {
            $value = (int) ($posted[$criterion->id] ?? 0);

            if ($value >= 1 && $value <= 5) {
                $out[(int) $criterion->id] = $value;
            }
        }

        return $out;
    }
}
