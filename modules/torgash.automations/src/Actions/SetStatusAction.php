<?php

namespace Torgash\Automations\Actions;

use Illuminate\Database\Eloquent\Model;
use Torgash\Automations\Models\RuleAction;
use Torgash\Automations\Models\RuleRun;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Orders\OrderStatusChanger;

/**
 * Move the order to a status.
 *
 * Through checkout's own `OrderStatusChanger`, which is the same code the admin
 * select runs: the stock is taken or given back exactly once, the change is
 * announced, and an automation cannot end up with an inventory rule of its own.
 */
class SetStatusAction implements Action, HasFields
{
    public function __construct(protected OrderStatusChanger $changer) {}

    public static function label(): string
    {
        return 'Change the order status';
    }

    public static function subjects(): array
    {
        return ['order'];
    }

    public static function fields(): array
    {
        return [
            'status' => [
                'label' => 'New status',
                'type' => 'select',
                'options' => OrderStatus::choosable()->get()->mapWithKeys(fn ($s) => [$s->code => $s->t('name')])->all(),
            ],
        ];
    }

    public function run(RuleAction $action, Model $order, array $context, string $idempotencyKey): array
    {
        $code = (string) $action->config('status');
        $status = OrderStatus::find($code);

        if (! $status) {
            return [
                'status' => RuleRun::FAILED,
                'summary' => __('No such status: :code', ['code' => $code]),
                'detail' => [],
            ];
        }

        if ($order->status === $status->code) {
            return [
                'status' => RuleRun::SKIPPED,
                'summary' => __('Already :status', ['status' => $status->t('name')]),
                'detail' => [],
            ];
        }

        $wasStatus = OrderStatus::find($order->status);
        $was = $wasStatus?->t('name') ?? $order->status;

        $this->changer->change($order, $status);

        // The order's own history says a machine did it and which rule that was.
        // Without the line, a manager finds a status they did not set and no
        // trace of why — the single most annoying thing an automation can do.
        OrderLog::record($order, ':rule: :from → :to', [
            'rule' => $action->rule?->name ?? OrderLog::word('Automation'),
            'from' => $wasStatus?->name ?? $was,
            'to' => $status->name,
        ], by: null);

        return [
            'status' => RuleRun::SENT,
            'summary' => sprintf('%s → %s', $was, $status->t('name')),
            'detail' => [],
        ];
    }
}
