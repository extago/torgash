<?php

namespace Torgash\Reviews;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Torgash\Reviews\Models\Review;
use Torgash\Reviews\Models\ReviewVote;

/**
 * "Was this review helpful?" (PLAN §32.3) — the one place a vote is cast,
 * changed and counted, the shape every writing path in this engine has.
 *
 * Three rules, each with a reason a shop feels:
 *
 * 1. **Signed in only.** A vote counted per session is one a script casts by
 *    clearing a cookie, and a helpfulness ranking that can be bought with a
 *    loop is worse than none: it would put the bought review at the top of
 *    every product page. An account is the same barrier writing a review
 *    already has.
 * 2. **One person, one answer, changeable.** The unique index is the
 *    statement; `updateOrCreate` is how a reader who pressed the wrong button
 *    fixes it. Pressing the same button twice TAKES THE VOTE BACK, because the
 *    button is the state and not a counter, and a reader who cannot un-vote
 *    has been told their answer is final by a piece of markup that does not
 *    look final.
 * 3. **Never your own review.** Not a moral point: the number exists to tell
 *    other readers which reviews helped them, and the author is the one person
 *    it cannot be evidence from.
 *
 * The counters on the review are recomputed from the rows rather than
 * incremented, so a vote that arrives twice (a double submit, a retry) cannot
 * drift them — the same reason `Aggregates` recomputes rather than adjusts.
 * Written with a plain query so voting never moves `updated_at`: a review is
 * not edited by being found useful.
 */
class Votes
{
    /**
     * Cast, change or take back a vote, and answer with what the reader now
     * holds: true, false, or null for "no longer voted".
     */
    public function cast(Review $review, User $user, bool $helpful): ?bool
    {
        if ((int) $review->user_id === (int) $user->id || $review->status !== Review::PUBLISHED) {
            return $this->mine($review, $user);
        }

        $existing = ReviewVote::where('review_id', $review->id)->where('user_id', $user->id)->first();

        if ($existing && (bool) $existing->helpful === $helpful) {
            $existing->delete();
            $now = null;
        } else {
            ReviewVote::updateOrCreate(
                ['review_id' => $review->id, 'user_id' => $user->id],
                ['helpful' => $helpful],
            );
            $now = $helpful;
        }

        $this->recount($review);

        return $now;
    }

    /** What this reader already said about this review, if anything. */
    public function mine(Review $review, ?User $user): ?bool
    {
        if (! $user) {
            return null;
        }

        $vote = ReviewVote::where('review_id', $review->id)->where('user_id', $user->id)->value('helpful');

        return $vote === null ? null : (bool) $vote;
    }

    /**
     * The votes this reader has already cast across a whole PAGE of reviews,
     * in one query — the `catalog.products.listed` idiom applied to a list
     * that draws a pair of buttons per row.
     *
     * @param  iterable<Review>  $reviews
     * @return array<int, bool>
     */
    public function mineFor(iterable $reviews, ?User $user): array
    {
        $ids = collect($reviews)->pluck('id')->filter()->all();

        if (! $user || $ids === []) {
            return [];
        }

        return ReviewVote::whereIn('review_id', $ids)
            ->where('user_id', $user->id)
            ->pluck('helpful', 'review_id')
            ->map(fn ($value) => (bool) $value)
            ->all();
    }

    /** Counted from the rows, never adjusted, and without touching `updated_at`. */
    public function recount(Review $review): void
    {
        $counts = ReviewVote::where('review_id', $review->id)
            ->selectRaw('count(*) filter (where helpful) as yes, count(*) filter (where not helpful) as no')
            ->first();

        $yes = (int) ($counts->yes ?? 0);
        $no = (int) ($counts->no ?? 0);

        DB::table('m_reviews_reviews')
            ->where('id', $review->id)
            ->update(['helpful_yes' => $yes, 'helpful_no' => $no]);

        $review->forceFill(['helpful_yes' => $yes, 'helpful_no' => $no])->syncOriginal();
    }
}
