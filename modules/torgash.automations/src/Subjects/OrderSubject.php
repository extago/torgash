<?php

namespace Torgash\Automations\Subjects;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Torgash\Automations\Conditions;
use Torgash\Automations\Context;
use Torgash\Automations\Models\RuleCondition;
use Torgash\Automations\Triggers;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Orders\OrderChanges;
use Torgash\Checkout\Orders\PaymentState;

/**
 * The order — what this module was written for, and still registers itself,
 * since it already depends on checkout. The questions, variables and events
 * are the ones it always had (Conditions, Context, Triggers); this class only
 * hands them to the engine in the shape every subject speaks.
 */
class OrderSubject extends BaseSubject
{
    public static function label(): string
    {
        return 'Order';
    }

    public function triggers(): array
    {
        // `automations.triggers` predates subjects: whatever a module added
        // there is an order event, as it always was.
        return array_filter(hook_filter('automations.triggers', Triggers::EVENTS), 'is_string');
    }

    /**
     * The three order events. `checkout.order.changed` is the general one and
     * the only one carrying a diff; the other two are their own triggers
     * because a merchant thinks in "when an order is placed", not in "when a
     * row appears".
     */
    public function listen(Closure $fire): void
    {
        hook_listen('checkout.order.changed', function (array $context) use ($fire) {
            $fire('checkout.order.changed', $context['order'], (array) ($context['changed'] ?? []));
        });

        hook_listen('checkout.order_placed', function (array $context) use ($fire) {
            $fire('checkout.order_placed', $context['order']);
        });

        hook_listen('checkout.order_status_changed', function (array $context) use ($fire) {
            $fire('checkout.order_status_changed', $context['order'], ['status']);
        });
    }

    public function usesFields(string $event): bool
    {
        return $event === 'checkout.order.changed';
    }

    public function fields(): array
    {
        return Triggers::FIELDS;
    }

    public function find(int $id): ?Model
    {
        return Order::with('manager')->find($id);
    }

    public function sample(): ?Model
    {
        return Order::latest('id')->first();
    }

    public function context(Model $model, string $event, array $changed = []): array
    {
        return Context::forOrder($model, $event, $changed);
    }

    public function variables(): array
    {
        return Context::catalogue();
    }

    public function conditions(): array
    {
        $inputs = [
            'status' => ['input' => 'list', 'options' => OrderStatus::orderBy('position')->get()->mapWithKeys(fn ($s) => [$s->code => $s->t('name')])->all()],
            'payment_state' => ['input' => 'list', 'options' => collect(PaymentState::all())->mapWithKeys(fn ($s) => [$s => PaymentState::label($s)])->all()],
            'total' => ['input' => 'amount'],
            'product_category' => ['input' => 'categories', 'options' => app(CategoryTree::class)->options()],
            'payment_method' => ['input' => 'text'],
            'shipping_method' => ['input' => 'text'],
            'country' => ['input' => 'csv', 'placeholder' => 'UA, PL, DE'],
            'city' => ['input' => 'text'],
            'manager' => ['input' => 'none'],
            'coupon' => ['input' => 'text', 'bare' => ['coupon_any', 'coupon_none']],
        ];

        $kinds = [];
        foreach (Conditions::KIND_LABELS as $kind => $label) {
            $kinds[$kind] = ['label' => $label, 'operators' => Conditions::KINDS[$kind] ?? []] + ($inputs[$kind] ?? ['input' => 'text']);
        }

        return $kinds;
    }

    public function passes(RuleCondition $condition, Model $model): bool
    {
        return Conditions::passes($condition, $model);
    }

    public function refs(array $ids): array
    {
        return Order::withTrashed()->whereIn('id', $ids)->get(['id', 'order_number'])
            ->mapWithKeys(fn (Order $order) => [$order->id => [
                'label' => $order->order_number,
                'url' => route('admin.orders.show', $order->order_number),
            ]])->all();
    }

    /**
     * Announce what the internal actions changed HERE, while the guard is still
     * up. Left to the worker's own flush after the job, the change would arrive
     * with nothing suppressing it and "when the status changes, set the status"
     * would run forever.
     */
    public function afterActions(): void
    {
        app(OrderChanges::class)->flush();
    }
}
