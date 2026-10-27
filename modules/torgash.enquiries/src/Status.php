<?php

namespace Torgash\Enquiries;

/**
 * Where an enquiry stands. A fixed vocabulary in a class, not a table the shop
 * edits — deliberately the opposite choice from order statuses.
 *
 * An order's statuses are configurable because fulfilment genuinely differs
 * between shops, and because other things (stock, emails, exports) are keyed to
 * them. "Have we rung this person back yet" is not a process: it is new, being
 * dealt with, done, or not worth answering. A shop that needs a pipeline with
 * stages, owners and SLAs needs a CRM, and the honest way to serve it is the
 * API resource plus an automations rule, not four more rows in a table here.
 */
class Status
{
    public const NEW = 'new';

    public const IN_PROGRESS = 'in_progress';

    public const DONE = 'done';

    public const REJECTED = 'rejected';

    /** In the order a shop moves through them; the first one is the default. */
    public const ALL = [self::NEW, self::IN_PROGRESS, self::DONE, self::REJECTED];

    /** The ones that still want somebody's attention — what the badge counts. */
    public const OPEN = [self::NEW, self::IN_PROGRESS];

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return [
            self::NEW => __('New'),
            self::IN_PROGRESS => __('In progress'),
            self::DONE => __('Answered'),
            self::REJECTED => __('Rejected'),
        ];
    }

    /**
     * What the CUSTOMER is told, which is neither the same vocabulary nor the
     * same question.
     *
     * The inbox's four states are the shop talking to itself: "rejected" is a
     * decision about the enquiry, and the difference between "new" and "in
     * progress" is about the shop's own queue. The customer is asking something
     * else — "has anybody answered me" — and that is NOT the status: the shop
     * replying leaves an enquiry "in progress", which read outward as "waiting
     * for an answer" underneath the answer itself. So the state is derived from
     * the conversation, in Enquiry::customerState(), and these three are only
     * how it is printed.
     */
    public const CUSTOMER_WAITING = 'waiting';

    public const CUSTOMER_ANSWERED = 'answered';

    public const CUSTOMER_CLOSED = 'closed';

    public static function customerLabel(string $state): string
    {
        return match ($state) {
            // Not the DONE status' own word: to the shop that state is
            // "dealt with", and the two want different words in a language
            // that declines them.
            self::CUSTOMER_ANSWERED => __('Answer received'),
            self::CUSTOMER_CLOSED => __('Closed'),
            default => __('Waiting for an answer'),
        };
    }

    public static function customerBadge(string $state): string
    {
        return match ($state) {
            self::CUSTOMER_ANSWERED => 'badge-success',
            self::CUSTOMER_CLOSED => 'badge-ghost',
            default => 'badge-warning',
        };
    }

    public static function label(string $status): string
    {
        return self::options()[$status] ?? $status;
    }

    /** daisyUI badge modifier, so the list is scannable at a glance. */
    public static function badge(string $status): string
    {
        return match ($status) {
            self::NEW => 'badge-warning',
            self::IN_PROGRESS => 'badge-info',
            self::DONE => 'badge-success',
            default => 'badge-ghost',
        };
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, self::OPEN, true);
    }
}
