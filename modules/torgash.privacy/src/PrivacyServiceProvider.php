<?php

namespace Torgash\Privacy;

use App\Core\Content\Placeholders;
use App\Core\Privacy\PersonalData;
use App\Core\Privacy\Subject;
use App\Core\Storefronts\StorefrontRoutes;
use App\Core\Support\PerRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Privacy\Models\ConsentRecord;
use Torgash\Privacy\Models\PrivacyRequest;

/**
 * Personal data (PLAN §49). Everything here answers a seam core owns:
 *
 * - `core.consent.allows` — may this visitor run statistics / marketing /
 *   functional code? (`ConsentState`, from their choice cookie and the mode);
 * - `core.consent.given` — somebody agreed to something: written down;
 * - `core.personal_data` — the registry every module describes its own data
 *   in; this module walks it to export, erase and expire, and names nobody's
 *   tables;
 * - `core.head` / `core.footer` — Google Consent Mode before any tag, the
 *   banner and its settings window at the end.
 */
class PrivacyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One visitor's choice — per request, forgotten with it.
        $this->app->singleton(ConsentState::class);
        PerRequest::forget($this->app, ConsentState::class);
    }

    public function boot(): void
    {
        hook_listen('core.consent.allows', fn (bool $allowed, array $context) => $allowed
            && app(ConsentState::class)->allows((string) ($context['category'] ?? '')));

        hook_listen('core.consent.given', fn (array $context) => $this->record($context));

        hook_listen('core.personal_data', fn (array $context) => $this->describeOwnData($context['registry']));

        // Before every snippet (they listen at the default 10): Google tags
        // must find the consent defaults already set.
        hook_listen('core.head', fn (string $html) => $html.$this->consentModeDefaults(), 1);

        hook_listen('core.footer', function (string $html) {
            return setting('torgash.privacy', 'consent_mode', 'opt_in') === 'off'
                ? $html
                : $html.view('torgash.privacy::banner', ['consent' => app(ConsentState::class)])->render();
        });

        // "#cookie-settings" anywhere — a footer menu item, a policy page —
        // opens the choice again.
        app(Placeholders::class)->register('cookie_settings_link', [
            'label' => 'Cookie settings link',
            'group' => 'shop',
            'resolve' => fn () => '#cookie-settings',
        ]);

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        Route::middleware(['web', 'auth', 'can:privacy.manage'])
            ->prefix('admin/privacy')
            ->name('admin.privacy.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        hook_listen('core.account.nav', fn (array $items) => [...$items, [
            'label' => __('My data'),
            'group' => 'account',
            'url' => route('account.privacy.index'),
            'icon' => 'shield-user',
            'active' => request()->routeIs('*account.privacy.*'),
        ]]);

        hook_listen('core.admin.notifications', function (array $rows, array $context) {
            if (! ($context['user'] ?? null)?->can('privacy.manage')) {
                return $rows;
            }

            return [...$rows, [
                'key' => 'privacy',
                'label' => __('Personal data requests'),
                'count' => PrivacyRequest::query()->open()->count(),
                'url' => route('admin.privacy.requests.index'),
                'icon' => 'shield-user',
            ]];
        });

        hook_listen('core.schedule', function (array $context) {
            $context['schedule']->call(fn () => app(Retention::class)->run())
                ->daily()->name('privacy:retention');
        });
    }

    /** @param  array<string, mixed>  $context */
    protected function record(array $context): void
    {
        ConsentRecord::create([
            'user_id' => $context['user_id'] ?? auth()->id(),
            'kind' => (string) ($context['kind'] ?? 'other'),
            'categories' => $context['categories'] ?? null,
            'text' => $context['text'] ?? null,
            'email' => $context['email'] ?? null,
            'phone' => $context['phone'] ?? null,
            'ip' => request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Google Consent Mode v2: the defaults for THIS visitor, before any tag.
     * The banner sends the update when they choose.
     */
    protected function consentModeDefaults(): string
    {
        if (! setting('torgash.privacy', 'google_consent_mode', true)
            || setting('torgash.privacy', 'consent_mode', 'opt_in') === 'off') {
            return '';
        }

        $state = app(ConsentState::class);
        $grant = fn (string $category) => $state->allows($category) ? 'granted' : 'denied';

        $defaults = [
            'ad_storage' => $grant('marketing'),
            'ad_user_data' => $grant('marketing'),
            'ad_personalization' => $grant('marketing'),
            'analytics_storage' => $grant('statistics'),
            'functionality_storage' => $grant('functional'),
            'personalization_storage' => $grant('functional'),
            'security_storage' => 'granted',
            'wait_for_update' => 500,
        ];

        return '<script nonce="'.csp_nonce().'">window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'
            ."gtag('consent','default',".json_encode($defaults).');</script>'."\n";
    }

    protected function describeOwnData(PersonalData $registry): void
    {
        $registry->register('consents', [
            'label' => 'Consents given',
            'export' => fn (Subject $s) => $s->scope(ConsentRecord::query())->orderBy('id')->get()
                ->map(fn (ConsentRecord $r) => [
                    'when' => $r->created_at?->toIso8601String(),
                    'kind' => $r->kind,
                    'categories' => $r->categories,
                    'text' => $r->text,
                ])->all(),
            'erase' => fn (Subject $s) => $s->scope(ConsentRecord::query())->delete(),
        ]);

        // The record stays as proof; its IP is what expires.
        $registry->register('consent_ip', [
            'label' => 'IP addresses in the record of consents',
            'default_days' => 365,
            'retention' => fn (CarbonInterface $before) => ConsentRecord::where('created_at', '<', $before)
                ->whereNotNull('ip')->update(['ip' => null]),
        ]);

        $registry->register('privacy_requests', [
            'label' => 'Requests about personal data',
            'export' => fn (Subject $s) => $s->scope(PrivacyRequest::query())->orderBy('id')->get()
                ->map(fn (PrivacyRequest $r) => [
                    'when' => $r->created_at?->toIso8601String(),
                    'type' => $r->type,
                    'status' => $r->status,
                ])->all(),
        ]);
    }
}
