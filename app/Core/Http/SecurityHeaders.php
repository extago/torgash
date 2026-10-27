<?php

namespace App\Core\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The security headers, set by the application itself (audit 2026-10-15:
 * OSPanel's Caddy added some of them, so another server would have sent
 * none). A header the server already set is left alone: whoever configured
 * it there knew why.
 *
 * - nosniff: a file is what its Content-Type says, never what it looks like.
 * - Referrer-Policy: other sites learn which shop sent the visitor, not the
 *   page (an order's address carries its key).
 * - frame-ancestors / X-Frame-Options: the shop is not drawn inside somebody
 *   else's page (clickjacking the buy button or the admin). Its own frames —
 *   the theme preview, a letter's preview — are the same origin.
 * - Permissions-Policy: no camera, microphone or USB; location only for the
 *   shop itself (the "your town" window asks for it).
 * - HSTS: only over https and never on a development host, where a year of
 *   "https only" for torgash.local would outlive any certificate.
 *
 * The content security policy (which scripts may run, App\Core\Http\Csp)
 * is sent on every page, enforced unless the shop chose otherwise, PLAN §53.
 */
class SecurityHeaders
{
    public const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(self), usb=(), serial=(), bluetooth=()',
    ];

    /** Half a year: long enough to matter, short enough to undo a mistake. */
    public const HSTS = 'max-age=15552000';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($request->isSecure() && ! self::developmentHost($request->getHost()) && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', self::HSTS);
        }

        // Which scripts a PAGE may run (PLAN §53): enforced, report-only or
        // off, as the shop chose (Core → Content).
        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            && ! $response->headers->has('Content-Security-Policy')
            && ! $response->headers->has('Content-Security-Policy-Report-Only')
            && ($header = app(Csp::class)->header()) !== null) {
            $response->headers->set($header, app(Csp::class)->policy());
        }

        // Which PHP answers is nobody's business; PHP adds it at the SAPI.
        $response->headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }

    public static function developmentHost(string $host): bool
    {
        $host = strtolower($host);

        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || preg_match('/\.(local|localhost|test)$/', $host) === 1;
    }
}
