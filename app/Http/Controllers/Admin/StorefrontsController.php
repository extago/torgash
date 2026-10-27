<?php

namespace App\Http\Controllers\Admin;

use App\Core\Themes\ThemeManager;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Storefront;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The storefronts: which addresses answer, in which languages and currencies,
 * wearing which theme, and how the shop can be reached.
 *
 * The DEFAULT storefront is load-bearing — a host nobody claims is served by
 * it (`Storefront::resolveByHost()`), and so is the admin — so it can be
 * neither switched off, un-defaulted nor deleted: the only way to move the
 * crown is to hand it to another storefront, which is one click on that one.
 */
class StorefrontsController extends Controller
{
    public function index()
    {
        return view('admin.storefronts.index', [
            'storefronts' => Storefront::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'themes' => app(ThemeManager::class)->available(),
            'languages' => Language::query()->pluck('native_name', 'code'),
        ]);
    }

    /** On or off from the list, answered with the row's own cell. */
    public function status(Request $request, Storefront $storefront)
    {
        $on = $request->boolean('enabled');

        if (! $on && $storefront->is_default) {
            return response(__('The default storefront answers every address nobody else claims — make another one the default first.'), 422);
        }

        $storefront->update(['enabled' => $on]);

        return view('admin.storefronts._status', ['storefront' => $storefront]);
    }

    public function create()
    {
        return $this->form(new Storefront([
            'enabled' => true,
            'theme' => config('torgash.default_theme', 'default'),
            'default_locale' => config('app.locale'),
            'locales' => [config('app.locale')],
            'default_currency' => shop_currency(),
            'currencies' => [shop_currency()],
        ]));
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request, new Storefront);

        $storefront = DB::transaction(function () use ($validated) {
            if ($validated['is_default']) {
                Storefront::where('is_default', true)->update(['is_default' => false]);
            }

            return Storefront::create($validated);
        });

        return $this->afterSave($request, $storefront, __('Storefront created.'));
    }

    public function edit(Storefront $storefront)
    {
        return $this->form($storefront);
    }

    public function update(Request $request, Storefront $storefront)
    {
        $validated = $this->validated($request, $storefront);

        DB::transaction(function () use ($validated, $storefront) {
            if ($validated['is_default'] && ! $storefront->is_default) {
                Storefront::where('is_default', true)->update(['is_default' => false]);
            }

            $storefront->update($validated);
        });

        return $this->afterSave($request, $storefront, __('Storefront updated.'));
    }

    /** A new secret for the preview link: whoever had the old one sees the notice again. */
    public function newPreviewLink(Storefront $storefront)
    {
        $storefront->update(['closed_token' => Str::random(32)]);

        return redirect()->route('admin.storefronts.edit', $storefront)
            ->with('status', __('A new preview link is made; the old one no longer opens the shop.'));
    }

    /** Hand the default to this storefront — one click from the list. */
    public function makeDefault(Storefront $storefront)
    {
        DB::transaction(function () use ($storefront) {
            Storefront::where('is_default', true)->whereKeyNot($storefront->getKey())->update(['is_default' => false]);
            $storefront->update(['is_default' => true, 'enabled' => true]);
        });

        return redirect()->route('admin.storefronts.index')
            ->with('status', __(':name is now the default storefront.', ['name' => $storefront->name]));
    }

    public function destroy(Storefront $storefront)
    {
        if ($storefront->is_default) {
            throw ValidationException::withMessages([
                'code' => __('The default storefront cannot be deleted — make another one the default first.'),
            ]);
        }

        // Orders and enquiries keep their rows (their foreign key sets NULL);
        // what goes with it is this storefront's own overrides of settings.
        $storefront->delete();

        return redirect()->route('admin.storefronts.index')->with('status', __('Storefront deleted.'));
    }

    protected function form(Storefront $storefront)
    {
        return view('admin.storefronts.form', [
            'storefront' => $storefront,
            'themes' => app(ThemeManager::class)->available(),
            'languages' => Language::query()->orderByDesc('enabled')->orderBy('sort')->get(),
            'currencies' => Currency::query()->orderByDesc('enabled')->orderBy('sort')->orderBy('code')->get(),
        ]);
    }

    /** Saving stays on the form; "Save and close" in the gear goes back to the list. */
    protected function afterSave(Request $request, Storefront $storefront, string $status)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.storefronts.index')
            : redirect()->route('admin.storefronts.edit', $storefront))
            ->with('status', $status);
    }

    /**
     * The network a page's address belongs to, when the admin did not say:
     * the few whose short domains say nothing, then the site's own name
     * ("instagram.com" → instagram) — the slug a brand mark is found by.
     */
    protected function networkOf(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www|m)\./', '', $host) ?? $host;

        return [
            't.me' => 'telegram', 'telegram.me' => 'telegram', 'wa.me' => 'whatsapp', 'twitter.com' => 'x',
            'youtu.be' => 'youtube', 'ok.ru' => 'odnoklassniki', 'vk.ru' => 'vk', 'fb.com' => 'facebook',
        ][$host] ?? (string) preg_replace('/[^a-z0-9]/', '', explode('.', $host)[0] ?? '');
    }

    protected function validated(Request $request, Storefront $storefront): array
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('storefronts', 'code')->ignore($storefront)],
            'name' => 'required|string|max:255',
            'domains' => 'required|string',
            'theme' => ['required', 'string', Rule::in(array_keys(app(ThemeManager::class)->available()))],
            'default_locale' => 'required|string|exists:languages,code',
            'locales' => 'nullable|array',
            'locales.*' => 'string|exists:languages,code',
            'default_currency' => 'required|string|exists:currencies,code',
            'currencies' => 'nullable|array',
            'currencies.*' => 'string|exists:currencies,code',
            'contacts' => 'nullable|array',
            ...collect(Storefront::CONTACT_KEYS)->mapWithKeys(fn ($key) => ['contacts.'.$key => 'nullable|string|max:500'])->all(),
            'contacts.phones' => 'nullable|array|max:20',
            'contacts.phones.*.number' => 'nullable|string|max:100',
            'contacts.phones.*.label' => 'nullable|string|max:100',
            'contacts.socials' => 'nullable|array|max:30',
            'contacts.socials.*.network' => 'nullable|string|max:40',
            'contacts.socials.*.url' => 'nullable|url:http,https|max:500',
            'home_title' => 'nullable|string|max:255',
            'home_description' => 'nullable|string|max:500',
            'closed_message' => 'nullable|string|max:2000',
        ], [
            'code.regex' => __('Latin letters, digits, dashes and underscores only.'),
        ]);

        $this->assertCurrencyIsTheBase($validated['default_currency']);

        // Only the declared channels, blanks dropped rather than stored as "" —
        // so `contact('phone') === ''` is the single "not set" test everywhere,
        // and the column stays null for a storefront with nothing filled in.
        $contacts = [];
        foreach (Storefront::CONTACT_KEYS as $key) {
            $value = trim((string) ($validated['contacts'][$key] ?? ''));
            if ($value !== '') {
                $contacts[$key] = $value;
            }
        }

        // The lists: a row without its main field (a number, an address) is
        // a row the admin left empty, not one to store. A network is a slug
        // ("telegram"), the name a brand mark is found by.
        foreach (Storefront::CONTACT_LISTS as $key => [$main, $second]) {
            $rows = [];
            foreach ((array) ($validated['contacts'][$key] ?? []) as $row) {
                $first = trim((string) ($row[$main] ?? ''));
                if ($first === '') {
                    continue;
                }
                $other = trim((string) ($row[$second] ?? ''));
                if ($second === 'network') {
                    $other = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $other) ?? '') ?: $this->networkOf($first);
                }
                $rows[] = [$main => $first, $second => $other];
            }
            if ($rows !== []) {
                $contacts[$key] = $rows;
            }
        }

        // The default is always among those offered — a storefront whose own
        // language is not one of its languages would 404 its own pages
        // (ResolveStorefront refuses a locale the storefront does not list).
        $locales = array_values(array_unique([$validated['default_locale'], ...($validated['locales'] ?? [])]));
        $currencies = array_values(array_unique([$validated['default_currency'], ...($validated['currencies'] ?? [])]));

        // The default storefront stays the default and stays on (see the class
        // docblock): the form draws both boxes locked, and this is the other door.
        $isDefault = $storefront->is_default || $request->boolean('is_default');

        // One language a save — the one picked at the top (put_translation).
        $homeTitle = put_translation($storefront->home_title, $validated['home_title'] ?? null);
        $homeDescription = put_translation($storefront->home_description, $validated['home_description'] ?? null);
        $closedMessage = put_translation($storefront->closed_message, $validated['closed_message'] ?? null);

        // Closing makes the preview link's secret the first time; it stays
        // the same across closings until the shop asks for a new one.
        $closed = $request->boolean('closed');

        return [
            'closed' => $closed,
            'closed_contacts' => $request->boolean('closed_contacts'),
            'closed_message' => $closedMessage ?: null,
            'closed_token' => $storefront->closed_token ?: ($closed ? Str::random(32) : null),
            'home_title' => $homeTitle ?: null,
            'home_description' => $homeDescription ?: null,
            'contacts' => $contacts ?: null,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'domains' => $this->domains($validated['domains'], $storefront),
            'theme' => $validated['theme'],
            'default_locale' => $validated['default_locale'],
            'locales' => $locales,
            'default_currency' => $validated['default_currency'],
            'currencies' => $currencies,
            'is_default' => $isDefault,
            'enabled' => $isDefault || $request->boolean('enabled'),
        ];
    }

    /**
     * The typed addresses as bare hosts — a pasted `https://shop.com/` is what
     * people copy out of a browser, and `resolveByHost()` compares the host
     * alone. A host another storefront already answers is refused: which of
     * the two a visitor landed on would be whichever row the database returned
     * first.
     *
     * @return list<string>
     */
    protected function domains(string $typed, Storefront $storefront): array
    {
        $domains = collect(preg_split('/[\s,;]+/', $typed))
            ->map(function (string $entry) {
                $entry = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', strtolower(trim($entry)));

                return rtrim(explode('/', $entry, 2)[0], '.');
            })
            ->filter()->unique()->values();

        if ($domains->isEmpty()) {
            throw ValidationException::withMessages(['domains' => __('Give the storefront at least one domain.')]);
        }

        foreach ($domains as $domain) {
            if (! preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d+)?$/', $domain)) {
                throw ValidationException::withMessages(['domains' => __(':domain is not a domain name.', ['domain' => $domain])]);
            }
        }

        $taken = [];
        foreach (Storefront::query()->whereKeyNot($storefront->getKey())->get() as $other) {
            foreach ($other->domains ?? [] as $otherDomain) {
                $taken[strtolower($otherDomain)] = $other->name;
            }
        }

        foreach ($domains as $domain) {
            if (isset($taken[$domain])) {
                throw ValidationException::withMessages([
                    'domains' => __(':domain already belongs to the storefront :name.', ['domain' => $domain, 'name' => $taken[$domain]]),
                ]);
            }
        }

        return $domains->all();
    }

    /**
     * The storefront's default currency is the one every amount in the database
     * is ALREADY in — a product's price, an order's total, a rate band. Nothing
     * converts when this select changes: the same stored 104225 simply stops
     * meaning $1042.25 and starts meaning ₴1042.25, across the whole shop and
     * its history.
     *
     * The one mechanical check that catches it is the rate: every other
     * currency's rate says how much of it one unit of THIS one buys, so the
     * shop's own currency is the one whose rate is 1. Picking a currency whose
     * rate is 41 as the default therefore cannot be what anybody meant, and it
     * would silently multiply every displayed price by 41 as well.
     *
     * Refused rather than fixed up: re-basing a shop is converting stored
     * money, and that is a job with a backup before it, not a side effect of
     * saving a form.
     */
    protected function assertCurrencyIsTheBase(string $code): void
    {
        $currency = Currency::firstWhere('code', $code);

        if ($currency && (float) $currency->rate !== 1.0) {
            throw ValidationException::withMessages([
                'default_currency' => __('The shop stores every price in its default currency, and changing this converts nothing. :code has a rate of :rate, so the shop\'s own currency is not :code — set its rate to 1 (and re-express the others against it) before making it the default.', [
                    'code' => $code,
                    'rate' => rtrim(rtrim((string) $currency->rate, '0'), '.'),
                ]),
            ]);
        }
    }
}
