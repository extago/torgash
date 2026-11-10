<?php

namespace App\Core\Http;

use App\Models\Storefront;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Links are written on the shop's own address, never on one a request brought.
 *
 * `Host` is a line the sender writes, and every absolute address the engine
 * builds while answering starts from it. Some of those leave the page: the
 * button of a letter, a password link, what a payment gateway is told to call
 * back. Where the web server hands the shop any host (a default site, a bare
 * IP), "I lost my password" posted with somebody else's domain in that line
 * made the shop send its owner a genuine letter whose button led to that
 * domain, token and all.
 *
 * The request is still answered: a shop opened by its IP, or on a name the
 * owner has not added yet, goes on working (`Storefront::resolveByHost` draws
 * the default storefront) — only what it writes goes to APP_URL. A host is the
 * shop's when APP_URL or a storefront names it, a `www.` or any other subdomain
 * of such a name, or this machine: none of those can be somebody else's.
 *
 * Not the framework's trustHosts(): that answers 400 to everything else,
 * which is exactly the shop opened by its IP. And nothing is forced while
 * APP_URL names this machine — a shop deployed without one has no better
 * address to offer than the one it was asked on.
 */
class ForeignHost
{
    /** On a request whose host the shop does not know. */
    public const ATTRIBUTE = 'torgash.foreign_host';

    protected const THIS_MACHINE = ['localhost', '127.0.0.1', '::1', '[::1]'];

    public function handle(Request $request, Closure $next): Response
    {
        $home = rtrim((string) config('app.url'), '/');

        if ($this->homeIsReal($home) && ! $this->known($request->getHost())) {
            $request->attributes->set(self::ATTRIBUTE, true);
            URL::forceRootUrl($home);
            URL::forceScheme((string) parse_url($home, PHP_URL_SCHEME));
        }

        return $next($request);
    }

    public function known(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        if (in_array($host, self::THIS_MACHINE, true) || $this->covers($this->homeHost(), $host)) {
            return true;
        }

        try {
            $domains = Storefront::query()->pluck('domains')->flatten()->all();
        } catch (Throwable) {
            return true; // no tables yet: nothing to send, nobody to send it to
        }

        foreach ($domains as $domain) {
            if ($this->covers(strtolower((string) $domain), $host)) {
                return true;
            }
        }

        return false;
    }

    protected function homeIsReal(string $home): bool
    {
        return preg_match('#^https?://#i', $home) === 1
            && $this->homeHost() !== ''
            && ! in_array($this->homeHost(), self::THIS_MACHINE, true);
    }

    protected function homeHost(): string
    {
        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /** The name itself, a subdomain of it, or the bare name of a `www.` one. */
    protected function covers(string $known, string $host): bool
    {
        return $known !== '' && ($host === $known || str_ends_with($host, '.'.$known) || 'www.'.$host === $known);
    }
}
