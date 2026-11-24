<?php

namespace Torgash\Points;

use App\Core\Mail\Letters;
use App\Models\User;
use Torgash\Points\Mail\PointsLetters;
use Torgash\Points\Models\Entry;

/**
 * "Your points are about to expire" (PLAN §74, stage 4): once a day, a letter
 * to every customer with a credit that has entered the days the shop set —
 * how many points go in those days ({@see Ledger::expiring()}, so points
 * already spent are not threatened with) and when the first of them do.
 *
 * A credit is warned about once (`expiry_warned_at`), whether or not a letter
 * went: one whose points are spent has nothing to warn of, and an account
 * without an address is not asked again tomorrow.
 */
class ExpiryNotices
{
    public function __construct(protected Ledger $ledger) {}

    /** @return int letters sent */
    public function send(): int
    {
        $days = (int) setting('torgash.points', 'expiry_notice_days', 7);

        if ($days <= 0) {
            return 0;
        }

        $until = now()->addDays($days);
        $due = fn () => Entry::where('points', '>', 0)->whereNull('expiry_warned_at')->whereBetween('expires_at', [now(), $until]);
        $sent = 0;

        foreach ($due()->distinct()->pluck('user_id') as $userId) {
            $soon = $this->ledger->expiring((int) $userId, $until);
            $due()->where('user_id', $userId)->update(['expiry_warned_at' => now()]);
            $user = $soon ? User::find($userId) : null;

            if ($user && Letters::send(PointsLetters::EXPIRING, $user->email, [
                'user' => $user,
                'points' => $soon['points'],
                'date' => $soon['at']->format('d.m.Y'),
            ], $user->preferredLocale() ?? config('app.locale'))) {
                $sent++;
            }
        }

        return $sent;
    }
}
