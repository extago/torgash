<?php

namespace Torgash\CommerceMl;

/**
 * `/1c_exchange.php` — the address a decade of 1C configurations, articles and
 * support scripts have hard-coded, made to answer on any web server.
 *
 * The route for it exists (routes.php registers both spellings), but a typical
 * server never lets the request reach the application: Caddy and nginx alike
 * hand *.php to PHP only when the FILE EXISTS and answer 404 otherwise, so the
 * one address the shop's own code cannot help being asked for is the one it
 * never hears. A rewrite rule fixes that and is the honest answer, but it is a
 * manual step in every installation — and under OSPanel the Caddyfile is
 * generated, so the edit does not even survive a restart.
 *
 * So the module writes a two-line front controller into public/ when it is
 * installed and deletes it when it is removed. It is a trace in another
 * module's territory — core's public directory — which the engine's rules do
 * not allow lightly; what makes it allowed is that it is undone: an
 * uninstalled module leaves nothing, which is the promise the rule protects.
 *
 * Deleting is by CONTENT, never by name. If something else is already living
 * at that address — a real script from the engine this shop is moving from,
 * mid-migration — we neither overwrite it nor remove it, and the admin screen
 * says so rather than quietly doing nothing.
 *
 * Why the file rewrites SCRIPT_NAME: Symfony works out how much of the URL is
 * the front controller from SCRIPT_NAME, and with `/1c_exchange.php` in it the
 * whole path is the script — the request arrives at the application as `/`,
 * the homepage, with 1C's query string attached. Pointing those three
 * variables at index.php makes it an ordinary front-controller request, path
 * and all, so the route matches the address that was actually asked for.
 */
class LegacyEndpoint
{
    public const NAME = '1c_exchange.php';

    /** What tells our file from somebody else's. Never change it. */
    protected const MARKER = 'torgash.commerceml:1c_exchange';

    public function path(): string
    {
        return public_path(self::NAME);
    }

    public function url(): string
    {
        return url('/'.self::NAME);
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /** Ours, as opposed to a file that was already there. */
    public function isOurs(): bool
    {
        return $this->exists()
            && str_contains((string) @file_get_contents($this->path()), self::MARKER);
    }

    /**
     * Write it. False means the shop has to be told: either something else is
     * at that address, or public/ is not writable (a deployment that keeps it
     * read-only is a legitimate way to run, and it is not a reason to refuse to
     * install the module — the screen offers the rewrite rule instead).
     */
    public function install(): bool
    {
        if ($this->exists() && ! $this->isOurs()) {
            return false;
        }

        return @file_put_contents($this->path(), $this->contents()) !== false;
    }

    /** Remove ours; leave anything else exactly where it is. */
    public function remove(): bool
    {
        if (! $this->exists()) {
            return true;
        }

        if (! $this->isOurs()) {
            return false;
        }

        return @unlink($this->path());
    }

    public function contents(): string
    {
        return <<<'PHP'
        <?php

        /*
         * torgash.commerceml:1c_exchange
         *
         * The address 1C's own instructions hard-code. Written by installing the
         * "CommerceML (1C)" module and deleted by uninstalling it — do not edit it
         * and do not add anything to it; the exchange itself lives in the module.
         *
         * The three variables below make this an ordinary front-controller request:
         * without them Symfony reads the whole path as the script's own name and the
         * application is asked for the homepage instead of the exchange.
         */

        $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';

        require __DIR__.'/index.php';

        PHP;
    }
}
