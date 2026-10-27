<?php

namespace Torgash\Rma;

/**
 * Where the goods and the money are — deliberately NOT the enquiry's status.
 *
 * The enquiry says how the conversation stands (new, in progress, answered,
 * rejected); this says what has physically happened. They move independently
 * and both are true: a return can be approved while the thread is still being
 * argued about, and a thread can be answered while the parcel is in the post.
 *
 * Five words and no more. A shop that wants stages, owners and SLAs on top of
 * this wants a warehouse system, and the honest way to serve it is the API plus
 * an automations rule — the same answer the enquiry statuses give.
 */
class State
{
    /** The customer has asked; nobody has looked yet. */
    public const REQUESTED = 'requested';

    /** Yes, send it back. */
    public const APPROVED = 'approved';

    /** No — and the reason is in the conversation, where the customer can read it. */
    public const DECLINED = 'declined';

    /** The parcel is here. This is when stock goes back, not when it was approved. */
    public const RECEIVED = 'received';

    /** Money returned, or the replacement order placed. */
    public const COMPLETED = 'completed';

    public const ALL = [self::REQUESTED, self::APPROVED, self::DECLINED, self::RECEIVED, self::COMPLETED];

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::REQUESTED => __('Requested'),
            self::APPROVED => __('Approved'),
            self::DECLINED => __('Declined'),
            self::RECEIVED => __('Goods received'),
            self::COMPLETED => __('Completed'),
        ];
    }

    public static function label(string $state): string
    {
        return self::options()[$state] ?? $state;
    }

    public static function badge(string $state): string
    {
        return match ($state) {
            self::REQUESTED => 'badge-warning',
            self::APPROVED => 'badge-info',
            self::RECEIVED => 'badge-info',
            self::COMPLETED => 'badge-success',
            default => 'badge-ghost',
        };
    }

    /** Nothing more will happen to these on their own. */
    public static function isFinished(string $state): bool
    {
        return in_array($state, [self::DECLINED, self::COMPLETED], true);
    }
}
