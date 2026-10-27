<?php

namespace Torgash\Automations\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\RuleTemplate;

/**
 * Rules in and out as JSON, so a shop can be handed a working integration
 * instead of a page of instructions.
 */
class TemplatesController extends Controller
{
    public function export(Rule $rule)
    {
        $payload = json_encode(
            RuleTemplate::export($rule),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $name = preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($rule->name)) ?: 'rule';

        return response($payload, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="automation-'.trim($name, '-').'.json"',
        ]);
    }

    /** Paste or upload; both end up as the same text. */
    public function form()
    {
        return view('torgash.automations::admin.templates.import', ['payload' => null, 'json' => old('json', '')]);
    }

    /**
     * Step one: read the template and ask for the parts it deliberately does
     * not carry — the endpoint and every header value.
     */
    public function review(Request $request)
    {
        $json = $this->json($request);
        $payload = json_decode((string) $json, true);

        if (! is_array($payload) || ! isset($payload['torgash_automation'])) {
            return back()->withInput(['json' => $json])
                ->withErrors(['json' => __('That is not a Torgash automation template.')]);
        }

        return view('torgash.automations::admin.templates.import', [
            'payload' => $payload,
            'json' => $json,
            'needs' => RuleTemplate::needs($payload),
        ]);
    }

    /** Step two: build it, switched off, and drop the admin into its editor. */
    public function store(Request $request)
    {
        $payload = json_decode((string) $request->input('json'), true);

        if (! is_array($payload)) {
            return back()->withErrors(['json' => __('That is not a Torgash automation template.')]);
        }

        $rule = RuleTemplate::import($payload, (array) $request->input('answers', []));

        return redirect()->route('admin.automations.edit', $rule)->with(
            'status',
            __('Imported and left switched off. Check it with "Test", then enable it.'),
        );
    }

    protected function json(Request $request): string
    {
        if ($request->hasFile('file')) {
            return (string) file_get_contents($request->file('file')->getRealPath());
        }

        return (string) $request->input('json', '');
    }
}
