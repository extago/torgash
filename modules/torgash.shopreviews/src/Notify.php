<?php

namespace Torgash\ShopReviews;

use App\Core\Mail\Letters;
use Torgash\ShopReviews\Mail\ShopReviewLetters;
use Torgash\ShopReviews\Models\ShopReview;

/**
 * The two letters: a new review, to the shop (one waiting for moderation that
 * nobody knows about is a form that does not work), and the shop's answer, to
 * the person who wrote it (nobody comes back to read a page on the off-chance).
 */
class Notify
{
    public static function about(ShopReview $review): void
    {
        Letters::toShop(ShopReviewLetters::POSTED, ['review' => $review]);
    }

    public static function replied(ShopReview $review): void
    {
        $to = trim((string) ($review->email ?: $review->user?->email));

        if ($to === '' || ! setting('torgash.shopreviews', 'notify_author', true)) {
            return;
        }

        Letters::send(ShopReviewLetters::REPLIED, $to, ['review' => $review], $review->locale ?: null);
    }
}
