<?php

namespace Torgash\Guard;

use App\Core\Storefronts\StorefrontRoutes;
use App\Core\Support\FormGuard;
use App\Models\CustomerGroup;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;
use Torgash\Guard\Audit\Auditor;
use Torgash\Guard\Blocking\Blocks;
use Torgash\Guard\Blocking\CheckBlock;
use Torgash\Guard\Blocking\Signals;
use Torgash\Guard\Errors\Recorder;
use Torgash\Guard\Models\AuditEntry;
use Torgash\Guard\Models\Block;
use Torgash\Guard\Models\ErrorGroup;

/**
 * The watchman.
 *
 * Planned in four parts (PLAN §25.2), in order of value and rising risk:
 * collecting errors, auditing what changes in the admin, watching invariants,
 * and blocking — plus the executable-file guard (§26.3) and, since the owner
 * asked for it, CAPTCHA on the public forms. Each switched on separately,
 * because a guard nobody can turn down is a guard that gets uninstalled.
 *
 * All of them are here now, and so is a seventh the plan did not have: the
 * SECOND FACTOR (§25.2/7), which is the first part of this module that stands
 * between a person and the shop rather than watching or counting. Errors were
 * first and are deliberately the cheapest: that runs only on the path where
 * something has ALREADY gone wrong, so a shop that never errs pays nothing for
 * it. The AUDIT costs one row per change, and only for requests that came in
 * through the admin. The two that can shut a door — blocking and the second
 * factor — each have a console command for getting back in, because the way
 * out of a locked admin cannot be inside it.
 *
 * What it does not do is duplicate `App\Core\Support\FormGuard`. The public
 * forms are already covered — honeypot, form-age stamp, rate limits — and this
 * module may only ADD checks through the `core.form.check` hook that exists for
 * exactly that. A second spam guard is how two of them start disagreeing.
 */
class GuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The trail's knobs live in the .env, out of the admin's reach
        // (config/audit.php says why).
        $this->mergeConfigFrom(dirname(__DIR__).'/config/audit.php', 'guard.audit');

        // Singletons because the re-entrancy flag has to be shared: two
        // instances would each think they were the first one in, which is the
        // one thing that flag exists to prevent. The auditor also counts what
        // it has written this request, which only means anything if there is
        // one of it.
        $this->app->singleton(Recorder::class);
        $this->app->singleton(Auditor::class);

        // Not for state — it has none — but because every request in the `web`
        // group asks it a question, and building it per resolution to answer
        // one cached lookup is machinery for nothing.
        $this->app->singleton(Blocks::class);

        // Same reason: every authenticated request in that group asks the
        // requirement a question, and it holds no state of its own either.
        $this->app->singleton(TwoFactor\Requirement::class);
    }

    public function boot(): void
    {
        // What it keeps about a person, for core's personal-data registry (PLAN §49).
        hook_listen('core.personal_data', fn (array $context) => GuardPersonalData::register($context['registry']));

        // A LISTENER, not a patch of bootstrap/app.php — that is what keeps
        // this a module rather than a fork of the skeleton. It also catches
        // more than an exception handler would: a Log::error() somebody wrote
        // by hand inside a queued job reaches here too.
        Event::listen(MessageLogged::class, fn (MessageLogged $event) => app(Recorder::class)->handle($event));

        Route::middleware(['web', 'auth', 'can:guard.manage'])
            ->prefix('admin/guard')
            ->name('admin.guard.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        $this->audit();
        $this->invariants();
        $this->blocking();
        $this->prune();
        $this->captcha();
        $this->twoFactor();

        if ($this->app->runningInConsole()) {
            // Rule three of PLAN §25.2/4: the way back in lives outside the
            // admin, because the admin is behind the door this module closes.
            $this->commands([
                Console\BlocksCommand::class,
                Console\UnblockCommand::class,
                // ...and the same rule for the second factor: a door that can
                // close on the person holding the key needs a way out that is
                // not behind it.
                Console\TwoFactorCommand::class,
            ]);
        }
    }

    /**
     * The last part, and the only one that can do harm (PLAN §25.2/4).
     *
     * Everything here is three lines because the decisions are in
     * `Blocking\Blocks`, but the placement of the first one IS the module's
     * first rule: the check is pushed onto the `web` group and nowhere else.
     * `/commerceml`, `/1c_exchange.php`, the payment callbacks and `/api/v2/*`
     * are all registered outside `web` — they are machines with no session —
     * so a request that presents credentials cannot be touched by this even by
     * mistake, and no future gateway module has to remember to ask for an
     * exemption.
     *
     * The three signals are all somebody else's announcements. A wrong password
     * is Laravel's own `Failed` event, so both doors are covered without either
     * controller knowing this module exists; a refused public form is core's
     * `FormGuard` saying so; a bearer token that names nobody is the API
     * module's. The last one is deliberately narrow — a call with NO token is a
     * misconfigured integration and an EXPIRED one is yesterday's valid
     * credential, while a token that never existed is a guess.
     */
    protected function blocking(): void
    {
        // Through the KERNEL, not `$router->pushMiddlewareToGroup()`, and this
        // is measured rather than a preference: the HTTP kernel holds the
        // groups and copies them onto the router when it is resolved
        // (`syncMiddlewareToRouter`). Push onto the router from a provider and
        // whichever of the two happens later wins — in production the kernel
        // resolves before the providers boot and the push survives, while under
        // the test suite it resolves at the first request and silently wipes
        // it. Appending to the kernel is the same statement in the one place
        // that cannot be overwritten, and it dedupes itself. Both orders are
        // covered: a kernel already resolved is told now, one resolved later
        // is told then.
        $append = function ($kernel) {
            if (method_exists($kernel, 'appendMiddlewareToGroup')) {
                $kernel->appendMiddlewareToGroup('web', CheckBlock::class);
            }
        };

        $this->app->afterResolving(HttpKernel::class, $append);

        if ($this->app->resolved(HttpKernel::class)) {
            $append($this->app->make(HttpKernel::class));
        }

        Event::listen(Failed::class, fn () => app(Blocks::class)->report(Signals::LOGIN));

        hook_listen('core.form.refused', fn (array $context) => app(Blocks::class)
            ->report(Signals::FORM, $context['request'] ?? null));

        hook_listen('core.code.failed', fn (array $context) => app(Blocks::class)
            ->report(Signals::CODE, $context['request'] ?? null));

        hook_listen('api.auth.failed', function (array $context) {
            if (($context['reason'] ?? null) === 'unknown_token') {
                app(Blocks::class)->report(Signals::API, $context['request'] ?? null);
            }
        });
    }

    /**
     * The trail (PLAN §25.2/2): one row per change made through the admin.
     *
     * Four narrow listeners rather than `eloquent.*`, and the difference is not
     * style: the wildcard would also catch `retrieved`, which fires for every
     * row the admin ever hydrates — a list of thirty products with their media
     * is hundreds of calls into a listener that would discard every one.
     *
     * Settings and modules are announced by core because it keeps them in plain
     * tables with no Eloquent underneath, and they are among the most
     * audit-worthy things in the admin. The listeners RESOLVE the auditor
     * inside themselves rather than capturing it: a boot-time closure outlives
     * the request, and a captured per-request singleton is a static wearing a
     * different hat.
     */
    protected function audit(): void
    {
        foreach (Auditor::EVENTS as $event) {
            Event::listen("eloquent.{$event}: *", function (string $name, array $payload) use ($event) {
                if (($payload[0] ?? null) instanceof Model) {
                    app(Auditor::class)->model($event, $payload[0]);
                }
            });
        }

        hook_listen('core.setting.changed', function (array $context) {
            // The storefront is part of the address, not a detail: a setting
            // overridden for one shop and the global one behind it are two
            // different things with the same name.
            $storefront = $context['storefront_id'] ?? null;
            $id = $context['module'].'.'.$context['key'].($storefront ? '@'.$storefront : '');

            app(Auditor::class)->note(
                'updated',
                'setting',
                $id,
                $context['module'].' → '.$context['key'],
                [$context['key'] => ['from' => $context['from'], 'to' => $context['to']]],
            );
        });

        hook_listen('core.module.changed', function (array $context) {
            app(Auditor::class)->note(
                $context['action'],
                'module',
                $context['module'],
                $context['module'],
            );
        });
    }

    /**
     * The watched invariants (PLAN §25.2/3).
     *
     * This module ships only the statements about CORE's data — currencies,
     * storefronts, customer groups — because core has no module to speak for
     * it. Everything else is said by whoever owns the table: checkout about an
     * order, warehouses about a stock row, catalog about a price. They answer
     * the same filter and depend on nothing here; a module that never heard of
     * the guard simply goes unwatched, which is the honest outcome and the
     * reason the registry is a hook rather than a list kept in this class.
     *
     * The nightly run is a plain `->call()`, not a queued job: every check is
     * capped, so the whole sweep is a second's work, and a button that needs a
     * queue worker running is a button that does nothing on most installs.
     */
    protected function invariants(): void
    {
        hook_listen('guard.invariants', fn (array $all) => $all
            + (new Invariants\CoreInvariants)->register()
            + (new Files\Uploads)->register());

        hook_listen('core.schedule', function (array $context) {
            $context['schedule']
                ->call(function () {
                    if (setting('torgash.guard', 'invariants_nightly', true)) {
                        app(Invariants\Invariants::class)->run();
                    }
                })
                ->dailyAt('04:10')
                ->name('guard:check-invariants')
                ->withoutOverlapping();
        });
    }

    /**
     * The CAPTCHA: the one addition this module is allowed to make to the
     * public forms.
     *
     * Core owns the honeypot, the form-age stamp and the rate limits, and says
     * plainly that a CAPTCHA is not its business — it is a tax on every real
     * customer, and the shops that need one need a specific one. So core
     * offers `core.form.check` and this answers it, and a shop that wants
     * Turnstile installs a module that registers itself in the same registry
     * the built-in arithmetic one uses.
     */
    protected function captcha(): void
    {
        hook_listen('guard.captcha.providers', fn (array $providers) => $providers + [
            Captcha\ArithmeticCaptcha::key() => Captcha\ArithmeticCaptcha::class,
        ]);

        // The picture. `web` for the session — fetching the image is what
        // creates the challenge, and the answer lives in the session.
        Route::middleware('web')
            ->get('/captcha/{form}/{nonce}.png', Http\CaptchaController::class)
            ->name('guard.captcha.image');

        // Drawn into whatever form asks, through the component every guarded
        // form now renders. A form nobody ticked gets '' and no markup at all.
        hook_listen('core.form.fields', fn (string $value, array $context) => $value
            .app(Captcha\Gate::class)->widget((string) ($context['form'] ?? '')));

        // ...and checked on the way back in, by the same gate, so a visitor who
        // was never shown the box is never refused for leaving it empty.
        hook_listen('core.form.check', function (?string $value, array $context) {
            return $value ?? app(Captcha\Gate::class)->check(
                $context['request'],
                (string) ($context['form'] ?? ''),
                (bool) ($context['keep'] ?? false),
            );
        });

        // A form that keeps a right answer across a refusal (the checkout)
        // says when it went through, and the answer is spent.
        hook_listen('core.form.accepted', fn (array $context) => app(Captcha\Gate::class)
            ->spend($context['request'], (string) ($context['form'] ?? '')));

        $this->settingFields();
    }

    /**
     * The settings whose options only exist in the shop's own data: which
     * forms it actually has, which customer groups it trusts, which permission
     * groups it has invented. None of them can be in manifest.json — a module
     * adds a form, a shop adds a group — so they come through
     * `core.settings.fields`, which core applies both on the way to the screen
     * and on the way back.
     *
     * The two group pickers are two different vocabularies on purpose (see
     * `TwoFactor\Requirement`): a permission group is "the people who can edit
     * prices", a customer group is "the wholesale accounts", and demanding a
     * second factor of either is a sentence the other cannot say.
     */
    protected function settingFields(): void
    {
        hook_listen('core.settings.fields', function (array $fields, array $context) {
            if (($context['module'] ?? null) !== 'torgash.guard') {
                return $fields;
            }

            $forms = FormGuard::forms();
            $groups = CustomerGroup::orderBy('id')->get()
                ->mapWithKeys(fn (CustomerGroup $g) => [$g->id => $g->t('name')])
                ->all();
            // The built-in group by the name the screens give it, not its code.
            $roles = Role::orderBy('name')->get()->mapWithKeys(fn (Role $r) => [$r->id => $r->name === \App\Core\Access\AccessGuard::SUPER_ROLE ? __('Administrator') : $r->name])->all();
            $providers = app(Captcha\Captchas::class)->options();

            return array_map(function (array $field) use ($forms, $groups, $roles, $providers) {
                $field['options'] = match ($field['key']) {
                    'captcha_forms' => $forms,
                    'captcha_exempt_groups', 'two_factor_customer_groups' => $groups,
                    'two_factor_staff_groups' => $roles,
                    'captcha_provider' => $providers,
                    default => $field['options'] ?? null,
                };

                return array_filter($field, fn ($v) => $v !== null);
            }, $fields);
        });
    }

    /**
     * The second factor (PLAN §25.2/7).
     *
     * Everything about it is in this module and nothing about it is in core,
     * which was the owner's call and is the better one: `users` gains no
     * columns, so a shop that decides against the whole idea uninstalls the
     * module and the secrets leave with it. What core lent instead is two
     * MENU hooks — `core.account.nav`, which already existed for the
     * enquiries module, and `core.admin.user_nav`, added with this and the
     * same shape — so the two Security pages are reachable without core ever
     * naming this module.
     *
     * The check is a middleware in the `web` group, placed for the same reason
     * `CheckBlock` is: everything credentialed in this engine is registered
     * outside `web` and takes no session, so the rule covers every page a
     * person can see and nothing a machine talks to. See `RequireTwoFactor`
     * for why it stands there rather than inside the login form.
     */
    protected function twoFactor(): void
    {
        $append = function ($kernel) {
            if (method_exists($kernel, 'appendMiddlewareToGroup')) {
                $kernel->appendMiddlewareToGroup('web', TwoFactor\RequireTwoFactor::class);
            }
        };

        // Both orders covered, exactly as in blocking() above: a kernel that
        // has already been resolved is told now, one resolved later is told
        // then. Pushing onto the router instead is what silently loses the
        // middleware under the test suite.
        $this->app->afterResolving(HttpKernel::class, $append);

        if ($this->app->resolved(HttpKernel::class)) {
            $append($this->app->make(HttpKernel::class));
        }

        // The code box and the customer's Security page, in every locale shape.
        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        // The staff one, and deliberately NOT inside this module's
        // `can:guard.manage` group: every manager must be able to secure their
        // own account, while only a few of them can read the audit trail.
        Route::middleware(['web', 'auth', 'can:admin.access'])
            ->prefix('admin/guard')
            ->name('admin.guard.')
            ->group(function () {
                $c = Http\Admin\SecurityController::class;

                Route::get('security', [$c, 'show'])->name('security');
                Route::post('security', [$c, 'start'])->name('security.start');
                Route::post('security/confirm', [$c, 'confirm'])->name('security.confirm');
                Route::post('security/codes', [$c, 'codes'])->name('security.codes');
                Route::post('security/codes/new', [$c, 'newCodes'])->name('security.codes.new');
                Route::delete('security', [$c, 'disable'])->name('security.disable');
            });

        $this->twoFactorMenus();

        // A sign-in carried to another of the shop's addresses (core's
        // Handoff) carries what this session proved too — otherwise the theme
        // configurator of a second storefront asks for the code again. Only a
        // stamp that is the ticket owner's own: holds() compares the user.
        hook_listen('core.auth.handoff', function (array $session, array $context) {
            $stamp = app(TwoFactor\Stamp::class);

            return $stamp->holds($context['user'])
                ? $session + [TwoFactor\Stamp::KEY => (array) session(TwoFactor\Stamp::KEY)]
                : $session;
        });

        hook_listen('core.auth.handed_over', fn (array $context) => app(Auditor::class)->note(
            'signed_in',
            'storefront',
            (string) $context['storefront']->getKey(),
            $context['storefront']->name.' → '.$context['path'],
        ));
    }

    /**
     * The two ways in, both of them a LIST item answered into core's own menu
     * rather than markup appended somewhere.
     *
     * Neither is drawn for somebody the shop is not offering this to, which is
     * what makes `two_factor_customers = off` mean what it says: no page, no
     * item, nothing to explain to a customer who asks what it is.
     */
    protected function twoFactorMenus(): void
    {
        hook_listen('core.account.nav', function (array $items) {
            $user = auth()->user();

            if (! $user || ! app(TwoFactor\Requirement::class)->offered($user)) {
                return $items;
            }

            return [...$items, [
                'label' => __('Security'),
                'url' => route('account.security'),
                'group' => 'account',
                'icon' => 'shield-check',
                'active' => request()->routeIs('*account.security*'),
            ]];
        });

        // Who on the staff has a second factor — on the administrators list and
        // card, through core's own seam, one query for a page. Said only while
        // the shop offers it to staff at all; "off" beside every name of a
        // shop that switched the feature off would be a warning about nothing.
        hook_listen('core.admin.staff.facts', function (array $facts, array $context) {
            $ids = array_values(array_filter((array) ($context['ids'] ?? [])));

            if (! $ids || setting('torgash.guard', 'two_factor_staff', TwoFactor\Requirement::OFFERED) === TwoFactor\Requirement::OFF) {
                return $facts;
            }

            $armed = Models\TwoFactorSecret::whereIn('user_id', $ids)->confirmed()->pluck('user_id')->flip();
            $requirement = app(TwoFactor\Requirement::class);

            foreach (\App\Models\User::with('roles')->whereIn('id', $ids)->get() as $user) {
                $on = isset($armed[$user->id]);
                $required = ! $on && $requirement->required($user);

                $facts[$user->id][] = [
                    'key' => 'two_factor',
                    'label' => $on ? __('Two-factor: on') : __('Two-factor: off'),
                    'icon' => $on ? 'shield-check' : 'shield-off',
                    'tone' => $on ? 'good' : ($required ? 'bad' : null),
                    'hint' => $required ? __('Required — asked to set it up at the next sign-in') : null,
                    // Your own row leads to where you switch it; nobody else's can.
                    'url' => $user->is(auth()->user()) ? route('admin.guard.security') : null,
                ];
            }

            return $facts;
        });

        hook_listen('core.admin.user_nav', function (array $items) {
            $user = auth()->user();

            if (! $user || ! app(TwoFactor\Requirement::class)->offered($user)) {
                return $items;
            }

            return [...$items, [
                'label' => __('Security'),
                'url' => route('admin.guard.security'),
                'icon' => 'shield-check',
                'active' => request()->routeIs('admin.guard.security*'),
            ]];
        });
    }

    /**
     * Table growth is the cost of this module (§25.3), so the prune is written
     * with the feature rather than after it — both features, in one nightly
     * call: two schedules for two `delete` statements is machinery for its own
     * sake.
     *
     * Counted from when a group was LAST seen: a problem that is still
     * happening is never pruned, however old its first occurrence. Registered
     * unconditionally and no-ops on the setting — the same shape enquiries'
     * auto-close uses, and for the same reason: gating at boot would mean
     * changing the number took a fresh boot to take effect.
     */
    protected function prune(): void
    {
        hook_listen('core.schedule', function (array $context) {
            $context['schedule']
                ->call(function () {
                    $days = (int) setting('torgash.guard', 'errors_retention_days', 30);

                    if ($days > 0) {
                        ErrorGroup::where('last_seen_at', '<', now()->subDays($days))->delete();
                    }

                    // The trail's own number, and a much larger one by default:
                    // it is read months later, and ageing out is the ONLY way a
                    // row ever leaves it — the screen has no delete button.
                    $days = (int) config('guard.audit.retention_days', 180);

                    if ($days > 0) {
                        AuditEntry::where('created_at', '<', now()->subDays($days))->delete();
                    }

                    // Blocks are pruned by when they STOPPED standing, and only
                    // then: a row that is still blocking is never removed by
                    // age, and the row of an address that came back is what
                    // tells the next block it is not the first time.
                    $days = (int) setting('torgash.guard', 'block_retention_days', 30);

                    if ($days > 0) {
                        Block::query()
                            ->where('updated_at', '<', now()->subDays($days))
                            ->where(fn ($q) => $q->whereNotNull('released_at')
                                ->orWhere(fn ($q) => $q->whereNotNull('until')->where('until', '<', now())))
                            ->delete();

                        app(Blocks::class)->flush();
                    }
                })
                ->daily()
                ->name('guard:prune')
                ->withoutOverlapping();
        });
    }
}
