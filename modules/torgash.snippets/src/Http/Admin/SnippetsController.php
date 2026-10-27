<?php

namespace Torgash\Snippets\Http\Admin;

use App\Core\Display\DisplayRules;
use App\Http\Controllers\Controller;
use App\Models\Storefront;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Snippets\Models\Snippet;

class SnippetsController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'place' => in_array($request->query('place'), Snippet::PLACEMENTS, true) ? $request->query('place') : '',
        ];

        // A shop has a handful of these: read once, counted per slot for the
        // tabs, and searched in the code too — "where did we put the pixel id".
        $all = Snippet::orderBy('placement')->orderBy('position')->orderBy('id')->get();
        $searched = $filters['q'] === '' ? $all : $all->filter(fn (Snippet $snippet) => mb_stripos($snippet->name, $filters['q']) !== false
            || mb_stripos((string) $snippet->code, $filters['q']) !== false);

        return view('torgash.snippets::admin.index', [
            'snippets' => $filters['place'] === '' ? $searched->values() : $searched->where('placement', $filters['place'])->values(),
            'filters' => $filters,
            'placeCounts' => ['' => $searched->count()] + collect(Snippet::PLACEMENTS)
                ->mapWithKeys(fn ($place) => [$place => $searched->where('placement', $place)->count()])->all(),
            'storefronts' => Storefront::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function create()
    {
        return $this->form(new Snippet(['placement' => 'head', 'enabled' => true, 'rules_match' => DisplayRules::MATCH_ALL]));
    }

    public function edit(Snippet $snippet)
    {
        return $this->form($snippet);
    }

    public function store(Request $request)
    {
        $snippet = Snippet::create($this->data($request));

        return $this->afterSave($request, $snippet, __('Snippet created.'));
    }

    public function update(Request $request, Snippet $snippet)
    {
        $snippet->update($this->data($request));

        return $this->afterSave($request, $snippet, __('Snippet saved.'));
    }

    public function destroy(Snippet $snippet)
    {
        $snippet->delete();

        return redirect()->route('admin.snippets.index')->with('status', __('Snippet deleted.'));
    }

    /** A copy arrives SWITCHED OFF: two live copies of one pixel count every visit twice. */
    public function duplicate(Snippet $snippet)
    {
        $copy = $snippet->replicate(['created_at', 'updated_at']);
        $copy->name = $snippet->name.' ('.__('copy').')';
        $copy->enabled = false;
        $copy->save();

        return redirect()->route('admin.snippets.edit', $copy)->with('status', __('Snippet copied. The copy is off until you switch it on.'));
    }

    /** The list's in-place switch: answers with the cell. */
    public function setStatus(Request $request, Snippet $snippet)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $snippet->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.snippets::admin._status', ['snippet' => $snippet]);
    }

    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        // Model by model, never a query update: the guard's audit hears
        // Eloquent events, and a snippet is exactly what it must not miss.
        $snippets = Snippet::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $snippets->each->delete();

            return back()->with('status', trans_choice(':count snippet deleted.|:count snippets deleted.', $snippets->count(), ['count' => $snippets->count()]));
        }

        $snippets->each(fn (Snippet $snippet) => $snippet->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count snippet updated.|:count snippets updated.', $snippets->count(), ['count' => $snippets->count()]));
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, Snippet $snippet, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.snippets.index')
            : redirect()->route('admin.snippets.edit', $snippet))->with('status', $message);
    }

    protected function form(Snippet $snippet)
    {
        return view('torgash.snippets::admin.form', [
            'snippet' => $snippet,
            'storefronts' => Storefront::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /** @return array<string, mixed> */
    protected function data(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:190',
            'placement' => ['required', Rule::in(Snippet::PLACEMENTS)],
            // No sanitising, no stripping, no validation of what the code IS.
            // That is the feature: a counter is whatever the provider handed the
            // shop, and an engine that tidied it would break it. What guards
            // this instead is the permission on the route and the guard
            // module's audit, which records every change from the Eloquent
            // events this write fires.
            'code' => 'nullable|string|max:65535',
            'storefront_id' => 'nullable|integer|exists:storefronts,id',
            'position' => 'nullable|integer',
            'category' => ['nullable', Rule::in(array_keys(\App\Core\Privacy\Consent::CATEGORIES))],
            'click_label' => 'nullable|string|max:64',
        ]);

        return [
            'name' => $validated['name'],
            'placement' => $validated['placement'],
            'code' => $validated['code'] ?? '',
            'storefront_id' => $validated['storefront_id'] ?? null,
            'position' => (int) ($validated['position'] ?? 0),
            'enabled' => $request->boolean('enabled'),
            // What it is for consent (PLAN §49), and whether it waits for a
            // click on its own button instead.
            'category' => $validated['category'] ?? 'necessary',
            'on_click' => $request->boolean('on_click'),
            'click_label' => put_translation($request->route('snippet')?->click_label, $validated['click_label'] ?? ''),
            // Where it is shown, through core's own engine — normalised there
            // too, because a rule the engine cannot evaluate would hide the
            // snippet everywhere rather than being ignored.
            'rules' => app(DisplayRules::class)->normalise($request->input('rules', [])),
            'rules_match' => $request->input('rules_match') === DisplayRules::MATCH_ANY
                ? DisplayRules::MATCH_ANY
                : DisplayRules::MATCH_ALL,
        ];
    }
}
