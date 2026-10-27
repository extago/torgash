<?php

namespace Torgash\Comments;

use App\Core\Privacy\PersonalData;
use App\Core\Privacy\Subject;
use Torgash\Comments\Models\Comment;

/**
 * Comments in core's personal-data registry (PLAN §49): found by the account
 * or the email they were left under; erasing takes the name and the email off
 * and leaves the words, signed "Customer" — removing them would leave the
 * replies under them answering nobody.
 */
class CommentPersonalData
{
    public static function register(PersonalData $registry): void
    {
        $registry->register('comments', [
            'label' => 'Comments',
            'export' => fn (Subject $s) => $s->scope(Comment::query(), 'user_id', 'author_email', null)->orderBy('id')->get()
                ->map(fn (Comment $c) => [
                    'written_at' => $c->created_at?->toIso8601String(),
                    'on' => $c->target_type.' #'.$c->target_id,
                    'name' => $c->author_name,
                    'email' => $c->author_email,
                    'text' => $c->body,
                ])->all(),
            'erase' => fn (Subject $s) => $s->scope(Comment::query(), 'user_id', 'author_email', null)->update([
                'author_name' => trans('Customer', [], config('app.locale')),
                'author_email' => null,
                'user_id' => null,
            ]),
            'keep' => 'Comments stay, signed "Customer".',
        ]);
    }
}
