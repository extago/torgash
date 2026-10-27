<?php

namespace Torgash\Comments\Mail;

use Torgash\Comments\Models\Comment;

/**
 * The comments module's letter in core's registry (PLAN §41): a new
 * comment, to the shop. "Reply" goes to the commenter when they left an
 * address.
 */
class CommentLetters
{
    public const POSTED = 'comments.posted';

    /** @return array<string, array<string, mixed>> */
    public static function events(): array
    {
        return [
            self::POSTED => [
                'label' => 'A new comment',
                'group' => 'Comments',
                'audience' => 'shop',
                'subject' => 'New comment on {target}',
                'heading' => 'New comment',
                'body' => "{target}\n\n{comment}\n\n{moderation}",
                'button' => ['label' => 'Moderate comments', 'url' => '{admin_url}'],
                'help' => [
                    'target' => 'What it is about',
                    'target_url' => 'The link to it',
                    'author' => 'Who wrote it',
                    'comment' => 'The comment (on a line of its own)',
                    'moderation' => 'Whether it waits for a check',
                    'admin_url' => 'The comments screen in the admin',
                ],
                'tokens' => fn (array $c) => self::tokens($c['comment'] ?? null),
                'blocks' => fn (array $c) => ['comment' => self::block($c['comment'] ?? null)],
                'reply_to' => fn (array $c) => ($c['comment'] ?? null)?->author_email,
                'recipients' => fn () => array_filter([trim((string) setting('torgash.comments', 'notify_email', ''))
                    ?: trim((string) (current_storefront()?->contact('email') ?? ''))]),
                'sample' => fn () => ['comment' => Comment::latest('id')->first()],
            ],
        ];
    }

    /** @return array<string, string> */
    protected static function tokens(?Comment $comment): array
    {
        if (! $comment) {
            return [];
        }

        return [
            'target' => (string) $comment->targetTitle(),
            'target_url' => (string) $comment->targetUrl(),
            'author' => (string) $comment->author_name,
            'moderation' => $comment->status === 'pending' ? __('It is waiting for a check and is not on the site yet.') : '',
            'admin_url' => url('/admin/comments'),
        ];
    }

    protected static function block(?Comment $comment): string
    {
        if (! $comment) {
            return '';
        }

        $who = e((string) $comment->author_name).($comment->author_email ? ' &lt;'.e((string) $comment->author_email).'&gt;' : '');

        return '<p style="margin:0 0 6px;"><b>'.$who.'</b></p>'
            .'<div style="border-left:3px solid #cbd5e1; padding:6px 14px; margin:0 0 14px; white-space:pre-line;">'.e((string) $comment->body).'</div>';
    }
}
