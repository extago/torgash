<?php

namespace Torgash\Points\Mail;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\Order;
use Torgash\Points\Ledger;
use Torgash\Points\Models\OrderPoints;

/** The letters points send (PLAN §41): "your points are in" and "they are about to expire". */
class PointsLetters
{
    public const CREDITED = 'points.credited';

    public const EXPIRING = 'points.expiring';

    /** @return array<string, array<string, mixed>> */
    public static function events(): array
    {
        return [
            self::CREDITED => [
                'label' => 'Bonus points credited',
                'group' => 'Bonus points',
                'audience' => 'customer',
                'subject' => '{points} bonus points for order {order_number}',
                'heading' => 'Your bonus points are in',
                'body' => "Thank you for your order {order_number}: {points} bonus points are now yours.\n\nYou have {balance} points — spend them in the basket on your next order.",
                'button' => ['label' => 'My bonus points', 'url' => '{account_url}'],
                'help' => [
                    'points' => 'How many were credited',
                    'balance' => 'The balance now',
                    'order_number' => 'The order number',
                    'account_url' => 'The points page of the account',
                ],
                'tokens' => fn (array $c) => self::tokens($c['order'] ?? null),
                'sample' => fn () => ['order' => Order::whereIn('id', OrderPoints::select('order_id'))->latest('id')->first()],
            ],
            self::EXPIRING => [
                'label' => 'Bonus points are about to expire',
                'group' => 'Bonus points',
                'audience' => 'customer',
                'subject' => '{points} of your bonus points expire soon',
                'heading' => 'Your bonus points are about to expire',
                'body' => "You have {balance} bonus points, and {points} of them expire soon — the first on {date}.\n\nSpend them in the basket on your next order before then.",
                'button' => ['label' => 'My bonus points', 'url' => '{account_url}'],
                'help' => [
                    'points' => 'How many expire in the days the shop warns ahead',
                    'date' => 'The day the first of them expire',
                    'balance' => 'The balance now',
                    'account_url' => 'The points page of the account',
                ],
                'tokens' => fn (array $c) => [
                    'points' => number_format((int) ($c['points'] ?? 0), 0, '.', ' '),
                    'date' => (string) ($c['date'] ?? ''),
                    'balance' => number_format(app(Ledger::class)->balance(($c['user'] ?? null)?->id), 0, '.', ' '),
                    'account_url' => route('account.points.index'),
                ],
                'sample' => fn () => [
                    'user' => User::whereIn('id', DB::table('m_points_accounts')->where('balance', '>', 0)->select('user_id'))->first(),
                    'points' => 150,
                    'date' => now()->addDays(7)->format('d.m.Y'),
                ],
            ],
        ];
    }

    /** @return array<string, string> */
    protected static function tokens(?Order $order): array
    {
        if (! $order) {
            return [];
        }

        $row = OrderPoints::find($order->id);

        return [
            'points' => number_format((int) ($row?->earned ?: $row?->earn), 0, '.', ' '),
            'balance' => number_format(app(Ledger::class)->balance($order->user_id), 0, '.', ' '),
            'order_number' => (string) $order->order_number,
            'account_url' => route('account.points.index'),
        ];
    }
}
