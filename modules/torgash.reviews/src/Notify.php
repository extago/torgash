<?php

namespace Torgash\Reviews;

use App\Core\Mail\Letters;
use Torgash\Reviews\Mail\ReviewLetters;
use Torgash\Reviews\Models\Review;

/**
 * The two letters this module sends by itself, through core's registry
 * (ReviewLetters, PLAN §41).
 *
 * Same argument the comments and enquiries modules make about theirs: a review
 * sitting in moderation that nobody has been told about is indistinguishable
 * from a review form that does not work, and "it works once you configure a
 * rule" costs a shop its first week. Everything beyond these — Telegram, a
 * digest, a rule that publishes five-star reviews by itself — is an automation
 * off `reviews.review.created`, announced whether or not an address is set.
 */
class Notify
{
    public static function about(Review $review): void
    {
        // The shop answering its own customer does not need announcing to the
        // shop.
        if ($review->is_staff) {
            return;
        }

        // To the addresses set on Admin → Letters, else this module's own
        // notify_email, else the storefront's contact address.
        Letters::toShop(ReviewLetters::POSTED, ['review' => $review]);
    }

    /**
     * Somebody answered a review — to the person who wrote it.
     *
     * The one letter this module sends to a CUSTOMER, and the reason is the
     * same one the enquiries module gives about its own: nobody returns to a
     * product page on the off-chance, so a public answer written for one
     * person would be read by everyone except them.
     *
     * Never to the author about their own words, and never without an address:
     * the account's own, because a review can only be written from one.
     */
    public static function replied(Review $reply, Review $review): void
    {
        if ($reply->user_id === $review->user_id) {
            return;
        }

        $to = trim((string) $review->user?->email);

        if ($to === '') {
            return;
        }

        // In the language the review was written in.
        Letters::send(ReviewLetters::REPLIED, $to, ['review' => $review, 'reply' => $reply], $review->locale ?: null);
    }
}
