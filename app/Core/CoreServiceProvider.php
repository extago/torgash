<?php

namespace App\Core;

use App\Core\Content\ContactPlaceholders;
use App\Core\Content\IconPlaceholders;
use App\Core\Content\Placeholders;
use App\Core\Currency\FetchRates;
use App\Core\Display\CoreRuleKinds;
use App\Core\Hooks\HookBus;
use App\Core\Modules\ModuleManager;
use App\Core\Seo\Breadcrumbs;
use App\Core\Seo\PageMeta;
use App\Core\Seo\PrivateRoutes;
use App\Core\Seo\RedirectManager;
use App\Core\Seo\StructuredData;
use App\Core\Settings\CoreSettings;
use App\Core\Settings\SettingsManager;
use App\Core\Storefronts\LocaleUrlGenerator;
use App\Core\Support\Currencies;
use App\Core\Support\PerRequest;
use App\Core\Support\Units;
use App\Core\Themes\ThemeManager;
use App\Core\Zones\ZoneRegistry;
use App\Http\Controllers\WellKnownController;
use App\Models\BackgroundTask;
use App\Models\Currency;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    /** PsySH's settings where it must not fork — tinkerWithoutFork(). */
    public const PSYSH_CONFIG = __DIR__.'/Support/psysh-config.php';

    public function register(): void
    {
        $this->app->singleton(HookBus::class);
        $this->app->singleton(\App\Core\Tasks\CurrentTask::class);
        $this->app->singleton(SettingsManager::class);
        $this->app->singleton(ThemeManager::class);
        $this->app->singleton(ZoneRegistry::class);
        $this->app->singleton(Placeholders::class);
        // Who keeps what personal data (PLAN §49) — code, not rows, so one
        // registry for the life of the process.
        $this->app->singleton(\App\Core\Privacy\PersonalData::class);
        $this->app->singleton(ContactPlaceholders::class);
        // One per process: it holds the "recording" switch its listener reads.
        $this->app->singleton(\App\Core\Demo\DemoData::class);
        $this->app->singleton(Currencies::class);
        $this->app->singleton(Units::class);
        $this->app->singleton(RedirectManager::class);

        // The head of the page, the trail to it and its JSON-LD: one of each
        // per REQUEST, which is what `scoped` means — a singleton that is
        // forgotten between requests, so a persistent worker cannot serve the
        // next visitor the last one's title. PrivateRoutes is a singleton
        // proper: it memoises the route table, which does not change.
        $this->app->scoped(PageMeta::class);
        $this->app->scoped(Breadcrumbs::class);
        $this->app->scoped(StructuredData::class);
        $this->app->singleton(PrivateRoutes::class);
        // The active theme's settings and this storefront's values for them —
        // asked by the head, the layout and every partial of one page.
        $this->app->scoped(\App\Core\Themes\ThemeSettings::class);
        // The shop's colours for the admin's own screens (labels, sales).
        $this->app->scoped(\App\Core\Themes\ShopPalette::class);
        // Every language's grammatical cases, read once for the page.
        $this->app->scoped(\App\Core\Support\GrammaticalCases::class);
        // The cleaner for staff-written HTML, built once for the page — with
        // THIS storefront's list of sites a frame may come from.
        $this->app->scoped(\App\Core\Content\SafeHtml::class);
        $this->app->scoped(\App\Core\Assets\AssetVersions::class);
        // This request's script nonce: a nonce that repeats is no nonce.
        $this->app->scoped(\App\Core\Http\Csp::class);

        // ...and actually forgotten when the next request begins. `scoped()`
        // declares the intent; only Octane enforces it. See PerRequest.
        PerRequest::forget($this->app, PageMeta::class, Breadcrumbs::class, StructuredData::class, \App\Core\Themes\ThemeSettings::class, \App\Core\Themes\ShopPalette::class, \App\Core\Support\GrammaticalCases::class, \App\Core\Content\SafeHtml::class, \App\Core\Assets\AssetVersions::class, \App\Core\Http\Csp::class);
        $this->app->singleton(ModuleManager::class, fn ($app) => new ModuleManager($app));

        // Pages kept ready for guests (PLAN §61): the registry every module
        // declares into at boot, and the session built as a store the cache
        // can watch while a copy of a page is drawn — the same store with the
        // same handler, only the class differs.
        $this->app->singleton(PageCache\PageCache::class);
        $this->app->singleton(PageCache\Holes::class);
        $this->app->singleton(PageCache\Writes::class);
        $this->app->singleton('session', fn ($app) => new PageCache\WatchedSessionManager($app));

        $this->registerLocaleUrlGenerator();
        $this->registerFallbackCache();

        // The mail server typed on Admin → Letters, when there is one, for
        // every letter — applied as the mailer is first built, when settings
        // can be read (PLAN §41).
        $this->callAfterResolving('mail.manager', fn () => \App\Core\Mail\MailServer::apply());

        // Modules register their own service providers here, so anything a
        // module binds is available exactly like core bindings.
        $this->app->make(ModuleManager::class)->registerEnabled();
    }

    /**
     * Swaps Laravel's URL generator for one that keeps links in the current
     * request's locale shape (see LocaleUrlGenerator).
     *
     * Re-binding "url" rather than extending it, because the class itself has
     * to change and RoutingServiceProvider hands out a concrete UrlGenerator.
     * The extenders it registered afterwards (session and key resolvers, the
     * routes rebinding) are kept by the container and still applied to this
     * instance — Container::bind() replaces the factory, not the extenders —
     * so nothing else about URL generation changes. The constructor arguments
     * mirror RoutingServiceProvider::registerUrlGenerator() exactly, including
     * the request rebinder that keeps the generator's request current.
     */
    protected function registerLocaleUrlGenerator(): void
    {
        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();
            $app->instance('routes', $routes);

            return new LocaleUrlGenerator(
                $routes,
                $app->rebinding('request', fn ($app, $request) => $app['url']->setRequest($request)),
                $app['config']['app.asset_url'],
            );
        });
    }

    /**
     * "Redis" as the Cache and sessions screen means it: Redis with the
     * database cache behind it (App\Core\Performance\FallbackStore), for the
     * cache and for sessions alike. Registered when the managers are first
     * built, because the session starts before any provider's boot() could be
     * relied on to have run.
     */
    protected function registerFallbackCache(): void
    {
        $this->app->singleton(\App\Core\Performance\FallbackState::class);
        PerRequest::forget($this->app, \App\Core\Performance\FallbackState::class);

        // Read again by every queue job: a worker runs for days, and a setting,
        // a rate or a unit changed in the admin has to reach its next job.
        PerRequest::flushPerJob($this->app, SettingsManager::class, Currencies::class, Units::class);

        // A FrankenPHP worker puts back what a request may have changed in the
        // objects it shares with the next one (hooks, theme, translations).
        \App\Core\Support\WorkerMode::register($this->app);

        // The shop's clock is the application's (config app.timezone, and the
        // database session's): it has to be known before the settings table
        // can be read, so it lives in .env and the setting writes it there.
        hook_listen('core.setting.changed', function (array $change) {
            if ($change['module'] !== 'core' || $change['key'] !== 'timezone' || $change['storefront_id'] !== null) {
                return;
            }

            \App\Core\Support\ShopClock::write((string) $change['to']);
        });

        $this->callAfterResolving('cache', function ($cache) {
            // A plain closure: CacheManager::extend() binds it to the
            // manager, which is whose repository() and whose stores it needs.
            $cache->extend('torgash-fallback', function ($app, array $config) {
                return $this->repository(
                    new \App\Core\Performance\FallbackStore($this, $app['events'], $config['stores']),
                    ['events' => false, ...$config],
                );
            });
        });

        $this->callAfterResolving('session', function ($session, $app) {
            $session->extend('redis-fallback', fn ($app) => new \Illuminate\Session\CacheBasedSessionHandler(
                clone $app['cache']->store('redis-sessions-fallback'),
                (int) $app['config']['session.lifetime'],
            ));
        });
    }

    public function boot(ModuleManager $modules, ThemeManager $themes, SettingsManager $settings, ZoneRegistry $zones): void
    {
        // The admin role implicitly has every permission, so module installs
        // never need to sync their new permissions onto it.
        Gate::before(
            fn ($user) => $user->hasRole('admin') ? true : null,
        );

        // A shop that chose its time zone before it became the application's
        // clock gets it now (and in .env, once) — ShopClock::reconcile().
        \App\Core\Support\ShopClock::reconcile();

        $themes->registerViewPaths();

        // Core's own settings (units of measure) — declared in code because
        // core has no manifest, read through the same SettingsManager.
        $settings->registerDefaults(CoreSettings::ID, CoreSettings::defaults());

        // Contact tokens are core because the data is: they read
        // storefronts.contacts, so admin-authored content may rely on {phone}
        // without a module having to stay installed for it to keep meaning
        // something. Resolvers are lazy — registering costs nothing.
        $this->app->make(ContactPlaceholders::class)
            ->register($this->app->make(Placeholders::class));

        // ...and the icons the shop chose to make writable, which is a list it
        // edits rather than the two thousand the pack ships.
        $this->app->make(IconPlaceholders::class)
            ->register($this->app->make(Placeholders::class));

        // {year}, {logo}, {logo_dark} — for the HTML of a theme's blocks.
        (new \App\Core\Content\ThemePlaceholders)->register($this->app->make(Placeholders::class));

        foreach ($themes->chain() as $theme) {
            $themeLangPath = config('torgash.themes_path').DIRECTORY_SEPARATOR.$theme.DIRECTORY_SEPARATOR.'lang';
            if (is_dir($themeLangPath)) {
                $this->loadJsonTranslationsFrom($themeLangPath);
            }
        }

        foreach ($modules->enabled() as $manifest) {
            $themes->registerModuleViews($manifest);
            $settings->registerDefaults($manifest->id, $manifest->settingDefaults());
            $zones->registerFromModule($manifest, $manifest->zones);

            if ($lang = $manifest->langPath()) {
                $this->loadJsonTranslationsFrom($lang);
            }
        }

        // After modules, so a theme can override a module's zone declaration
        // (same name, its own label/group) rather than being shadowed by it.
        $zones->registerFromThemes();

        // A module came, went, or was switched: the code on disk is not the
        // code the caches, the workers and the stylesheet were made from.
        hook_listen('core.module.changed', function (array $context) {
            app(System\CodeChanged::class)->refresh();

            return $context;
        });

        $this->commands([
            Modules\Commands\ModuleListCommand::class,
            Modules\Commands\ModuleInstallCommand::class,
            Modules\Commands\ModuleUninstallCommand::class,
            Modules\Commands\ModuleEnableCommand::class,
            Modules\Commands\ModuleDisableCommand::class,
            Files\CopyFilesCommand::class,
            Backups\BackupCommand::class,
            Backups\BackupRestoreCommand::class,
            Access\AdminCommand::class,
            Assets\StylesCommand::class,
            System\RefreshCommand::class,
            Tasks\PruneMissingJobsCommand::class,
            Updates\ReleaseKeyCommand::class,
            Updates\ReleaseCommand::class,
            Updates\UpdateCommand::class,
            Updates\UpdateFinishCommand::class,
            Updates\UpdateRollbackCommand::class,
            Demo\DemoCommand::class,
            Install\InstallCommand::class,
            Modules\Commands\SiteExportCommand::class,
        ]);

        Route::middleware('web')
            ->prefix('admin')
            ->name('admin.')
            ->group(base_path('routes/admin.php'));

        $this->registerWellKnown();

        // The corner every module's "done" notice stacks in (<x-notice>): one
        // place, because the basket, the wishlist and the comparison do not
        // know each other and three corners would talk over one another.
        // Printed through the page's own slot rather than by the theme, so a
        // theme written without it still gets its notices.
        hook_listen('core.footer', fn (string $html) => $html.view('components.notices')->render(), 1);

        // Staff are told what customers see differently from them — a closed
        // storefront, safe mode. Both are asked first, cheaply; the roles are
        // loaded only when one of them is true.
        hook_listen('core.body_start', function (string $html) {
            $closed = (bool) current_storefront()?->closed;
            $safe = \App\Core\Support\SafeMode::active();

            if ((! $closed && ! $safe) || ! auth()->user()?->isStaff()) {
                return $html;
            }

            return $html.view('components.staff-bar', ['closed' => $closed, 'safe' => $safe])->render();
        }, 1);

        // Asking for a sign-in or restore code is a public form that sends
        // mail on demand — guarded like the others, and nameable on the
        // CAPTCHA screen (PLAN §44).
        hook_listen('core.forms', fn (array $forms) => $forms + [
            \App\Http\Controllers\Account\CodeController::FORM => 'Sign-in and password restore codes',
        ]);

        // Core's own letters, in the registry every module's join (PLAN §41).
        hook_listen('core.mail.events', fn (array $events) => $events + \App\Core\Mail\CoreLetters::events());

        // Creating an account can wait for its email to be proven by a code.
        hook_listen('core.verified_forms', fn (array $forms) => $forms + [
            \App\Http\Controllers\Account\RegisterController::FORM => ['label' => 'Creating an account', 'kinds' => ['email']],
            // Always asked, never a tick box: a phone on an account signs in.
            \App\Http\Controllers\Account\RegisterController::PHONE_FORM => ['label' => 'A phone put on an account', 'kinds' => ['phone'], 'always' => true],
        ]);

        $this->registerSchedule();
        $this->registerPageCache();
        $this->announceCurrencyChanges();
        $this->watchRates();
        $this->watchTasks();
        $this->watchUpdates();
        $this->tinkerWithoutFork();
        \App\Core\Admin\CoreWidgets::register();

        // The kinds of question a display rule may ask that core itself can
        // answer — where the page is, and who is looking. Everything about
        // products, stock or orders is registered by the module that owns it.
        CoreRuleKinds::register();

        // Core's own personal data: the account, addresses, verification codes.
        hook_listen('core.personal_data', fn (array $context) => \App\Core\Privacy\CorePersonalData::register($context['registry']));
    }

    /**
     * Tinker on FrankenPHP's `php-cli`. Where pcntl is, PsySH runs its loop in
     * a forked child, and the child calls cli_set_process_title() — which only
     * PHP's own CLI has: tinker died at its prompt and on `tinker file.php`,
     * only `--execute` worked (reported from the live shop, 2026-10-29).
     * Told not to fork, it keeps the loop in this process, as on Windows. A
     * PSYSH_CONFIG the server set itself is left alone.
     */
    protected function tinkerWithoutFork(): void
    {
        $this->app['events']->listen(CommandStarting::class, function (CommandStarting $event) {
            if ($event->command === 'tinker' && ! function_exists('cli_set_process_title') && empty($_SERVER['PSYSH_CONFIG'])) {
                $_SERVER['PSYSH_CONFIG'] = self::PSYSH_CONFIG;
            }
        });
    }

    /**
     * Pages kept ready for guests (PLAN §61): the route middleware a module
     * puts on its pages, `@personal`, the net that throws copies away on
     * every write — and what core itself keeps about a visitor and which of
     * its tables no guest page is drawn from. Every module declares its own.
     */
    protected function registerPageCache(): void
    {
        $this->app['router']->aliasMiddleware('page.cache', PageCache\CachePages::class);
        Blade::directive('personal', fn (string $expression) => "<?php echo personal({$expression}); ?>");

        DB::listen(fn (QueryExecuted $query) => app(PageCache\Writes::class)->saw($query));

        // A request that died while a copy was being drawn must not leave the
        // drawing open for the next one on the same worker.
        $this->app->rebinding('request', function ($app) {
            if ($app->resolved(PageCache\PageCache::class)) {
                $app->make(PageCache\PageCache::class)->end();
            }
        });

        // Switched on: whatever was kept the last time it was on is not to be
        // trusted (the writes in between were not watched).
        hook_listen('core.setting.changed', function (array $change) {
            if ($change['module'] === CoreSettings::ID && $change['key'] === PageCache\PageCache::ENABLED) {
                page_cache()->flush('the page cache was switched '.($change['to'] ? 'on' : 'off'));
            }
        });

        page_cache()
            // The language is the address's; the ResolveStorefront writes it
            // on every request.
            ->harmless(session: ['locale'])
            ->personal(
                session: ['verification', 'code_pending', \App\Http\Controllers\Account\NewPasswordController::SESSION],
                cookies: [\App\Core\Verification\FormVerification::COOKIE],
            )
            ->vary('currency', fn () => current_currency(), session: ['currency'])
            ->unseen([
                'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
                'page_cache', 'background_tasks', 'password_reset_tokens', 'verification_codes', 'demo_records',
                // Who has which role, and the accounts themselves: a guest's
                // page shows neither (a review keeps its author's name).
                'users', 'addresses', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
                // Letters, and the redirect table — read only for an address
                // that is not a page, which is never kept.
                'mail_texts', 'redirects',
            ]);
    }

    /**
     * The two files addressed to programs, and the limit on what a program may
     * take.
     *
     * `web`, like the sitemap and for the same reason: they are storefront
     * answers and need the storefront resolved (which host, which theme, which
     * currency), and a well-known file that cannot tell two shops apart is
     * wrong on the second one. The session that comes with `web` is the price,
     * and the storefront's HTML pays it already for every crawler that visits.
     *
     * robots.txt is deliberately NOT limited: it is how a crawler learns what
     * it may do, and refusing it is how a well-behaved one ends up guessing.
     * The text renditions are, per address — the `.md` half of the catalogue is
     * one more way to download all of it, and the API's own limiter is keyed by
     * a token these callers do not have and lives in a module that may not be
     * installed.
     */
    protected function registerWellKnown(): void
    {
        RateLimiter::for('torgash-agent', fn (Request $request) => Limit::perMinute(
            max(1, (int) setting('core', 'agent_rate_limit', 60)),
        )->by('agent:'.$request->ip()));

        Route::middleware('web')->group(function () {
            Route::get('/robots.txt', [WellKnownController::class, 'robots'])->name('core.robots');

            Route::get('/llms.txt', [WellKnownController::class, 'llms'])
                ->middleware('throttle:torgash-agent')->name('core.llms');
        });
    }

    /**
     * A rate moved, or a currency went away — announced from the MODEL, so that
     * every way of changing one announces it: the admin form today, a nightly
     * job pulling rates from a central bank tomorrow, a seeder, an import.
     *
     * Core does not know who cares. What it knows is that amounts elsewhere are
     * derived from these numbers — a product priced in hryvnia keeps its
     * hryvnia number and has its books amount recomputed — and that deriving
     * them is not core's business.
     */
    /**
     * The bell says when the rates stopped moving (Currency\RateFreshness):
     * a source that quietly broke leaves last month's rate looking exactly
     * like today's, and a nightly statement is read the next morning at best.
     */
    protected function watchRates(): void
    {
        hook_listen('core.admin.notifications', function (array $rows, array $context) {
            if (! ($context['user'] ?? null)?->can('currencies.manage')) {
                return $rows;
            }

            $stale = app(\App\Core\Currency\RateFreshness::class)->stale();

            return $stale->isEmpty() ? $rows : [...$rows, [
                'key' => 'stale_rates',
                'label' => __('Exchange rates not updated: :codes', ['codes' => $stale->pluck('code')->implode(', ')]),
                'count' => $stale->count(),
                'url' => route('admin.currencies.index'),
                'icon' => 'refresh-cw-off',
            ]];
        });
    }

    /**
     * The bell says when background work went wrong where nobody is looking:
     * a failed job (an order email that never left is one), and a task whose
     * worker died and whose row would otherwise say "running" for ever. A
     * queue with no worker at all is said on the tasks screen, not here —
     * on a dev box that is every day.
     */
    protected function watchTasks(): void
    {
        hook_listen('core.admin.notifications', function (array $rows, array $context) {
            if (! ($context['user'] ?? null)?->can('tasks.manage')) {
                return $rows;
            }

            $tasks = app(\App\Core\Tasks\Tasks::class);

            return [...$rows, [
                'key' => 'failed_jobs',
                'label' => __('Background jobs that failed'),
                'count' => $tasks->failedJobs(),
                'url' => route('admin.tasks', ['tab' => 'jobs']),
                'icon' => 'circle-x',
            ], [
                'key' => 'stale_tasks',
                'label' => __('Tasks whose worker stopped'),
                'count' => $tasks->staleCount(),
                'url' => route('admin.tasks', ['tab' => 'failed']),
                'icon' => 'hourglass',
            ]];
        });
    }

    /**
     * The bell says when the update server has a newer version (PLAN §45.8).
     * It reads the last check, never asks the server itself: a page of the
     * admin must not wait on somebody else's host.
     */
    protected function watchUpdates(): void
    {
        hook_listen('core.admin.notifications', function (array $rows, array $context) {
            if (! ($context['user'] ?? null)?->can('system.manage')) {
                return $rows;
            }

            $offer = app(\App\Core\Updates\UpdateServer::class)->offer();

            return $offer === null ? $rows : [...$rows, [
                'key' => 'core_update',
                'label' => __('Torgash :version is available', ['version' => $offer['version']]),
                'count' => 1,
                'url' => route('admin.system').'#updates',
                'icon' => 'download',
            ]];
        });
    }

    protected function announceCurrencyChanges(): void
    {
        Currency::updated(function (Currency $currency) {
            // The books currency shown with more or fewer places: a price
            // derived from another currency is rounded to them (catalog's
            // Prices), so every derived price is due again.
            if ($currency->wasChanged('decimals') && $currency->code === shop_currency()) {
                app(Currencies::class)->flush();

                hook_action('core.currency.precision_changed', ['currency' => $currency, 'code' => $currency->code]);
            }

            if (! $currency->wasChanged('rate')) {
                return;
            }

            // Before anything is told: the memo is a snapshot of the table as
            // it was at the first read this request, and every listener below
            // is about to do arithmetic with a rate. Recomputing prices from
            // the number that just stopped being true is a no-op that looks
            // like a working feature.
            app(Currencies::class)->flush();

            hook_action('core.currency.rate_changed', [
                'currency' => $currency,
                'code' => $currency->code,
                'from' => (float) $currency->getOriginal('rate'),
                'to' => (float) $currency->rate,
            ]);
        });

        Currency::deleted(function (Currency $currency) {
            app(Currencies::class)->flush();

            hook_action('core.currency.deleted', ['currency' => $currency, 'code' => $currency->code]);
        });
    }

    /**
     * The one place the framework scheduler is wired. Core puts its own
     * periodic work here; a module adds its own by listening on `core.schedule`
     * and calling methods on the Schedule it is handed — the same shape as
     * adding routes or a menu item, and without patching this file.
     *
     * Nothing here runs until something resolves the scheduler, i.e. a cron
     * calling `php artisan schedule:run` every minute. Arranging that cron is a
     * deployment step the project still owns — see docs/PLAN.md §13.2.
     */
    protected function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Finished tracking rows accumulate. The admin has a "Clear
            // finished" button; this is the same sweep on a timer, keeping a
            // month so "did last week's import work" is still answerable.
            $schedule->call(fn () => BackgroundTask::query()
                ->whereIn('status', BackgroundTask::FINISHED)
                ->where('finished_at', '<', now()->subDays(30))
                ->delete()
            )->daily()->name('core:prune-background-tasks');

            // Codes older than a month: the log is about what is happening now.
            $schedule->call(fn () => \App\Core\Verification\Verifier::prune())
                ->dailyAt('03:20')->name('core:prune-verification-codes');

            // Automatic redirects nobody uses any more (core setting
            // redirect_forget_months); typed and imported ones stay.
            $schedule->call(fn () => app(RedirectManager::class)->prune())
                ->dailyAt('03:40')->name('core:prune-redirects');

            // Kept pages past their time or thrown away (PLAN §61): never
            // served, only taking room.
            $schedule->call(fn () => app(PageCache\PageCache::class)->prune())
                ->everyFifteenMinutes()->name('core:prune-page-cache');

            // The scheduler's pulse: Admin → System reads its age to say
            // whether cron runs at all (nothing else would notice it stop).
            $schedule->call(fn () => \Illuminate\Support\Facades\Cache::forever(\App\Core\System\SystemFacts::HEARTBEAT, time()))
                ->everyMinute()->name('core:heartbeat');

            // The queue from cron alone (PLAN §54): a worker that empties the
            // queue and stops inside the minute. Never two at once — a long
            // job keeps the lock, and the next minutes simply wait for it.
            // A shop with a worker of its own says QUEUE_WORKER=external.
            if (config('torgash.queue_worker') === 'scheduler' && config('queue.default') !== 'sync') {
                $schedule->command('queue:work', ['--stop-when-empty', '--max-time=50', '--tries=3'])
                    ->everyMinute()->withoutOverlapping()->runInBackground()->name('core:queue');
            }

            // Backups by the shop's own schedule (PLAN §45).
            \App\Core\Backups\Backups::schedule($schedule);

            $this->scheduleRates($schedule);

            // Once a day the shop asks the update server what is newer; the
            // answer waits in Admin → System and the bell. Asking is all it
            // does — installing stays the owner's decision. The minute is the
            // shop's own (from APP_KEY) so a thousand shops do not knock at
            // 04:00 sharp.
            if (app(\App\Core\Updates\UpdateServer::class)->configured()) {
                $minute = crc32((string) config('app.key')) % 60;
                $schedule->call(fn () => app(\App\Core\Updates\UpdateServer::class)->check())
                    ->dailyAt(sprintf('04:%02d', $minute))->name('core:update-check');
            }

            hook_action('core.schedule', ['schedule' => $schedule]);
        });
    }

    /**
     * Today's exchange rates, at the frequency the shop asked for — and not at
     * all while it updates them by hand, which is the default.
     *
     * `startIfIdle` rather than `start`: an hourly schedule against a slow feed
     * must not stack runs on top of each other, and the queue has no supervisor
     * watching that for us.
     */
    protected function scheduleRates(Schedule $schedule): void
    {
        if ((string) setting(CoreSettings::ID, 'rates_source', '') === '') {
            return;
        }

        $event = $schedule->call(fn () => FetchRates::startIfIdle())->name('core:fetch-rates');

        match ((string) setting(CoreSettings::ID, 'rates_frequency', 'daily')) {
            'hourly' => $event->hourly(),
            'six_hours' => $event->everySixHours(),
            'weekly' => $event->weekly(),
            // A central bank publishes once a working day, and early morning is
            // before the shop's own day starts.
            default => $event->dailyAt('05:00'),
        };
    }
}
