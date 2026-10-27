<?php

namespace App\Core\Admin;

use App\Core\Modules\ModuleManager;
use App\Core\Tasks\Tasks;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Storefront;
use App\Models\User;

/**
 * Core's own dashboard widgets — about data only core has: what is waiting
 * (the bell's rows, from every module), the customers, and the instance
 * itself. Plus the old `core.admin.dashboard` HTML hook, drawn as one widget
 * so a module written against it keeps its card.
 */
class CoreWidgets
{
    public static function register(): void
    {
        hook_listen('core.admin.dashboard.widgets', function (array $widgets) {
            $widgets[] = [
                'key' => 'core.attention',
                'title' => 'Needs attention',
                'icon' => 'bell-ring',
                'size' => 'third',
                'position' => 5,
                'render' => fn () => view('admin.dashboard.attention', [
                    'rows' => app(AdminNotifications::class)->all(),
                ])->render(),
            ];

            $widgets[] = [
                'key' => 'core.customers',
                'title' => 'Customers',
                'icon' => 'users',
                'size' => 'third',
                'position' => 60,
                'can' => 'customers.manage',
                'link' => ['url' => route('admin.customers.index'), 'label' => 'All customers'],
                'render' => fn () => view('admin.dashboard.customers', [
                    'total' => User::customers()->count(),
                    'month' => User::customers()->where('created_at', '>=', now()->subDays(30))->count(),
                    'week' => User::customers()->where('created_at', '>=', now()->subDays(7))->count(),
                    'latest' => User::customers()->latest('id')->limit(5)->get(['id', 'name', 'email', 'created_at']),
                ])->render(),
            ];

            $widgets[] = [
                'key' => 'core.system',
                'title' => 'System',
                'icon' => 'server',
                'size' => 'third',
                'position' => 90,
                'render' => function () {
                    $modules = app(ModuleManager::class);
                    $tasks = app(Tasks::class);

                    return view('admin.dashboard.system', [
                        'modules' => count($modules->enabled()),
                        'modulesTotal' => count($modules->all()),
                        'storefronts' => Storefront::where('enabled', true)->count(),
                        'languages' => Language::where('enabled', true)->count(),
                        'currencies' => Currency::where('enabled', true)->count(),
                        'workerSilent' => $tasks->workerSilent(),
                        'pending' => $tasks->pending(),
                        'failed' => $tasks->failedJobs(),
                    ])->render();
                },
            ];

            // Cards written for the old one-string hook, kept as one widget.
            $legacy = (string) hook_filter('core.admin.dashboard', '', []);
            if (trim($legacy) !== '') {
                $widgets[] = [
                    'key' => 'core.legacy',
                    'title' => 'More',
                    'icon' => 'blocks',
                    'size' => 'full',
                    'position' => 100,
                    'render' => fn () => $legacy,
                ];
            }

            return $widgets;
        });
    }
}
