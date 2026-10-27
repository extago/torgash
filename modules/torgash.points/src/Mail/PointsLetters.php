<?php

namespace Torgash\Points\Mail;

use Torgash\Checkout\Models\Order;
use Torgash\Points\Ledger;
use Torgash\Points\Models\OrderPoints;

/** "Your points are in" — the one letter points send (PLAN §41). */
class PointsLetters
{
    public const CREDITED = 'points.credited';

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
