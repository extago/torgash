<?php

namespace App\Http\Controllers\Admin;

use App\Core\Support\GrammaticalCases;
use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\Storefront;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The languages the shop can be written in.
 *
 * Two kinds are load-bearing and can be neither switched off nor deleted: the
 * BASE language (every translatable field falls back to it, and it is the one
 * a form requires) and a language some storefront opens in by default. And a
 * language's code never changes once made: every translated text in the
 * database is keyed by it, so renaming `uk` to `ua` would orphan all of them.
 */
class LanguagesController extends Controller
{
    public function index()
    {
        return view('admin.languages.index', [
            'languages' => Language::orderBy('sort')->orderBy('id')->get(),
            'inUse' => $this->inUse(),
        ]);
    }

    /**
     * Makes a language the base: the one every translatable text must have in
     * the admin and every missing translation falls back to.
     *
     * The owner's choice, not a fact of the code: a Russian-only shop wants
     * Russian as its base and English switched off, and before this it could
     * have neither. What is already written stays where it is — a text only in
     * the old base still shows (a missing translation reads the first one
     * there is), and the admin asks for the new base the next time it is saved.
     */
    public function makeBase(Language $language)
    {
        if (! $language->enabled) {
            return back()->withErrors(['language' => __('Switch the language on first — the base language cannot be off.')]);
        }

        app(\App\Core\Settings\SettingsManager::class)->set(\App\Core\Settings\CoreSettings::ID, 'content_locale', $language->code);

        return back()->with('status', __(':name is the base language now.', ['name' => $language->native_name]));
    }

    /** On or off from the list, answered with the row's own cell. */
    public function status(Request $request, Language $language)
    {
        $on = $request->boolean('enabled');
        $inUse = $this->inUse();

        if (! $on && isset($inUse[$language->code])) {
            return response($inUse[$language->code], 422);
        }

        $language->update(['enabled' => $on]);

        return view('admin.languages._status', ['language' => $language, 'inUse' => $inUse]);
    }

    public function create()
    {
        return view('admin.languages.form', ['language' => new Language(['enabled' => true]), 'inUse' => []]);
    }

    public function store(Request $request)
    {
        $language = Language::create($this->data($request));

        return $this->saved($request, $language, __('Language created.'));
    }

    public function edit(Language $language)
    {
        return view('admin.languages.form', ['language' => $language, 'inUse' => $this->inUse()]);
    }

    public function update(Request $request, Language $language)
    {
        $language->update($this->data($request, $language));

        return $this->saved($request, $language, __('Language updated.'));
    }

    public function destroy(Language $language)
    {
        $inUse = $this->inUse();

        if (isset($inUse[$language->code])) {
            throw ValidationException::withMessages(['code' => $inUse[$language->code]]);
        }

        // A storefront still listing the code would keep its /xx/ addresses
        // in the route table for a language that no longer exists.
        foreach (Storefront::all() as $storefront) {
            if (in_array($language->code, $storefront->locales ?? [], true)) {
                $storefront->update(['locales' => array_values(array_diff($storefront->locales, [$language->code]))]);
            }
        }

        $language->delete();

        return redirect()->route('admin.languages.index')->with('status', __('Language deleted.'));
    }

    /** Saving stays on the form; "Save and close" in the gear goes back to the list. */
    protected function saved(Request $request, Language $language, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.languages.index')
            : redirect()->route('admin.languages.edit', $language))->with('status', $message);
    }

    /**
     * The languages that must stay, each with the reason in words — the list
     * prints it beside the dimmed trash, the refusals say it.
     *
     * @return array<string, string> code => why
     */
    protected function inUse(): array
    {
        $inUse = [];

        foreach (Storefront::query()->get(['name', 'default_locale']) as $storefront) {
            if ($storefront->default_locale) {
                $inUse[$storefront->default_locale] = __('The storefront :name opens in this language — choose another one for it first.', ['name' => $storefront->name]);
            }
        }

        $base = content_base_locale();
        $inUse[$base] = __('The base language: every text falls back to it, so it can be neither switched off nor deleted.');

        return $inUse;
    }

    protected function data(Request $request, ?Language $language = null): array
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:5', 'regex:/^[a-z]{2,3}(-[a-z0-9]{2,3})?$/i', Rule::unique('languages', 'code')->ignore($language?->id)],
            'name' => 'required|string|max:100',
            'native_name' => 'required|string|max:100',
            'sort' => 'nullable|integer',
            'cases' => 'nullable|array|max:30',
            'cases.*.name' => 'nullable|string|max:60',
            'cases.*.example' => 'nullable|string|max:100',
        ]);

        $enabled = $request->boolean('enabled');

        if ($language?->exists) {
            // See the class docblock: the code is the key of every translation.
            if (strtolower($validated['code']) !== $language->code) {
                throw ValidationException::withMessages([
                    'code' => __('A language\'s code cannot be changed: every translated text is stored under it. Create a new language instead.'),
                ]);
            }

            $enabled = $enabled || isset($this->inUse()[$language->code]);
        }

        return [
            'code' => strtolower($validated['code']),
            'name' => $validated['name'],
            'native_name' => $validated['native_name'],
            'sort' => (int) ($validated['sort'] ?? 0),
            'enabled' => $enabled,
            // A list, numbered by its order; one row or none is "does not
            // decline", stored as an empty list — said, unlike NULL, so the
            // defaults are never filled in over it.
            'cases' => array_values(GrammaticalCases::normalise((array) ($validated['cases'] ?? []))),
        ];
    }
}
