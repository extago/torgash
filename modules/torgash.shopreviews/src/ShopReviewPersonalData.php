<?php

namespace Torgash\ShopReviews;

use App\Core\Privacy\PersonalData;
use App\Core\Privacy\Subject;
use Torgash\ShopReviews\Models\ShopReview;

/**
 * Shop reviews in core's personal-data registry (PLAN §49), found by the
 * account or by the email a guest gave. A review is the shop's published
 * content and stays; erasing takes off everything that names the person.
 */
class ShopReviewPersonalData
{
    public static function register(PersonalData $registry): void
    {
        $registry->register('shopreviews', [
            'label' => 'Shop reviews',
            'export' => fn (Subject $s) => $s->scope(ShopReview::query(), 'user_id', 'email', null)->orderBy('id')->get()
                ->map(fn (ShopReview $r) => [
                    'written_at' => $r->created_at?->toIso8601String(),
                    'name' => $r->author_name,
                    'city' => $r->city,
                    'email' => $r->email,
                    'rating' => $r->rating,
                    'text' => $r->body,
                    'pros' => $r->pros,
                    'cons' => $r->cons,
                ])->all(),
            'erase' => function (Subject $s) {
                $count = 0;

                $s->scope(ShopReview::query(), 'user_id', 'email', null)->each(function (ShopReview $review) use (&$count) {
                    $review->forceFill([
                        'author_name' => trans('Customer', [], $review->locale ?: config('app.locale')),
                        'city' => null, 'email' => null, 'user_id' => null, 'ip' => null,
                    ])->saveQuietly();
                    $count++;
                });

                Stats::forget();

                return $count;
            },
            'keep' => 'Shop reviews stay on the site, signed "Customer".',
        ]);
    }
}
