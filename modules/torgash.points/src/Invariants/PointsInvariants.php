<?php

namespace Torgash\Points\Invariants;

use Illuminate\Support\Facades\DB;

/**
 * What must be true about points, said by the module that owns them. Answered
 * lazily through `guard.invariants`.
 */
class PointsInvariants
{
    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            'points.balance_is_its_history' => [
                'label' => 'Every customer\'s points balance is the sum of their history',
                'explain' => 'The balance is kept beside the history so a page can show it; a difference means something wrote it past the ledger. The history is the truth.',
                'check' => fn (int $limit) => DB::table('m_points_accounts as a')
                    ->leftJoin('m_points_ledger as l', 'l.user_id', '=', 'a.user_id')
                    ->groupBy('a.user_id', 'a.balance')
                    ->havingRaw('a.balance <> coalesce(sum(l.points), 0)')
                    ->limit($limit)
                    ->get(['a.user_id', 'a.balance', DB::raw('coalesce(sum(l.points), 0) as ledger')])
                    ->map(fn ($row) => [
                        'id' => 'user'.$row->user_id,
                        'title' => (string) (DB::table('users')->where('id', $row->user_id)->value('email') ?? '#'.$row->user_id),
                        'detail' => ['Balance :balance, history :ledger', ['balance' => (int) $row->balance, 'ledger' => (int) $row->ledger]],
                        'url' => url('/admin/customers/'.$row->user_id),
                    ])->all(),
            ],

            'points.balance_not_negative' => [
                'label' => 'No customer owes points',
                'explain' => 'A balance below zero means points were spent that were not there. Correct it on the customer\'s card.',
                'check' => fn (int $limit) => DB::table('m_points_accounts')->where('balance', '<', 0)->limit($limit)->get(['user_id', 'balance'])
                    ->map(fn ($row) => [
                        'id' => 'user'.$row->user_id,
                        'title' => (string) (DB::table('users')->where('id', $row->user_id)->value('email') ?? '#'.$row->user_id),
                        'detail' => ['Balance :balance', ['balance' => (int) $row->balance]],
                        'url' => url('/admin/customers/'.$row->user_id),
                    ])->all(),
            ],

            'points.orders_agree_with_history' => [
                'label' => 'What each order says it spent in points is what the history says',
                'explain' => 'An order keeps the points spent on it beside the money they took off; the history is the record of the points themselves. A difference means one was changed without the other.',
                'check' => fn (int $limit) => DB::table('m_points_orders as o')
                    ->leftJoinSub(
                        DB::table('m_points_ledger')->where('type', 'spend')->whereNotNull('order_id')->groupBy('order_id')->selectRaw('order_id, -sum(points) as spent'),
                        's', 's.order_id', '=', 'o.order_id',
                    )
                    ->whereRaw('o.spent <> coalesce(s.spent, 0)')
                    ->limit($limit)
                    ->get(['o.order_id', 'o.spent', DB::raw('coalesce(s.spent, 0) as ledger')])
                    ->map(fn ($row) => [
                        'id' => 'order'.$row->order_id,
                        'title' => (string) (DB::table('m_checkout_orders')->where('id', $row->order_id)->value('order_number') ?? '#'.$row->order_id),
                        'detail' => ['Order says :order, history :ledger', ['order' => (int) $row->spent, 'ledger' => (int) $row->ledger]],
                        'url' => url('/admin/orders/'.DB::table('m_checkout_orders')->where('id', $row->order_id)->value('order_number')),
                    ])->all(),
            ],
        ];
    }
}
