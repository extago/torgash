<?php

namespace App\Http\Middleware;

use App\Models\Language;
use Closure;
use Illuminate\Http\Request;

/**
 * The admin panel's own UI language (menus, labels, buttons) — a per-admin
 * session preference, switched from the profile dropdown in admin/layout.blade.php.
 * Independent of both the storefront's visitor locale (ResolveStorefront) and
 * admin_content_locale() (which translation of a product/category is being edited).
 */
class SetAdminLocale
{
    public function handle(Request $request, Closure $next)
    {
        // Global 'web' middleware (so every module's own admin routes pick it up
        // too — see bootstrap/app.php), but must not leak into storefront requests:
        // a customer who's also logged in as admin in the same browser would
        // otherwise get the admin's interface language instead of the storefront's.
        if (! $request->is('admin', 'admin/*')) {
            return $next($request);
        }

        $locale = $request->session()->get('admin_locale');

        if ($locale && Language::where('code', $locale)->where('enabled', true)->exists()) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
