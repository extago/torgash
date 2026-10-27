<?php

namespace App\Core\Admin;

/**
 * What is waiting for somebody in the admin — the bell in the top bar.
 *
 * Core knows no orders and no enquiries, so it only ASKS: a module answers
 * `core.admin.notifications` with rows of
 *   ['key' => …, 'label' => …, 'count' => int, 'url' => …, 'icon' => lucide name]
 * about its own data, and decides itself whether this person may see them
 * (a manager without orders.manage gets no orders row). A row counting zero
 * is dropped here, so a module may answer unconditionally.
 *
 * Nothing is stored and nothing is "read": the count IS the queue — new
 * orders nobody has moved on, enquiries awaiting a reply — and it goes down
 * when the work is done, not when the bell is opened.
 */
class AdminNotifications
{
    /** @return list<array{key: string, label: string, count: int, url: string, icon: string}> */
    public function all(): array
    {
        $rows = (array) hook_filter('core.admin.notifications', [], ['user' => auth()->user()]);

        return array_values(array_filter(array_map(fn ($row) => is_array($row) ? [
            'key' => (string) ($row['key'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'count' => (int) ($row['count'] ?? 0),
            'url' => (string) ($row['url'] ?? '#'),
            'icon' => (string) ($row['icon'] ?? 'bell'),
        ] : null, $rows), fn ($row) => $row && $row['count'] > 0));
    }

    public function total(): int
    {
        return array_sum(array_column($this->all(), 'count'));
    }
}
