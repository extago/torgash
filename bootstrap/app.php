<?php

use App\Core\Seo\NormaliseUrl;
use App\Core\Seo\RedirectManager;
use App\Core\Storefronts\ResolveStorefront;
use App\Http\Middleware\SetAdminLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Module and theme files, outside the `web` group — see the file.
        then: function () {
            \Illuminate\Support\Facades\Route::group([], base_path('routes/assets.php'));
            // The web installer — outside `web` too: see the file.
            \Illuminate\Support\Facades\Route::group([], base_path('routes/install.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Before everything, so an error anywhere after it is rendered with
        // the switch already settled: APP_DEBUG pages only for this machine.
        $middleware->prepend(\App\Core\Http\DebugOnlyLocally::class);

        // Before the shop is installed, every address leads to /install.
        $middleware->prepend(\App\Core\Install\SendToInstaller::class);

        // The security headers from the application, not the web server's
        // configuration (nosniff, referrer, framing, permissions, HSTS).
        $middleware->append(\App\Core\Http\SecurityHeaders::class);

        // Bytes that are not UTF-8 are a 500 the moment they meet Postgres, so
        // a parameter of them is scrubbed (a path of them the framework has
        // already answered 400). Global and FIRST — before NormaliseUrl, which may itself look a redirect up in
        // the database, and before any route, so no controller has to know.
        $middleware->append(\App\Core\Http\ValidUtf8::class);

        // One page, one address: a 301 off /page/, //page and /Page. GLOBAL
        // rather than in the `web` group, because group middleware runs only
        // after a route has matched — and `//catalog` or `/Catalog` matches
        // nothing, which is exactly the address that needs tidying. Appended,
        // so TrustProxies has already settled what the scheme is. Every part of
        // it is off until the shop switches it on.
        $middleware->append(NormaliseUrl::class);

        $middleware->web(append: [
            ResolveStorefront::class,
            // Global (not just routes/admin.php) — module admin panels (catalog,
            // checkout, ...) register their own routes/middleware per ServiceProvider
            // and would otherwise never pick up the interface-language switch.
            SetAdminLocale::class,
        ]);
        // Admin and storefront customers share the same 'web' guard/users table
        // (role, not guard, tells them apart — see CoreServiceProvider's
        // Gate::before). So these redirects must be path-aware: an unauthenticated
        // hit on an /account/* page should bounce to the storefront login, not
        // the admin one, and vice versa. Also matches the locale-prefixed form
        // (/ru/account/*) — see App\Core\Storefronts\StorefrontRoutes.
        $accountPaths = ['account', 'account/*', '*/account', '*/account/*'];
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is(...$accountPaths) ? route('account.login') : route('admin.login'),
        );
        $middleware->redirectUsersTo(
            fn (Request $request) => $request->is(...$accountPaths) ? route('account.dashboard') : route('admin.dashboard'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A session that ran out while a page stood open (the bell polls every
        // minute) must not answer HTMX with the login page: HTMX follows the
        // redirect and swaps the whole login screen into the bell's corner.
        // The page itself goes to the login instead, and comes back to where
        // it was — the address HTMX was asking for is a fragment, not a page.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->hasHeader('HX-Request') || $request->expectsJson()) {
                return null;
            }

            $current = (string) $request->header('HX-Current-URL', '');
            if ($current !== '' && str_starts_with($current, $request->getSchemeAndHttpHost().'/')) {
                session()->put('url.intended', $current);
            }

            return response('', 401)->header('HX-Redirect', $e->redirectTo($request) ?? route('admin.login'));
        });

        // An address this shop used to serve answers with a 301 instead of a
        // 404 — the last word, after every route and every module's own
        // fallback have declined it.
        //
        // Here rather than in a fallback route, because a fallback is a single
        // slot and the pages module already holds it: two modules wanting
        // "whatever nobody else claimed" would silently take turns depending on
        // boot order. A 404 is the one place that is reached however the
        // address failed to match.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->isMethodSafe() || $request->expectsJson()) {
                return null;
            }

            $target = app(RedirectManager::class)->redirectFor($request);

            return $target === null ? null : redirect($target, 301);
        });
    })->create();
