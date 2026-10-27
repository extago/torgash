<?php

namespace Torgash\Enquiries\Admin;

use Torgash\Enquiries\Models\Enquiry;

/**
 * The inbox on the admin dashboard: how many people are waiting for an
 * answer, how many of them longer than the shop promised, and who has waited
 * longest — the queue read from its oldest end, which is the end that matters.
 */
class DashboardWidgets
{
    public function register(): void
    {
        hook_listen('core.admin.dashboard.widgets', function (array $widgets) {
            $widgets[] = [
                'key' => 'enquiries.awaiting',
                'title' => 'Enquiries',
                'icon' => 'inbox',
                'size' => 'third',
                'position' => 45,
                'can' => 'enquiries.manage',
                'link' => ['url' => route('admin.enquiries.index', ['view' => 'awaiting']), 'label' => 'Inbox'],
                'render' => fn () => $this->render(),
            ];

            return $widgets;
        });
    }

    protected function render(): string
    {
        // Oldest wait first; bounded, since a dashboard is not the inbox.
        $waiting = Enquiry::query()->awaitingReply()->with('lastMessage')->latest('id')->limit(200)->get()
            ->sortBy(fn (Enquiry $enquiry) => $enquiry->waitingSince()?->getTimestamp() ?? PHP_INT_MAX)->values();
        $overdue = $waiting->filter->isOverdue()->count();

        $rows = $waiting->take(5)->map(fn (Enquiry $enquiry) => [
            'label' => $enquiry->answer('name') ?: ($enquiry->answer('phone') ?: ($enquiry->answer('email') ?: '#'.$enquiry->id)),
            'hint' => $enquiry->kindLabel().' · '.$enquiry->preview(60),
            'url' => route('admin.enquiries.show', $enquiry),
            'value' => $enquiry->waitingSince()?->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE),
            'tone' => $enquiry->isOverdue() ? 'bad' : null,
        ])->all();

        return view('admin.dashboard.list', [
            'stats' => [
                ['label' => __('awaiting a reply'), 'value' => Enquiry::query()->awaitingReply()->count(), 'url' => route('admin.enquiries.index', ['view' => 'awaiting'])],
                ['label' => __('waiting too long'), 'value' => $overdue, 'tone' => $overdue ? 'bad' : null],
                ['label' => __('new in 7 days'), 'value' => Enquiry::query()->where('created_at', '>=', now()->subDays(7))->count()],
            ],
            'rows' => $rows,
            'empty' => __('Nobody is waiting for an answer.'),
        ])->render();
    }
}
