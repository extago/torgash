<?php

use App\Core\Content\Placeholders;
use App\Core\Hooks\HookBus;
use App\Core\Seo\Breadcrumbs;
use App\Core\Seo\PageMeta;
use App\Core\Seo\StructuredData;
use App\Core\Settings\SettingsManager;
use App\Core\Support\Currencies;
use App\Core\Support\Units;
use App\Core\Zones\ZoneRegistry;
use App\Models\Storefront;

if (! function_exists('hook_listen')) {
    function hook_listen(string $hook, callable $callback, int $priority = 10): void
    {
        app(HookBus::class)->listen($hook, $callback, $priority);
    }
}

if (! function_exists('hook_filter')) {
    function hook_filter(string $hook, mixed $value, array $context = []): mixed
    {
        return app(HookBus::class)->filter($hook, $value, $context);
    }
}

if (! function_exists('hook_render')) {
    /**
     * A position on a page that modules add HTML to, each listener isolated
     * (HookBus::render): one that throws is left out, the rest still print.
     */
    function hook_render(string $hook, array $context = []): string
    {
        return app(HookBus::class)->render($hook, $context);
    }
}

if (! function_exists('safe_mode')) {
    /** Whether this page is drawn without the shop's theme, CSS and snippets (App\Core\Support\SafeMode). */
    function safe_mode(): bool
    {
        return \App\Core\Support\SafeMode::active();
    }
}

if (! function_exists('hook_action')) {
    function hook_action(string $hook, array $context = []): void
    {
        app(HookBus::class)->action($hook, $context);
    }
}

if (! function_exists('label_text')) {
    /**
     * A DECLARED word — a setting's label, a block type's name, a menu look,
     * a zone — in the reader's language.
     *
     * Declared in English and translated like any other interface string: the
     * English text is the key into `lang/<locale>.json` (the owning module's,
     * the theme's or core's), so adding a language is adding a file, never
     * editing every declaration in the code. A map `{en: …, ru: …}` is still
     * read — a module written before this, or by somebody else, keeps working —
     * and a map that lacks the reader's language falls back to its English
     * text translated the ordinary way, so a Ukrainian file reaches it too.
     *
     * NOT for content (a product's name, a block's heading): those are
     * translatable jsonb the shop typed, read through `t()`.
     */
    function label_text(mixed $label, ?string $locale = null, string $fallback = ''): string
    {
        $locale ??= app()->getLocale();

        if (is_array($label)) {
            if (isset($label[$locale]) && is_string($label[$locale]) && $label[$locale] !== '') {
                return $label[$locale];
            }

            $base = $label['en'] ?? null;

            if (! is_string($base) || $base === '') {
                $first = collect($label)->first(fn ($v) => is_string($v) && $v !== '');

                return is_string($first) ? $first : $fallback;
            }

            $label = $base;
        }

        if (! is_string($label) || $label === '') {
            return $fallback;
        }

        return (string) __($label, [], $locale);
    }
}

if (! function_exists('expand_placeholders')) {
    /**
     * Expand registered {tokens} in admin-authored content. For content
     * rendered UNESCAPED only — see Placeholders::expand(), which escapes each
     * value on the way in.
     */
    function expand_placeholders(string $text, array $context = []): string
    {
        return app(Placeholders::class)->expand($text, $context);
    }
}

if (! function_exists('theme_setting')) {
    /**
     * A setting of the active theme, as this storefront chose it (or as the
     * configurator's preview frame is trying it) — see App\Core\Themes\ThemeSettings.
     */
    function theme_setting(string $key, mixed $default = null): mixed
    {
        return app(\App\Core\Themes\ThemeSettings::class)->get($key, $default);
    }
}

if (! function_exists('theme_image')) {
    /**
     * The address of a theme's `image` setting (the no-photo picture, a
     * logo): the storefront's upload, else the theme's own file, else null.
     */
    function theme_image(string $key): ?string
    {
        return app(\App\Core\Themes\ThemeSettings::class)->imageUrl($key);
    }
}

if (! function_exists('zone')) {
    /**
     * Render a named output position: {!! zone('catalog.product.after_buy_box') !!}
     *
     * The same seed-and-accumulate semantics as hook_filter(), so every
     * listener keeps working unchanged, but each listener is drawn isolated
     * (hook_render): a module whose part of the zone throws loses that part,
     * not the page. It also tells the registry the zone was rendered, which
     * is what turns a mistyped zone name from silent nothing into a
     * debug-mode warning.
     */
    function zone(string $name, array $context = []): string
    {
        app(ZoneRegistry::class)->assertDeclared($name);

        return hook_render($name, $context);
    }
}

if (! function_exists('page_meta')) {
    /**
     * The head of the page being rendered — title, description, canonical,
     * hreflang, OpenGraph. See App\Core\Seo\PageMeta: a controller fills it in,
     * the theme prints it once.
     */
    function page_meta(): PageMeta
    {
        return app(PageMeta::class);
    }
}

if (! function_exists('breadcrumbs')) {
    /** Where the visitor is, as data — printed by <x-breadcrumbs>, read by the structured data. */
    function breadcrumbs(): Breadcrumbs
    {
        return app(Breadcrumbs::class);
    }
}

if (! function_exists('structured_data')) {
    /** The page's JSON-LD graph — one script, many nodes. See App\Core\Seo\StructuredData. */
    function structured_data(): StructuredData
    {
        return app(StructuredData::class);
    }
}

if (! function_exists('asset_url')) {
    /**
     * A file under public/ with its version in the address (`?v=`), so an
     * update reaches the browser instead of a year-old copy (PLAN §45.3).
     * Never write `/assets/...` by hand in a template.
     */
    function asset_url(string $path): string
    {
        return app(\App\Core\Assets\AssetVersions::class)->public($path);
    }
}

if (! function_exists('module_asset')) {
    /** A module's own file (modules/<id>/assets/<path>) with its version in the address. */
    function module_asset(string $module, string $path): string
    {
        $file = app(\App\Core\Modules\ModuleManager::class)->find($module)?->path.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.$path;
        $version = app(\App\Core\Assets\AssetVersions::class)->of($file);

        return route('module.asset', ['module' => $module, 'path' => $path] + ($version === '' ? [] : ['v' => $version]));
    }
}

if (! function_exists('theme_asset')) {
    /** A theme's own file (themes/<dir>/assets/<path>) with its version in the address. */
    function theme_asset(string $theme, string $path): string
    {
        $file = config('torgash.themes_path').DIRECTORY_SEPARATOR.$theme.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.$path;
        $version = app(\App\Core\Assets\AssetVersions::class)->of($file);

        return route('theme.asset', ['theme' => $theme, 'path' => $path] + ($version === '' ? [] : ['v' => $version]));
    }
}

if (! function_exists('safe_html')) {
    /**
     * A text the staff wrote, cleaned for printing as HTML on the storefront —
     * `{{ safe_html($product->t('description')) }}`. See App\Core\Content\SafeHtml.
     */
    function safe_html(?string $html): \Illuminate\Support\HtmlString
    {
        return new \Illuminate\Support\HtmlString(app(\App\Core\Content\SafeHtml::class)->clean($html));
    }
}

if (! function_exists('within_pages')) {
    /**
     * A storefront listing's page, or 404 past its last one: `?page=999`
     * answered 200 with an empty grid, a page a crawler indexes as a page
     * with nothing on it (audit 2026-10-15, "soft 404"). Page 1 of an empty
     * list stays a page — "nothing here yet" is an answer.
     */
    function within_pages(\Illuminate\Contracts\Pagination\LengthAwarePaginator $page): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        abort_if($page->currentPage() > max(1, $page->lastPage()), 404);

        return $page;
    }
}

if (! function_exists('csp_nonce')) {
    /**
     * This request's script nonce (App\Core\Http\Csp): every <script> the
     * engine prints carries `nonce="{{ csp_nonce() }}"`, or the content
     * security policy will not let it run.
     */
    function csp_nonce(): string
    {
        return app(\App\Core\Http\Csp::class)->nonce();
    }
}

if (! function_exists('setting')) {
    function setting(string $module, string $key, mixed $default = null, ?int $storefrontId = null): mixed
    {
        return app(SettingsManager::class)->get($module, $key, $default, $storefrontId);
    }
}

if (! function_exists('format_price')) {
    /**
     * Prices are stored as integer minor units of the shop's books
     * (shop_currency()). Renders in the visitor's currency using its rate and
     * its own way of writing an amount.
     *
     * `$exact` is for the bill — the cart, the basket dialog, the checkout:
     * what the card will be charged. In the books' own currency nothing is
     * converted and a gateway is asked for the stored cents as they are
     * (Order::chargeMinor), so a shop that shows whole dollars would print
     * $2 874 over a charge of $2 873.76 and parts that do not add up. Exact
     * shows the cents there — only when there are any, so a shop with whole
     * prices never grows ".00" on every line. A converted amount is charged
     * rounded to the places it is shown with, so it stays as it is.
     */
    function format_price(int $minorUnits, ?string $currencyCode = null, bool $exact = false): string
    {
        $code = $currencyCode ?? current_currency();
        // Resolved through the request-scoped table, not a query per call: this
        // runs once per price on the page. See App\Core\Support\Currencies.
        $currency = app(Currencies::class)->find($code);

        if ($currency === null) {
            return number_format($minorUnits / 100, 2).' '.$code;
        }

        $amount = ($minorUnits / 100) * (float) $currency->rate;

        if ($exact && $code === shop_currency() && $minorUnits % 100 !== 0) {
            return $currency->format($amount, max(2, (int) $currency->decimals));
        }

        return $currency->format($amount);
    }
}

if (! function_exists('current_currency')) {
    /**
     * The currency the visitor is looking at.
     *
     * Their own pick from the header switcher when the storefront actually
     * offers it — a code pasted into a session must not decide what anything
     * costs — and the storefront's own currency otherwise.
     *
     * Named and separate because "what is on screen right now" is asked by more
     * than the formatter: an order printed in the currency it was placed in has
     * to know whether that still matches what the visitor picked, and answering
     * it in each template is how the two drift apart.
     */
    function current_currency(): string
    {
        $storefront = current_storefront();
        $chosen = session('currency');

        return $chosen && in_array($chosen, $storefront?->currencies ?? [], true)
            ? $chosen
            : ($storefront?->default_currency ?? 'USD');
    }
}

if (! function_exists('round_money')) {
    /**
     * Money worked out from a percentage (a discount, a markup, a fee), in
     * books minor units, rounded to the places the books currency is shown
     * with — so what the product says is what the basket asks.
     * Currencies::round().
     */
    function round_money(float|int $minor): int
    {
        return app(\App\Core\Support\Currencies::class)->round((float) $minor);
    }
}

if (! function_exists('shop_currency')) {
    /**
     * The currency the shop keeps its books in — the units every stored amount
     * in every module is in, and the one whose rate is 1 by construction.
     * With no storefront served (the console, a job) it is still the shop's
     * books, never a guess — Currencies::books().
     */
    function shop_currency(): string
    {
        return current_storefront()?->default_currency ?? app(\App\Core\Support\Currencies::class)->books();
    }
}

if (! function_exists('admin_currency')) {
    /**
     * The currency the admin chose to READ money in, or null for "Initial" —
     * the one each amount is actually stored in.
     *
     * A third switcher beside the two languages, and needed for the same
     * reason: the admin and the storefront share a session, so before this an
     * owner who had walked their own shop in hryvnia found the admin quoting
     * hryvnia — not a decision anybody made, just a leak.
     *
     * Null is the default and means every number on the screen is the number in
     * the database. That is what keeps inline price editing safe: a field is
     * only ever typed in the currency it is stored in.
     */
    function admin_currency(): ?string
    {
        $code = session('admin_currency');

        return $code && app(Currencies::class)->find($code) ? $code : null;
    }
}

if (! function_exists('admin_price')) {
    /**
     * Money on an admin screen: read in whatever the admin picked, converted
     * from whatever it is stored in.
     *
     * `$source` is the currency `$minorUnits` is stored in — the shop's books
     * unless the caller knows better (an order carries its own `currency_code`,
     * a shipping rate its own currency). Everything is routed through the books
     * first, because that is the one currency every rate is expressed against.
     *
     * Never used for INPUT. What an admin types stays in the currency it is
     * stored in, so that a quick inline edit cannot silently rewrite a price
     * through an exchange rate.
     */
    function admin_price(int $minorUnits, ?string $source = null): string
    {
        $source ??= shop_currency();
        $target = admin_currency() ?? $source;

        if ($target === $source) {
            return format_price($minorUnits, $source);
        }

        $rate = (float) (app(Currencies::class)->find($source)?->rate ?: 1);

        return format_price($rate > 0 ? (int) round($minorUnits / $rate) : $minorUnits, $target);
    }
}

if (! function_exists('units')) {
    /**
     * Units of measure — what a weight or a size is typed and shown in, against
     * the grams and millimetres actually stored. See App\Core\Support\Units.
     */
    function units(): Units
    {
        return app(Units::class);
    }
}

if (! function_exists('current_storefront')) {
    function current_storefront(): ?Storefront
    {
        return app()->bound('torgash.storefront') ? app('torgash.storefront') : null;
    }
}

if (! function_exists('format_rate')) {
    /**
     * An exchange rate as a person reads it: by SIGNIFICANT digits, never in
     * scientific notation, trailing zeros dropped — 44.639, 0.86655796,
     * 0.00001049. A fixed four decimals printed a bitcoin rate as 0.
     */
    function format_rate(float|int|string|null $rate, int $significant = 10, string $thousands = ''): string
    {
        $rate = (float) $rate;

        if ($rate == 0.0) {
            return '0';
        }

        $decimals = max(0, min(20, $significant - 1 - (int) floor(log10(abs($rate)))));
        $text = number_format($rate, $decimals, '.', $thousands);

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}

if (! function_exists('pick_translation')) {
    /**
     * A translatable value read from a raw jsonb map — what HasTranslations::t()
     * does for a model, for a row that arrived as plain data (a query-builder
     * row, an API payload): this locale, else the base language, else any.
     */
    function pick_translation(mixed $values, ?string $locale = null): ?string
    {
        if (is_string($values)) {
            $decoded = json_decode($values, true);
            $values = is_array($decoded) ? $decoded : $values;
        }

        if (! is_array($values)) {
            return is_string($values) && $values !== '' ? $values : null;
        }

        $values = array_filter($values, fn ($text) => is_string($text) && $text !== '');

        return $values[$locale ?? app()->getLocale()]
            ?? $values[content_base_locale()]
            ?? (reset($values) ?: null);
    }
}

if (! function_exists('shop_now')) {
    /**
     * Now, on the shop's own clock (core setting `timezone`): what "from
     * 18:00", "on weekends" and "25.12–08.01" in a display rule mean. The
     * application's clock — what the database stores — stays as it is.
     */
    function shop_now(): \Illuminate\Support\Carbon
    {
        $zone = (string) setting('core', 'timezone', '');

        return in_array($zone, \DateTimeZone::listIdentifiers(), true) ? now($zone) : now();
    }
}

if (! function_exists('put_translation')) {
    /**
     * One language of a translatable jsonb field, written from an admin form:
     * `$value` goes in under `$locale` (the admin's content language by
     * default) and every other language is kept as it was. Blank REMOVES that
     * language — the form shows a missing translation as the other language's
     * text in grey, so "" and "not translated yet" have to be the same thing.
     *
     * The one place this is done, so a form never learns which languages the
     * shop has: `['en' => $request->name_en, 'ru' => $request->name_ru]` is
     * how a third language got silently wiped on every save.
     *
     * @return array<string, string>
     */
    function put_translation(?array $current, mixed $value, ?string $locale = null): array
    {
        $current ??= [];
        $locale ??= admin_content_locale();
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '') {
            unset($current[$locale]);
        } else {
            $current[$locale] = $value;
        }

        return $current;
    }
}

if (! function_exists('content_locale_is_base')) {
    /** Whether the admin is writing the base language — the one a required text must have. */
    function content_locale_is_base(): bool
    {
        return admin_content_locale() === content_base_locale();
    }
}

if (! function_exists('content_base_locale')) {
    /**
     * The language the shop's CONTENT is written in first — the one a
     * translatable field must have, the one a missing translation falls back
     * to, the one an admin form opens on.
     *
     * Not `app.fallback_locale`, which it used to be: that is the TRANSLATOR's
     * fallback for the engine's own strings, and has to stay English — the
     * framework's validation messages exist only in English, and a Russian
     * fallback printed `validation.required`. A Russian shop's content is
     * Russian: with the two being one setting, its product names were written
     * into the English slot under a "(EN)" label (found on the first live
     * shop, 2026-10-21). The installer writes the shop's language here.
     */
    function content_base_locale(): string
    {
        // Chosen on the Languages screen ("Make it the base"), so an owner can
        // change it — the first live shop, Russian only, was stuck with
        // English as its base and so could not switch English off. Then what
        // the installer wrote into .env, then the translator's fallback.
        try {
            $chosen = (string) setting('core', 'content_locale', '');
        } catch (\Throwable) {
            $chosen = ''; // no settings table yet: an install in progress
        }

        return $chosen !== '' ? $chosen : (string) (config('torgash.content_locale') ?: config('app.fallback_locale', 'en'));
    }
}

if (! function_exists('admin_content_locale')) {
    /**
     * Which language admin edit forms show/write for translatable JSONB
     * fields (name, description, ...) — independent of any storefront's
     * locale and of the admin's own browsing language. Session-backed, set
     * via the switcher in admin/layout.blade.php (see LocalizationController).
     */
    function admin_content_locale(): string
    {
        $chosen = session('admin_content_locale');

        // A language switched off since it was picked is not one to write in:
        // back to the base, where the forms are required anyway. Remembered
        // on the request itself — a page asks this twenty times, and the
        // request object is new for every request in every mode (a worker's,
        // a queue's), where a static or once() would outlive it.
        if (is_string($chosen)) {
            $attributes = request()->attributes;

            if (! $attributes->has('torgash.enabled_locales')) {
                $attributes->set('torgash.enabled_locales', \App\Models\Language::where('enabled', true)->pluck('code')->all());
            }

            if (in_array($chosen, $attributes->get('torgash.enabled_locales'), true)) {
                return $chosen;
            }
        }

        return content_base_locale();
    }
}
