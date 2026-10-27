<?php

namespace Torgash\Comments\Api;

use Torgash\Api\ApiResources;
use Torgash\Comments\CommentTargets;
use Torgash\Comments\Models\Comment;

/**
 * Comments over the API — read, and moderated by the one act the screen
 * offers. No create: a comment written by a machine with a token is not a
 * comment, and the public form is where visitors write.
 */
class CommentResources
{
    public function register(): void
    {
        ApiResources::register('admin.comments', [
            'label' => 'Comments',
            'description' => 'Comments on pages and anything else a module registered as commentable. Read whole; published or rejected through PATCH, which is the same act the moderation screen performs.',
            'permission' => 'comments.moderate',
            'query' => fn () => Comment::query(),
            'sortable' => ['id', 'created_at', 'updated_at'],
            'filters' => [
                'status' => [
                    'description' => 'pending | approved | rejected.',
                    'apply' => fn ($q, $v) => $q->where('status', $v),
                ],
                'target_type' => [
                    'description' => 'What kind of thing was commented on — a key from the target registry, e.g. `page`.',
                    'apply' => fn ($q, $v) => $q->where('target_type', $v),
                ],
                'target_id' => ['apply' => fn ($q, $v) => $q->where('target_id', (int) $v)],
                'updated_after' => [
                    'description' => 'Everything touched since a moment (ISO 8601).',
                    'apply' => fn ($q, $v) => $q->where('updated_at', '>=', $v),
                ],
            ],
            'serialize' => fn (Comment $comment) => [
                'id' => $comment->id,
                'target_type' => $comment->target_type,
                'target_id' => (int) $comment->target_id,
                'target' => $comment->targetTitle(),
                'url' => $comment->targetUrl(),
                'parent_id' => $comment->parent_id,
                'author_name' => $comment->author_name,
                'author_email' => $comment->author_email,
                'user_id' => $comment->user_id,
                'body' => $comment->body,
                'status' => $comment->status,
                'is_staff' => (bool) $comment->is_staff,
                'created_at' => $comment->created_at?->toIso8601String(),
                'updated_at' => $comment->updated_at?->toIso8601String(),
            ],
            'writable' => [
                'status' => ['type' => 'string', 'description' => 'pending | approved | rejected. The only field a moderator changes; the text is the author’s.'],
            ],
            'schema' => [
                'id' => 'integer',
                'target_type' => ['type' => 'string', 'description' => 'A key from the registry: '.implode(' | ', array_keys(CommentTargets::all())).'.'],
                'target_id' => 'integer',
                'target' => ['type' => 'string', 'description' => 'What it is about, in words.'],
                'url' => 'string|null',
                'parent_id' => ['type' => 'integer|null', 'description' => 'Set on a reply. Threads are one level deep.'],
                'author_name' => 'string',
                'author_email' => ['type' => 'string|null', 'description' => 'Never shown on the storefront.'],
                'user_id' => ['type' => 'integer|null', 'description' => 'The account, when the author had one.'],
                'body' => 'string',
                'status' => 'string',
                'is_staff' => ['type' => 'boolean', 'description' => 'An answer written by the shop.'],
                'created_at' => ['type' => 'string|null', 'format' => 'date-time'],
                'updated_at' => ['type' => 'string|null', 'format' => 'date-time'],
            ],
            'update' => function (Comment $comment, array $data) {
                if (isset($data['status']) && in_array($data['status'], Comment::STATUSES, true)) {
                    $comment->update(['status' => $data['status']]);
                }
            },
        ]);
    }
}
