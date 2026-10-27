<?php

namespace Torgash\Automations\Actions;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Torgash\Automations\Models\RuleAction;
use Torgash\Automations\Models\RuleRun;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;

/**
 * Give the order to somebody (or take it back).
 *
 * The same rule the admin screen enforces: only a staff account can hold an
 * order. A customer assigned as manager would make the list's manager filter
 * meaningless and hand the order to somebody who cannot open it — and a rule
 * pointing at an account that has since lost its roles is exactly how that
 * would happen without the check.
 */
class SetManagerAction implements Action, HasFields
{
    public static function label(): string
    {
        return 'Assign a manager';
    }

    public static function subjects(): array
    {
        return ['order'];
    }

    public static function fields(): array
    {
        return [
            'manager_id' => [
                'label' => 'Send to',
                'type' => 'select',
                'options' => ['' => __('Not assigned')] + User::query()->staff()->orderBy('name')->pluck('name', 'id')->all(),
            ],
        ];
    }

    public function run(RuleAction $action, Model $order, array $context, string $idempotencyKey): array
    {
        $managerId = $action->config('manager_id');
        $managerId = $managerId === '' || $managerId === null ? null : (int) $managerId;

        if ($managerId !== null && ! User::query()->staff()->whereKey($managerId)->exists()) {
            return [
                'status' => RuleRun::FAILED,
                'summary' => __('That account is not a staff member.'),
                'detail' => ['manager_id' => $managerId],
            ];
        }

        if ($order->manager_id === $managerId) {
            return ['status' => RuleRun::SKIPPED, 'summary' => __('Already assigned'), 'detail' => []];
        }

        $was = $order->manager?->name;

        $order->update(['manager_id' => $managerId]);
        $order->load('manager');

        $now = $order->manager?->name;

        OrderLog::record($order, ':rule: :from → :to', [
            'rule' => $action->rule?->name ?? OrderLog::word('Automation'),
            'from' => $was ?? OrderLog::word('unassigned'),
            'to' => $now ?? OrderLog::word('unassigned'),
        ], by: null);

        return ['status' => RuleRun::SENT, 'summary' => sprintf('%s → %s', $was ?? __('unassigned'), $now ?? __('unassigned')), 'detail' => []];
    }
}
