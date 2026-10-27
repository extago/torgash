<?php

namespace Torgash\Speed\Http\Admin;

use Torgash\Speed\Reports;

/**
 * The dashboard's line about speed: how the storefront feels today against
 * the week before, the page that costs the most, and what got slower —
 * the three questions worth a glance; everything else is on the Speed page.
 */
class DashboardCard
{
    public function __construct(protected Reports $reports) {}

    public function render(bool $bare = false): string
    {
        if (! auth()->user()?->can('speed.view')) {
            return '';
        }

        $today = $this->reports->zones(now()->subDay());
        $week = $this->reports->zones(now()->subDays(8), now()->subDay());

        return view('torgash.speed::admin.dashboard-card', [
            'today' => $today['storefront'] ?? null,
            'week' => $week['storefront'] ?? null,
            'top' => $this->reports->routes(now()->subDay(), zone: 'storefront')->first(),
            'grown' => $this->reports->grown()->first(),
            'enabled' => (bool) setting('torgash.speed', 'enabled'),
            'bare' => $bare,
        ])->render();
    }
}
