<?php

namespace Torgash\Reviews\Admin;

use Torgash\Reviews\Models\Review;

/**
 * Reviews on the admin dashboard: what waits for moderation (a review nobody
 * publishes is a customer who wrote for nothing), and the month's marks.
 */
class DashboardWidgets
{
    public function register(): void
    {
        hook_listen('core.admin.dashboard.widgets', function (array $widgets) {
            $widgets[] = [
                'key' => 'reviews.moderation',
                'title' => 'Reviews',
                'icon' => 'star',
                'size' => 'third',
                'position' => 50,
                'can' => 'reviews.moderate',
                'link' => ['url' => route('admin.reviews.index'), 'label' => 'All reviews'],
                'render' => fn () => $this->render(),
            ];

            return $widgets;
        });
    }

    protected function render(): string
    {
        $pending = Review::query()->reviews()->where('status', Review::PENDING);
        $month = Review::query()->reviews()->where('status', Review::PUBLISHED)->where('created_at', '>=', now()->subDays(30));
        $average = (clone $month)->whereNotNull('rating')->avg('rating');

        $rows = (clone $pending)->with('product:id,name')->oldest('id')->limit(5)->get()
            ->map(fn (Review $review) => [
                'label' => $review->product?->t('name') ?? '—',
                'hint' => ($review->author_name ?: '—').' · '.\Illuminate\Support\Str::limit(strip_tags((string) $review->body), 60),
                'url' => route('admin.reviews.index'),
                'value' => $review->rating ? str_repeat('★', (int) round($review->rating)) : '',
            ])->all();

        return view('admin.dashboard.list', [
            'stats' => [
                ['label' => __('to moderate'), 'value' => (clone $pending)->count(), 'tone' => (clone $pending)->exists() ? 'warn' : null, 'url' => route('admin.reviews.index')],
                ['label' => __('published in 30 days'), 'value' => (clone $month)->count()],
                ['label' => __('average mark, 30 days'), 'value' => $average ? number_format((float) $average, 1, ',', ' ') : '—'],
            ],
            'rows' => $rows,
            'empty' => __('Nothing waits for moderation.'),
        ])->render();
    }
}
