<?php

namespace Torgash\Api;

use App\Core\Support\ClientAddress;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Api\Http\Authenticate;
use Torgash\Api\Http\ResolveContext;
use Torgash\Api\Models\ApiToken;

class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // What API clients hold is on no guest's page (PLAN §61).
        page_cache()->unseen('m_api_tokens');

        $this->throttle();

        // NOT the `web` group: a machine has no session, no CSRF token and no
        // locale prefix, and `web` would answer its call with a 419 that looks,
        // from the other end, exactly like the shop being down. Same reasoning
        // as the payments callback route.
        //
        // `throttle:api` stays: a runaway integration is the ordinary way an
        // API takes a shop down, and noticing it is not the shop owner's job.
        Route::middleware(['throttle:torgash-api', Authenticate::class, ResolveContext::class])
            ->group(dirname(__DIR__).'/routes.php');

        // The shop half carries no Authenticate: it is as public as the
        // storefront's own pages, and a cart is protected by its own token.
        Route::middleware(['throttle:torgash-api', ResolveContext::class])
            ->group(dirname(__DIR__).'/routes-shop.php');

        Route::middleware(['web', 'auth', 'can:api.manage'])
            ->prefix('admin/api')
            ->name('admin.api.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // Core's map for machine readers points at the description the API
        // writes about itself. Two lines, because everything they describe
        // already exists: a client that finds `openapi.json` needs no prose
        // from us, and a client that never finds it reads the catalogue as
        // text instead.
        hook_listen('core.llms.sections', fn (array $sections) => [...$sections, [
            'title' => __('Programmatic access'),
            'position' => 20,
            'items' => [
                [
                    'label' => __('OpenAPI description'),
                    'url' => route('api.openapi'),
                    'note' => __('generated from what the shop actually exposes, so it cannot describe an endpoint that is not there'),
                ],
                [
                    'label' => __('Public shop API'),
                    'url' => url('/api/v2/shop/products'),
                    'note' => __('products and categories as JSON, plus a cart and a checkout addressed by a token — no key needed, exactly like the storefront pages'),
                ],
            ],
        ]]);
    }

    /**
     * Our own limiter, named after the module: Laravel's stock `api` one is
     * registered by `install:api`, which this project never ran, and defining a
     * limiter under that name would be this module deciding something for
     * everybody.
     *
     * A token this shop ISSUED has its allowance to itself: two integrations
     * behind one office NAT must not throttle each other, and one runaway
     * script must not take the others down with it. Anything else counts
     * against the caller's address, a made-up token included. The limiter runs
     * before authentication, and keying it on the string as sent gave every
     * request with a new random token an allowance of its own.
     */
    protected function throttle(): void
    {
        RateLimiter::for('torgash-api', function (Request $request) {
            $perMinute = max(1, (int) setting('torgash.api', 'rate_limit', 120));
            $hash = $request->bearerToken() ? ApiToken::hash($request->bearerToken()) : null;

            $key = $hash !== null && ApiToken::where('token_hash', $hash)->exists()
                ? 'token:'.$hash
                : 'ip:'.ClientAddress::subject($request->ip());

            return Limit::perMinute($perMinute)->by($key);
        });
    }
}
