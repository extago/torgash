<?php

namespace Torgash\Reviews\Mail;

use Torgash\Reviews\Models\Review;

/**
 * The reviews module's letters in core's registry (PLAN §41): a new review,
 * to the shop, and an answer to a review, to the person who wrote it. The
 * review itself is a BLOCK — the mark, pros, cons and text as written.
 */
class ReviewLetters
{
    public const POSTED = 'reviews.posted';

    public const REPLIED = 'reviews.replied';

    /** @return array<string, array<string, mixed>> */
    public static function events(): array
    {
        return [
            self::POSTED => [
                'label' => 'A new review',
                'group' => 'Reviews',
                'audience' => 'shop',
                'subject' => 'New review: {product}',
                'heading' => 'New review',
                'body' => "{review}\n\n{moderation}",
                'button' => ['label' => 'Moderate reviews', 'url' => '{admin_url}'],
                'help' => self::help() + ['review' => 'The review (on a line of its own)', 'moderation' => 'Whether it waits for a check'],
                'tokens' => fn (array $c) => self::tokens($c['review'] ?? null),
                'blocks' => fn (array $c) => ['review' => self::reviewBlock($c['review'] ?? null)],
                'recipients' => fn () => array_filter([trim((string) setting('torgash.reviews', 'notify_email', ''))
                    ?: trim((string) (current_storefront()?->contact('email') ?? ''))]),
                'sample' => fn () => ['review' => Review::whereNull('parent_id')->latest('id')->first()],
            ],
            self::REPLIED => [
                'label' => 'An answer to a customer\'s review',
                'group' => 'Reviews',
                'audience' => 'customer',
                'subject' => 'Your review has an answer: {product}',
                'heading' => 'Somebody answered your review.',
                'body' => "{product}\n\n{answer}",
                'button' => ['label' => 'Read it on the product page', 'url' => '{product_url}'],
                'help' => self::help() + ['answer' => 'The answer (on a line of its own)'],
                'tokens' => fn (array $c) => self::tokens($c['review'] ?? null),
                'blocks' => fn (array $c) => ['answer' => self::answerBlock($c['reply'] ?? null)],
                'sample' => function () {
                    $reply = Review::whereNotNull('parent_id')->latest('id')->first();

                    return ['review' => $reply ? Review::find($reply->parent_id) : null, 'reply' => $reply];
                },
            ],
        ];
    }

    /** @return array<string, string> */
    protected static function help(): array
    {
        return [
            'product' => 'The product',
            'product_url' => 'The link to the product\'s reviews',
            'admin_url' => 'The reviews screen in the admin',
        ];
    }

    /** @return array<string, string> */
    protected static function tokens(?Review $review): array
    {
        if (! $review) {
            return [];
        }

        $product = $review->product;

        return [
            'product' => (string) ($product?->t('name') ?: $product?->sku),
            'product_url' => $product?->slug ? url('/product/'.$product->slug.'#reviews') : '',
            'admin_url' => url('/admin/reviews'),
            'moderation' => $review->status === 'pending' ? __('It is waiting for a check and is not on the site yet.') : '',
        ];
    }

    protected static function reviewBlock(?Review $review): string
    {
        if (! $review) {
            return '';
        }

        $html = '<p style="margin:0 0 8px;"><b>'.e((string) $review->author_name).'</b> — '.e(number_format((float) $review->rating, 1)).'/5'
            .($review->verified ? ' <span style="color:#16a34a;">('.e(__('Verified purchase')).')</span>' : '').'</p>';

        foreach (['pros' => __('Pros'), 'cons' => __('Cons')] as $field => $label) {
            if ($review->{$field}) {
                $html .= '<p style="margin:0 0 8px;"><b>'.e($label).':</b> '.e((string) $review->{$field}).'</p>';
            }
        }

        if ($review->body) {
            $html .= '<div style="border-left:3px solid #cbd5e1; padding:6px 14px; margin:0 0 14px; white-space:pre-line;">'.e((string) $review->body).'</div>';
        }

        return $html;
    }

    protected static function answerBlock(?Review $reply): string
    {
        if (! $reply) {
            return '';
        }

        return '<p style="margin:0 0 6px;"><b>'.e($reply->is_staff ? __('Shop') : (string) $reply->author_name).':</b></p>'
            .'<div style="border-left:3px solid #cbd5e1; padding:6px 14px; margin:0 0 14px; white-space:pre-line;">'.e((string) $reply->body).'</div>';
    }
}
