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
    /**
     * Asked once per page: the dashboard draws the rows twice — the bell in
     * the top bar and its "Needs attention" card — and every module counted
     * its queue for each (measured on a live shop: the whole chain of counts
     * ran twice). One per request (`scoped`, forgotten by PerRequest), so
     * the next page counts afresh.
     *
     * @var list<array{key: string, label: string, count: int, url: string, icon: string}>|null
     */
    protected ?array $rows = null;

    /** @return list<array{key: string, label: string, count: int, url: string, icon: string}> */
    public function all(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $rows = (array) hook_filter('core.admin.notifications', [], ['user' => auth()->user()]);

        return $this->rows = array_values(array_filter(array_map(fn ($row) => is_array($row) ? [
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
