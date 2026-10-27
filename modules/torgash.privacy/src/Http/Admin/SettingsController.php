<?php

namespace Torgash\Privacy\Http\Admin;

use App\Core\Privacy\PersonalData;
use App\Core\Settings\SettingsManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Privacy\Presets;
use Torgash\Privacy\Retention;

/**
 * Personal data → Settings (PLAN §49): whose law, which fills in the rest;
 * how long each kind of data is kept; and what on the site waits for consent
 * (`core.consent.inventory` — the snippets module answers with its list).
 */
class SettingsController extends Controller
{
    public function edit(PersonalData $data, Retention $retention)
    {
        return view('torgash.privacy::admin.settings', [
            'values' => [
                'regime' => setting('torgash.privacy', 'regime', 'eu'),
                'consent_mode' => setting('torgash.privacy', 'consent_mode', 'opt_in'),
                'banner_style' => setting('torgash.privacy', 'banner_style', 'bar'),
                'policy_url' => setting('torgash.privacy', 'policy_url', ''),
                'consent_doc_url' => setting('torgash.privacy', 'consent_doc_url', ''),
                'respect_gpc' => (bool) setting('torgash.privacy', 'respect_gpc', true),
                'google_consent_mode' => (bool) setting('torgash.privacy', 'google_consent_mode', true),
                'answer_days' => (int) setting('torgash.privacy', 'answer_days', 30),
                'retention' => $retention->days(),
            ],
            'retained' => $data->retained(),
            'presets' => Presets::all(),
            'inventory' => (array) hook_filter('core.consent.inventory', []),
        ]);
    }

    public function update(Request $request, SettingsManager $settings, PersonalData $data)
    {
        $validated = $request->validate([
            'regime' => ['required', Rule::in(['eu', 'us', 'ru', 'ua', 'custom'])],
            'consent_mode' => ['required', Rule::in(['opt_in', 'opt_out', 'off'])],
            'banner_style' => ['required', Rule::in(['bar', 'corner'])],
            'policy_url' => ['nullable', 'string', 'max:500', 'regex:~^(/|https?://)~i'],
            'consent_doc_url' => ['nullable', 'string', 'max:500', 'regex:~^(/|https?://)~i'],
            'answer_days' => ['required', 'integer', 'min:1', 'max:365'],
            'retention' => ['nullable', 'array'],
            'retention.*' => ['nullable', 'integer', 'min:0', 'max:36500'],
        ]);

        foreach (['regime', 'consent_mode', 'banner_style', 'answer_days'] as $key) {
            $settings->set('torgash.privacy', $key, $validated[$key]);
        }

        $settings->set('torgash.privacy', 'policy_url', (string) ($validated['policy_url'] ?? ''));
        $settings->set('torgash.privacy', 'consent_doc_url', (string) ($validated['consent_doc_url'] ?? ''));
        $settings->set('torgash.privacy', 'respect_gpc', $request->boolean('respect_gpc'));
        $settings->set('torgash.privacy', 'google_consent_mode', $request->boolean('google_consent_mode'));

        // Only the entries that exist, as whole days.
        $retention = [];
        foreach (array_keys($data->retained()) as $key) {
            $retention[$key] = (int) ($validated['retention'][$key] ?? 0);
        }
        $settings->set('torgash.privacy', 'retention', $retention);

        return redirect()->route('admin.privacy.settings')->with('status', __('Settings saved.'));
    }
}
