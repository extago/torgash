<?php

namespace Torgash\ShopReviews;

use App\Core\Support\FormGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Torgash\ShopReviews\Models\Criterion;
use Torgash\ShopReviews\Models\ShopReview;

/**
 * The one place a shop review is written from the storefront: who may, the
 * guard, the scores and the average made of them, moderation, the
 * verified mark, the letter and the announcement.
 *
 * A guest may write one unless the shop says accounts only (`who_writes`) —
 * the examples a shop compares itself with take a name, a town and an email.
 * What holds a guest back is the form guard (honeypot, pace, a CAPTCHA the
 * shop can switch on for this form by name) and moderation, on by default.
 */
class Submit
{
    public const FORM = 'shop_review';

    public function __construct(protected FormGuard $guard) {}

    /** Why this visitor may not write one, or null. */
    public static function refusal(?\App\Models\User $user): ?string
    {
        return ! $user && setting('torgash.shopreviews', 'who_writes', 'anyone') === 'accounts'
            ? __('Please sign in to leave a review.')
            : null;
    }

    /** @return Collection<int, Criterion> */
    public static function criteria(): Collection
    {
        return Criterion::where('enabled', true)->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * @throws ValidationException
     */
    public function handle(Request $request): ShopReview
    {
        $user = $request->user();

        if ($refusal = self::refusal($user)) {
            throw ValidationException::withMessages(['review' => $refusal]);
        }

        $identity = $user ? \App\Core\Access\AccountContact::label($user) : mb_strtolower(trim((string) $request->input('email')));

        if ($stop = $this->guard->check($request, $identity ?: null, self::FORM)) {
            throw ValidationException::withMessages(['review' => $stop['message'] ?? __('Please try again in a moment.')]);
        }

        $criteria = self::criteria();

        $data = Validator::make($request->all(), [
            'name' => [$user ? 'nullable' : 'required', 'string', 'max:120'],
            'email' => [$user ? 'nullable' : 'required', 'email', 'max:190'],
            'city' => 'nullable|string|max:120',
            'body' => 'required|string|max:5000',
            'pros' => 'nullable|string|max:2000',
            'cons' => 'nullable|string|max:2000',
            'rating' => [$criteria->isEmpty() ? 'required' : 'nullable', 'integer', 'min:1', 'max:5'],
            'criteria' => 'nullable|array',
            'criteria.*' => 'nullable|integer|min:1|max:5',
            'recommend' => 'nullable|boolean',
            'will_return' => 'nullable|boolean',
        ], [], [
            'name' => __('Your name'),
            'body' => __('Your review'),
        ])->validate();

        $scores = [];
        foreach ($criteria as $criterion) {
            $value = (int) ($data['criteria'][$criterion->id] ?? 0);

            if ($value >= 1 && $value <= 5) {
                $scores[$criterion->id] = $value;
            }
        }

        if ($scores === [] && ($data['rating'] ?? null) === null) {
            throw ValidationException::withMessages(['rating' => __('Please rate the shop.')]);
        }

        $asks = fn (string $key) => (bool) setting('torgash.shopreviews', $key, true);

        $review = DB::transaction(function () use ($user, $data, $scores, $asks, $request) {
            $review = ShopReview::create([
                'user_id' => $user?->id,
                // A snapshot, as a product review keeps one.
                'author_name' => trim((string) ($data['name'] ?? '')) ?: ($user?->name ?: __('Customer')),
                'email' => $user ? $user->email : mb_strtolower(trim((string) $data['email'])),
                'city' => $asks('ask_city') ? (trim((string) ($data['city'] ?? '')) ?: null) : null,
                'body' => trim((string) $data['body']),
                'pros' => $asks('pros_cons') ? (trim((string) ($data['pros'] ?? '')) ?: null) : null,
                'cons' => $asks('pros_cons') ? (trim((string) ($data['cons'] ?? '')) ?: null) : null,
                'rating' => $scores === [] ? (float) $data['rating'] : round(array_sum($scores) / count($scores), 1),
                'recommend' => $asks('ask_recommend') && array_key_exists('recommend', $data) ? (bool) $data['recommend'] : null,
                'will_return' => $asks('ask_return') && array_key_exists('will_return', $data) ? (bool) $data['will_return'] : null,
                'status' => $asks('premoderate') ? ShopReview::PENDING : ShopReview::PUBLISHED,
                'verified' => $user !== null && self::hasOrdered($user->id),
                'storefront_id' => current_storefront()?->id,
                'locale' => app()->getLocale(),
                'ip' => $request->ip(),
            ]);

            foreach ($scores as $criterionId => $value) {
                $review->ratings()->create(['criterion_id' => $criterionId, 'value' => $value]);
            }

            return $review;
        });

        $this->guard->record($request, $identity ?: null, self::FORM);

        if ($review->status === ShopReview::PUBLISHED) {
            Stats::forget();
        }

        Notify::about($review);

        hook_action('shopreviews.review.created', ['review' => $review]);

        return $review;
    }

    /** An account that has bought here — the checkout module's orders, when it is installed. */
    public static function hasOrdered(int $userId): bool
    {
        return Schema::hasTable('m_checkout_orders')
            && DB::table('m_checkout_orders')->where('user_id', $userId)->exists();
    }
}
