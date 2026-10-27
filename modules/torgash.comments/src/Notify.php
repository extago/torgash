<?php

namespace Torgash\Comments;

use App\Core\Mail\Letters;
use Torgash\Comments\Mail\CommentLetters;
use Torgash\Comments\Models\Comment;

/**
 * The one email this module sends by itself, and why it is not left to an
 * automations rule: a comment sitting in moderation that nobody has been told
 * about is indistinguishable from a comment form that does not work. The same
 * argument the enquiries module makes for its own notification.
 *
 * Everything beyond it — Telegram, a webhook, a digest — is a rule off
 * `comments.comment.created`, which is announced whether or not an address is
 * configured here.
 */
class Notify
{
    public static function about(Comment $comment): void
    {
        // A reply written by the shop does not need announcing to the shop.
        if ($comment->is_staff) {
            return;
        }

        // To the addresses set on Admin → Letters, else the module's own
        // notify_email, else the storefront's contact address.
        Letters::toShop(CommentLetters::POSTED, ['comment' => $comment]);
    }
}
