<?php

namespace Torgash\ShopReviews;

use App\Models\User;
use Torgash\ShopReviews\Models\ShopReview;

/**
 * The one place a shop review's status and answer move: both change what the
 * page and the rating block print, so the numbers are recounted here and the
 * change announced here, whichever screen asked.
 */
class Moderate
{
    public function decide(ShopReview $review, string $status): ShopReview
    {
        if (! in_array($status, ShopReview::STATUSES, true) || $review->status === $status) {
            return $review;
        }

        $review->update(['status' => $status]);
        Stats::forget();

        hook_action('shopreviews.review.moderated', ['review' => $review]);

        return $review;
    }

    /**
     * The shop's public answer. Answering publishes the review: an answer to
     * something nobody can read is worse than either half — a shop that does
     * not want it shown rejects it instead. An empty answer takes it away.
     */
    public function reply(ShopReview $review, User $staff, string $text, ?string $signature = null): ShopReview
    {
        $text = trim($text);
        $first = $review->reply === null;

        $review->update($text === '' ? [
            'reply' => null, 'reply_author' => null, 'replied_by' => null, 'replied_at' => null,
        ] : [
            'reply' => $text,
            'reply_author' => trim((string) $signature) ?: (trim((string) setting('torgash.shopreviews', 'reply_signature', '')) ?: $staff->name),
            'replied_by' => $staff->id,
            'replied_at' => now(),
        ]);

        if ($text !== '') {
            $this->decide($review, ShopReview::PUBLISHED);

            if ($first) {
                Notify::replied($review);
            }
        }

        return $review;
    }

    public function delete(ShopReview $review): void
    {
        $review->delete();
        Stats::forget();
    }
}
