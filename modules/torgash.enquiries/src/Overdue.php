<?php

namespace Torgash\Enquiries;

use Illuminate\Support\Facades\DB;
use Torgash\Enquiries\Models\Enquiry;

/**
 * "Somebody has been waiting longer than the shop promised" — announced as
 * `enquiries.enquiry.overdue`, which is what an escalation rule listens to.
 *
 * The promise is the inbox's own setting (`reply_within_hours`, the one that
 * paints a row red), so the red row and the message to the owner can never
 * disagree about what "late" means. Announced ONCE per wait: the stamp is
 * compared with when the wait began, so the shop answering and the customer
 * writing again starts a new wait that can be late again. Nothing happens in
 * a request — this runs on the schedule, every five minutes.
 */
class Overdue
{
    public static function sweep(): int
    {
        $hours = (int) setting('torgash.enquiries', 'reply_within_hours', 24);

        if ($hours <= 0) {
            return 0;
        }

        $deadline = now()->subHours($hours);
        $announced = 0;

        Enquiry::query()->awaitingReply()
            // A wait never begins before the enquiry does: a cheap first cut.
            ->where('created_at', '<', $deadline)
            ->with('lastMessage')
            ->chunkById(200, function ($enquiries) use ($deadline, &$announced) {
                foreach ($enquiries as $enquiry) {
                    $since = $enquiry->waitingSince();

                    if (! $since || $since->gte($deadline)) {
                        continue;
                    }

                    if ($enquiry->overdue_announced_at && $enquiry->overdue_announced_at->gte($since)) {
                        continue;
                    }

                    // A plain query: stamping this must not move updated_at
                    // or look like somebody touched the enquiry.
                    DB::table('m_enquiries_enquiries')->where('id', $enquiry->id)->update(['overdue_announced_at' => now()]);
                    $enquiry->overdue_announced_at = now();

                    hook_action('enquiries.enquiry.overdue', ['enquiry' => $enquiry, 'since' => $since]);
                    $announced++;
                }
            });

        return $announced;
    }
}
