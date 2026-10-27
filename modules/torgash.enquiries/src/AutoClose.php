<?php

namespace Torgash\Enquiries;

use Torgash\Enquiries\Models\Enquiry;

/**
 * Close a conversation that has run out on its own.
 *
 * A shop answers, the customer is satisfied and says nothing, and the thread
 * sits in the queue for ever — so the inbox slowly fills with work that is
 * already done and the badge stops meaning anything. The rule is deliberately
 * narrow: the SHOP spoke last (nobody is waiting on us), and long enough ago.
 * A thread where the customer spoke last is never touched, however old — that
 * is an unanswered question, and closing it would be the shop tidying away its
 * own backlog.
 *
 * Not a rule in torgash.automations, even though it looks like one: this is
 * housekeeping the inbox needs to keep telling the truth, and a shop that has
 * not installed the automations module has the same inbox.
 */
class AutoClose
{
    /** @return int how many were closed */
    public static function run(): int
    {
        $hours = (int) setting('torgash.enquiries', 'auto_close_hours', 0);

        if ($hours <= 0) {
            return 0;
        }

        $cutoff = now()->subHours($hours);
        $closed = 0;

        Enquiry::open()
            ->with('lastMessage')
            ->chunkById(200, function ($enquiries) use ($cutoff, &$closed) {
                foreach ($enquiries as $enquiry) {
                    $last = $enquiry->lastMessage;

                    if (! $last?->is_staff || $last->created_at->gt($cutoff)) {
                        continue;
                    }

                    $enquiry->status = Status::DONE;

                    // The wait ended when the answer was written, not when this
                    // swept past — answered_at measures how long the person
                    // waited, and a scheduler running at 3am is not an answer.
                    $enquiry->answered_at ??= $last->created_at;
                    $enquiry->save();

                    $closed++;
                }
            });

        return $closed;
    }
}
