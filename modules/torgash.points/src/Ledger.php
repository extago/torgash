<?php

namespace Torgash\Points;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Torgash\Points\Models\Entry;

/**
 * The only way a customer's points move — the gift cards' ledger again, for
 * the same reasons: every movement a signed line, the balance on
 * `m_points_accounts` rewritten as their SUM in the same transaction, and the
 * account row locked before points are spent, or two orders in one second
 * spend one balance twice.
 */
class Ledger
{
    /** Memo of user id → balance, per request. */
    protected array $balances = [];

    public function balance(?int $userId): int
    {
        if (! $userId) {
            return 0;
        }

        return $this->balances[$userId] ??= (int) DB::table('m_points_accounts')->where('user_id', $userId)->value('balance');
    }

    public function earn(int $userId, int $points, int $orderId): ?Entry
    {
        if ($points <= 0) {
            return null;
        }

        $months = (int) setting('torgash.points', 'lifetime_months', 0);

        return $this->write($userId, 'earn', $points, $orderId, expiresAt: $months > 0 ? now()->addMonthsNoOverflow($months) : null);
    }

    /** Spend for an order, or refuse — the caller is inside the order's transaction. */
    public function spend(int $userId, int $points, int $orderId): Entry
    {
        return DB::transaction(function () use ($userId, $points, $orderId) {
            $this->lock($userId);

            if ($points > $this->fresh($userId)) {
                throw ValidationException::withMessages([
                    'points' => __('You no longer have that many points. Please check the order again.'),
                ]);
            }

            return $this->write($userId, 'spend', -$points, $orderId);
        });
    }

    /** Spent points given back — never more than the order took. */
    public function refund(int $userId, int $points, int $orderId, ?string $note = null): ?Entry
    {
        return $points > 0 ? $this->write($userId, 'refund', $points, $orderId, $note) : null;
    }

    /**
     * Earned points taken back. Never below zero: points already spent are
     * spent, and a balance that owes the shop is not a thing a customer can
     * be shown.
     */
    public function revoke(int $userId, int $points, int $orderId, ?string $note = null): int
    {
        return DB::transaction(function () use ($userId, $points, $orderId, $note) {
            $this->lock($userId);
            $take = min(max(0, $points), $this->fresh($userId));

            if ($take > 0) {
                $this->write($userId, 'revoke', -$take, $orderId, $note);
            }

            return $take;
        });
    }

    public function adjust(int $userId, int $delta, string $reason): Entry
    {
        return DB::transaction(function () use ($userId, $delta, $reason) {
            $this->lock($userId);

            if ($delta === 0 || $this->fresh($userId) + $delta < 0) {
                throw new \InvalidArgumentException('A balance cannot go below zero.');
            }

            $months = (int) setting('torgash.points', 'lifetime_months', 0);

            return $this->write($userId, 'adjust', $delta, null, $reason, $delta > 0 && $months > 0 ? now()->addMonthsNoOverflow($months) : null);
        });
    }

    /**
     * Points past their date, written off — oldest first. The balance may not
     * exceed what the still-living credits add up to; the excess is what has
     * expired, whatever order it was spent in. One query per customer who has
     * anything expiring.
     */
    public function expire(): int
    {
        $users = Entry::where('points', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->distinct()->pluck('user_id');
        $count = 0;

        foreach ($users as $userId) {
            DB::transaction(function () use ($userId, &$count) {
                $this->lock($userId);
                $alive = (int) Entry::where('user_id', $userId)->where('points', '>', 0)->whereIn('type', ['earn', 'adjust', 'refund'])
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))->sum('points');
                $excess = $this->fresh($userId) - $alive;

                if ($excess > 0) {
                    $this->write($userId, 'expire', -$excess);
                    $count++;
                }
            });
        }

        return $count;
    }

    public static function sum(int $userId): int
    {
        return (int) Entry::where('user_id', $userId)->sum('points');
    }

    protected function lock(int $userId): void
    {
        DB::table('m_points_accounts')->insertOrIgnore(['user_id' => $userId, 'balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('m_points_accounts')->where('user_id', $userId)->lockForUpdate()->first();
    }

    protected function fresh(int $userId): int
    {
        return (int) DB::table('m_points_accounts')->where('user_id', $userId)->value('balance');
    }

    protected function write(int $userId, string $type, int $points, ?int $orderId = null, ?string $note = null, $expiresAt = null): Entry
    {
        return DB::transaction(function () use ($userId, $type, $points, $orderId, $note, $expiresAt) {
            $actor = auth()->user();

            $entry = Entry::create([
                'user_id' => $userId,
                'type' => $type,
                'points' => $points,
                'order_id' => $orderId,
                'expires_at' => $expiresAt,
                'note' => $note,
                'actor_id' => $actor?->id,
                'actor_name' => $actor?->name,
            ]);

            $balance = self::sum($userId);
            DB::table('m_points_accounts')->upsert(
                [['user_id' => $userId, 'balance' => $balance, 'created_at' => now(), 'updated_at' => now()]],
                ['user_id'],
                ['balance', 'updated_at'],
            );
            $this->balances[$userId] = $balance;

            return $entry;
        });
    }
}
