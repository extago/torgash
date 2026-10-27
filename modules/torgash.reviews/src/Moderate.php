<?php

namespace Torgash\Reviews;

use Torgash\Reviews\Models\Review;

/**
 * The one place a review's status moves (PLAN §32.3) — `Submit`'s shape for
 * the other end of a review's life.
 *
 * Extracted when the API arrived, because a status is not a column here: both
 * directions change the product's average (rejecting a published one-star
 * review is exactly as much of a change as publishing it was), both announce
 * themselves, and a caller that set the column would leave the card on the
 * product page saying a number nothing on the page adds up to. Two doors lead
 * in now — the moderation screen and the API — and a third will; each of them
 * writing its own three lines is one of them eventually writing two.
 */
class Moderate
{
    public function __construct(protected Aggregates $aggregates) {}

    /**
     * Publish, reject or send a review back to the queue.
     *
     * The note is left alone when nothing is passed: it is the moderator's own
     * record of why, and a second decision about the status is not a decision
     * to forget the first one's reason.
     */
    public function decide(Review $review, string $status, ?string $note = null): Review
    {
        if (! in_array($status, Review::STATUSES, true)) {
            return $review;
        }

        $review->update([
            'status' => $status,
            'moderation_note' => $note ?? $review->moderation_note,
        ]);

        // Always, and in both directions — see the class docblock. A reply has
        // no rating and moves no average, but recomputing for one costs a
        // query and saves a rule nobody would remember.
        $this->aggregates->recalculate((int) $review->product_id);

        hook_action('reviews.review.moderated', ['review' => $review]);

        return $review;
    }
}
