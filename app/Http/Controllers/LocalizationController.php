<?php

namespace App\Http\Controllers;

use App\Models\Language;
use Illuminate\Http\Request;

class LocalizationController extends Controller
{
    /** Admin edit-form content language — see admin_content_locale(). Independent of storefront locale. */
    public function setAdminContentLocale(Request $request)
    {
        $locale = $request->input('locale');

        if (Language::where('code', $locale)->where('enabled', true)->exists()) {
            $request->session()->put('admin_content_locale', $locale);
        }

        return redirect()->back();
    }

    /** Admin panel's own UI language (menus/labels) — see SetAdminLocale middleware. Independent of content language. */
    public function setAdminLocale(Request $request)
    {
        $locale = $request->input('locale');

        if (Language::where('code', $locale)->where('enabled', true)->exists()) {
            $request->session()->put('admin_locale', $locale);
        }

        return redirect()->back();
    }

    /**
     * The currency the ADMIN reads money in — see admin_price().
     *
     * Deliberately its own session key: the admin and the storefront share a
     * domain, so before this the admin's prices followed whatever the owner had
     * last picked while walking their own shop. Anything but a known currency
     * means "Initial" — as stored — which is the default and the only mode in
     * which a number on the screen is the number in the database.
     */
    public function setAdminCurrency(Request $request)
    {
        $code = (string) $request->input('currency');

        $request->session()->put(
            'admin_currency',
            app(\App\Core\Support\Currencies::class)->find($code) ? $code : null,
        );

        return redirect()->back();
    }

    public function setCurrency(Request $request)
    {
        $storefront = current_storefront();
        $currency = $request->input('currency');

        if ($storefront && in_array($currency, $storefront->currencies ?? [], true)) {
            $request->session()->put('currency', $currency);
        }

        return redirect()->back();
    }
}
