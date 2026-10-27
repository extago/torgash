<?php

namespace Torgash\ShopReviews\Mail;

use Torgash\ShopReviews\Models\ShopReview;

/**
 * The module's letters in core's registry (PLAN §41). The review itself is a
 * BLOCK — the mark, pros, cons and text as written.
 */
class ShopReviewLetters
{
    public const POSTED = 'shopreviews.posted';

    public const REPLIED = 'shopreviews.replied';

    /** @return array<string, array<string, mixed>> */
    public static function events(): array
    {
        return [
            self::POSTED => [
                'label' => 'A new review of the shop',
                'group' => 'Shop reviews',
                'audience' => 'shop',
                'subject' => 'New review of the shop from {author}',
                'heading' => 'New review of the shop',
                'body' => "{review}\n\n{moderation}",
                'button' => ['label' => 'Open the reviews', 'url' => '{admin_url}'],
                'help' => self::help() + ['review' => 'The review (on a line of its own)', 'moderation' => 'Whether it waits for a check'],
                'tokens' => fn (array $c) => self::tokens($c['review'] ?? null),
                'blocks' => fn (array $c) => ['review' => self::reviewBlock($c['review'] ?? null)],
                'recipients' => fn () => array_filter([trim((string) setting('torgash.shopreviews', 'notify_email', ''))
                    ?: trim((string) (current_storefront()?->contact('email') ?? ''))]),
                'sample' => fn () => ['review' => ShopReview::latest('id')->first()],
            ],
            self::REPLIED => [
                'label' => 'The shop answered a review of the shop',
                'group' => 'Shop reviews',
                'audience' => 'customer',
                'subject' => 'We answered your review',
                'heading' => 'Thank you for your review — here is our answer.',
                'body' => "{answer}",
                'button' => ['label' => 'Read it on the site', 'url' => '{page_url}'],
                'help' => self::help() + ['answer' => 'The answer (on a line of its own)'],
                'tokens' => fn (array $c) => self::tokens($c['review'] ?? null),
                'blocks' => fn (array $c) => ['answer' => self::answerBlock($c['review'] ?? null)],
                'sample' => fn () => ['review' => ShopReview::whereNotNull('reply')->latest('id')->first()],
            ],
        ];
    }

    /** @return array<string, string> */
    protected static function help(): array
    {
        return [
            'author' => 'Who wrote it',
            'page_url' => 'The page of the shop\'s reviews',
            'admin_url' => 'The reviews screen in the admin',
        ];
    }

    /** @return array<string, string> */
    protected static function tokens(?ShopReview $review): array
    {
        return [
            'author' => (string) ($review?->author_name ?? ''),
            'page_url' => route('shopreviews.index'),
            'admin_url' => url('/admin/shop-reviews'),
            'moderation' => $review?->status === ShopReview::PENDING ? __('It is waiting for a check and is not on the site yet.') : '',
        ];
    }

    protected static function reviewBlock(?ShopReview $review): string
    {
        if (! $review) {
            return '';
        }

        $html = '<p style="margin:0 0 8px;"><b>'.e((string) $review->author_name).'</b>'
            .($review->city ? ', '.e($review->city) : '')
            .' — '.e(number_format((float) $review->rating, 1)).'/5</p>';

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

    protected static function answerBlock(?ShopReview $review): string
    {
        if (! $review?->reply) {
            return '';
        }

        return '<p style="margin:0 0 6px;"><b>'.e((string) $review->reply_author).':</b></p>'
            .'<div style="border-left:3px solid #cbd5e1; padding:6px 14px; margin:0 0 14px; white-space:pre-line;">'.e((string) $review->reply).'</div>';
    }
}
