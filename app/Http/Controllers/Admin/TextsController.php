<?php

namespace App\Http\Controllers\Admin;

use App\Core\Translation\TextCatalogue;
use App\Core\Translation\TextOverrides;
use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Admin → Texts: the shop's own wording of what the engine prints, in each
 * of its languages (App\Core\Translation\TextOverrides). A text is listed
 * under its English key with the file's translation in every enabled
 * language; what the shop types instead is kept as a row and read over the
 * file, so it outlives an update. Words only — see the class.
 */
class TextsController extends Controller
{
    public function index(Request $request, TextCatalogue $catalogue, TextOverrides $overrides)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'changed' => $request->boolean('changed'),
        ];

        $languages = Language::where('enabled', true)->orderBy('sort')->orderBy('id')->get();
        $own = [];

        foreach ($languages as $language) {
            $own[$language->code] = $overrides->for($language->code);
        }

        $rows = [];
        $changedCount = 0;

        foreach ($catalogue->texts() as $key => $text) {
            $row = ['key' => $key, 'owner' => $text['owner'], 'values' => [], 'changed' => false];

            foreach ($languages as $language) {
                $code = $language->code;
                // English is the key itself: a file never translates it.
                $file = $code === 'en' ? $key : ($text['files'][$code] ?? '');
                $mine = $own[$code][$key] ?? null;
                $row['values'][$code] = ['file' => $file, 'own' => $mine, 'shown' => $mine ?? $file];
                $row['changed'] = $row['changed'] || $mine !== null;
            }

            $changedCount += (int) $row['changed'];

            if ($filters['changed'] && ! $row['changed']) {
                continue;
            }

            if ($filters['q'] !== '' && ! $this->matches($row, $filters['q'])) {
                continue;
            }

            $rows[] = $row;
        }

        // Searched for, the short texts first: "Bonus points" before the
        // hint that mentions them.
        if ($filters['q'] !== '') {
            usort($rows, fn ($a, $b) => mb_strlen($a['key']) <=> mb_strlen($b['key']));
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 50;

        return view('admin.texts.index', [
            'filters' => $filters,
            'languages' => $languages,
            'counts' => ['all' => count($catalogue->texts()), 'changed' => $changedCount],
            'rows' => new LengthAwarePaginator(
                array_slice($rows, ($page - 1) * $perPage, $perPage),
                count($rows), $perPage, $page,
                ['path' => $request->url(), 'query' => $request->query()],
            ),
        ]);
    }

    /** @param  array{key: string, values: array<string, array{file: string, own: ?string, shown: string}>}  $row */
    protected function matches(array $row, string $q): bool
    {
        if (mb_stripos($row['key'], $q) !== false) {
            return true;
        }

        foreach ($row['values'] as $value) {
            if (mb_stripos($value['file'], $q) !== false || ($value['own'] !== null && mb_stripos($value['own'], $q) !== false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One text, every enabled language at once. A value equal to the file's
     * (or empty) is the file's again: no row. "Back to the original" drops
     * every language's row for the key.
     */
    public function save(Request $request, TextCatalogue $catalogue, TextOverrides $overrides)
    {
        $key = (string) $request->input('key', '');

        if ($key === '') {
            return back();
        }

        if ($request->input('action') === 'reset') {
            $overrides->forget($key);

            return back()->with('status', __('Back to the file\'s own text.'));
        }

        $files = $catalogue->texts()[$key]['files'] ?? [];
        $values = (array) $request->input('values', []);
        $kept = [];

        foreach (Language::where('enabled', true)->pluck('code') as $code) {
            $typed = trim((string) ($values[$code] ?? ''));
            $file = $code === 'en' ? $key : ($files[$code] ?? '');

            if ($typed !== '' && $typed !== $file && ($why = $overrides->refusal($key, $typed)) !== null) {
                return back()->withInput()->withErrors(['text' => $why]);
            }

            $kept[$code] = $typed === $file ? '' : $typed;
        }

        foreach ($kept as $code => $value) {
            $overrides->put($code, $key, $value);
        }

        return back()->with('status', __('Saved.'));
    }
}
