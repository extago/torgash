<?php

namespace Torgash\Points\Api;

use Illuminate\Support\Facades\Validator;
use Torgash\Api\ApiResources;
use Torgash\Points\Ledger;
use Torgash\Points\Models\Account;
use Torgash\Points\Models\Entry;
use Torgash\Points\Models\OrderPoints;

/**
 * Customers' points over the admin API (PLAN §40.5): one row per customer who
 * has ever had any, keyed by the user id — what a CRM or a loyalty app asks
 * ("how many does she have, what is waiting, what happened") — and a
 * correction as an ACTION through the ledger, never a write of the balance.
 */
class PointsResources
{
    public function register(): void
    {
        ApiResources::register('admin.points', [
            'label' => 'Bonus points',
            'description' => 'A customer\'s points, keyed by their user id: the balance (the sum of `entries`), what is waiting for orders on their way, and the history. Corrected through the `adjust` action.',
            'permission' => 'points.manage',
            'query' => fn () => Account::query(),
            'sortable' => ['balance', 'updated_at'],
            'filters' => [
                'min_balance' => ['description' => 'At least this many points.', 'apply' => fn ($q, $v) => $q->where('balance', '>=', (int) $v)],
                'updated_after' => ['description' => 'Balances that moved since a moment (ISO 8601).', 'apply' => fn ($q, $v) => $q->where('updated_at', '>=', $v)],
            ],
            'serialize' => fn (Account $account) => [
                'user_id' => $account->user_id,
                'balance' => (int) $account->balance,
                'waiting' => (int) OrderPoints::where('user_id', $account->user_id)->whereNull('credited_at')->sum('earn'),
                'entries' => Entry::where('user_id', $account->user_id)->orderByDesc('id')->limit(100)->get()->map(fn (Entry $entry) => [
                    'type' => $entry->type,
                    'points' => $entry->points,
                    'order_id' => $entry->order_id,
                    'note' => $entry->note,
                    'expires_at' => $entry->expires_at?->toIso8601String(),
                    'at' => $entry->created_at?->toIso8601String(),
                ])->all(),
            ],
            'schema' => [
                'user_id' => 'integer',
                'balance' => 'integer',
                'waiting' => ['type' => 'integer', 'description' => 'Points of orders not yet in a crediting status.'],
                'entries' => 'array',
            ],
            'actions' => [
                'adjust' => [
                    'description' => 'Give or take points by hand: signed `points` and a `reason` the customer will read in their history. Never below zero.',
                    'body' => ['points' => 'integer', 'reason' => 'string'],
                    'apply' => function (Account $account, array $data) {
                        $valid = Validator::make($data, ['points' => 'required|integer|not_in:0', 'reason' => 'required|string|max:500'])->validate();

                        try {
                            app(Ledger::class)->adjust($account->user_id, (int) $valid['points'], $valid['reason']);
                        } catch (\InvalidArgumentException) {
                            abort(422, __('A balance cannot go below zero.'));
                        }
                    },
                ],
            ],
        ]);
    }
}
