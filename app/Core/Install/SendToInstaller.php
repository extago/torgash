<?php

namespace App\Core\Install;

use Closure;
use Illuminate\Http\Request;

/**
 * Until the shop is installed every address leads to the wizard (PLAN §54):
 * an unpacked distribution has no `.env`, no database and no key, and any
 * other page would only be an error.
 *
 * Global and first, before the `web` group: there is no application key yet
 * to encrypt a cookie with, which is also why the wizard's own routes stand
 * outside that group. Once installed it costs one `is_file()` a request.
 */
class SendToInstaller
{
    /** What may be asked for before the install: the wizard itself and the files its page loads. */
    public const OPEN = ['install', 'install/*', 'up', 'favicon.ico', 'csp-report', 'modules/*/assets/*', 'themes/*/assets/*'];

    public function handle(Request $request, Closure $next)
    {
        if ($request->is(...self::OPEN) || Installer::installed()) {
            return $next($request);
        }

        return redirect($request->getSchemeAndHttpHost().$request->getBasePath().'/install');
    }
}
