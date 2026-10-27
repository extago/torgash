<?php

namespace Torgash\Reviews;

use App\Core\Privacy\PersonalData;
use App\Core\Privacy\Subject;
use Torgash\Reviews\Models\Review;

/**
 * Reviews in core's personal-data registry (PLAN §49). A review is the shop's
 * published content and stays; erasing takes the person's NAME off it (it
 * becomes "Customer" in the review's own language) and the link to the
 * account. Found by the account only — a review has no email of its own.
 */
class ReviewPersonalData
{
    public static function register(PersonalData $registry): void
    {
        $registry->register('reviews', [
            'label' => 'Reviews',
            'export' => fn (Subject $s) => $s->user
                ? Review::where('user_id', $s->user->id)->orderBy('id')->get()->map(fn (Review $r) => [
                    'written_at' => $r->created_at?->toIso8601String(),
                    'product_id' => $r->product_id,
                    'name' => $r->author_name,
                    'rating' => $r->rating,
                    'text' => $r->body,
                    'pros' => $r->pros,
                    'cons' => $r->cons,
                ])->all()
                : [],
            'erase' => function (Subject $s) {
                if (! $s->user) {
                    return 0;
                }

                $count = 0;

                Review::where('user_id', $s->user->id)->each(function (Review $review) use (&$count) {
                    $review->forceFill([
                        'author_name' => trans('Customer', [], $review->locale ?: config('app.locale')),
                        'user_id' => null,
                    ])->saveQuietly();
                    $count++;
                });

                return $count;
            },
            'keep' => 'Reviews stay on the site, signed "Customer".',
        ]);
    }
}
