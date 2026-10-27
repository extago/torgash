<?php

namespace Torgash\Automations;

use Torgash\Automations\Subjects\Subjects;

/**
 * What a rule can react to.
 *
 * Deliberately hook names, not an invented event registry: the shop already
 * announces these, and a rule pointing at `checkout.order.changed` is pointing
 * at the same string a module would listen to by hand. Each event belongs to a
 * SUBJECT (Subjects\Subject) — an order's, an enquiry's — and a module adds
 * events by registering a subject through `automations.subjects`, or, for an
 * order event, through `automations.triggers` as before.
 */
class Triggers
{
    /** The order's own events: event => English source label. */
    public const EVENTS = [
        'checkout.order_placed' => 'An order is placed',
        'checkout.order.changed' => 'Something about an order changes',
        'checkout.order_status_changed' => 'An order status changes',
    ];

    /**
     * The diff-carrying event can be narrowed to the fields that moved — the
     * "статус/менеджер/состав" choice a merchant actually thinks in. Names are
     * the order's own columns, because that is what the diff is keyed by.
     */
    public const FIELDS = [
        'status' => 'Status',
        'payment_state' => 'Payment state',
        'paid_total' => 'Amount paid',
        'total' => 'Total',
        'items' => 'Contents',
        'manager_id' => 'Manager',
        'payment_method_name' => 'Payment method',
        'shipping_method_name' => 'Delivery method',
        'shipping_cost' => 'Delivery cost',
    ];

    /** @return array<string, string> event => translated label, every subject's */
    public static function all(): array
    {
        $events = [];
        foreach (Subjects::all() as $subject) {
            $events += $subject->triggers();
        }

        return array_map(fn (string $label) => __($label), $events);
    }

    /**
     * For a grouped picker: subject key => [label, events].
     *
     * @return array<string, array{label: string, events: array<string, string>}>
     */
    public static function grouped(): array
    {
        $groups = [];
        foreach (Subjects::all() as $key => $subject) {
            $groups[$key] = [
                'label' => __($subject::label()),
                'events' => array_map(fn (string $label) => __($label), $subject->triggers()),
            ];
        }

        return $groups;
    }

    public static function label(string $event): string
    {
        return self::all()[$event] ?? $event;
    }

    /** Only a diff-carrying event has fields to narrow by. */
    public static function usesFields(string $event): bool
    {
        return (bool) Subjects::for($event)?->usesFields($event);
    }

    /** @return array<string, string> the fields an event can be narrowed by, translated */
    public static function fields(?string $event = null): array
    {
        $fields = $event === null ? self::FIELDS : (Subjects::for($event)?->fields() ?? []);

        return array_map(fn (string $label) => __($label), $fields);
    }
}
