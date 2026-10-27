<?php

namespace Torgash\Banners\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Torgash\Banners\Fields;
use Torgash\Banners\Models\Slider;
use Torgash\Banners\Models\Template;
use Torgash\Banners\PageStyles;
use Torgash\Banners\Presets;
use Torgash\Banners\Renderer;
use Torgash\Banners\TemplateText;

/**
 * Templates (PLAN §51): HTML with {tokens}, CSS, and what each token is. The
 * fields are found in the HTML as it is typed; their author only says what
 * each one IS. Presets from the module and the theme are where templates
 * start and what "restore" puts back; a template travels as one JSON file.
 */
class TemplatesController extends Controller
{
    public function index(Presets $presets)
    {
        $templates = Template::query()->withCount(['banners', 'sliders'])->orderBy('kind')->orderBy('name')->get();
        $onDisk = $presets->all();

        return view('torgash.banners::admin.templates.index', [
            'templates' => $templates,
            'differs' => $templates->mapWithKeys(fn (Template $template) => [$template->id => $presets->differs($template)])->all(),
            'missing' => array_values(array_diff(array_keys($onDisk), $templates->pluck('key')->all())),
            'presets' => $onDisk,
        ]);
    }

    public function create(Request $request)
    {
        $kind = $request->query('kind') === 'frame' ? 'frame' : 'banner';

        return $this->form(new Template([
            'kind' => $kind,
            'shape' => $kind === 'frame' ? 'wide' : null,
            'html' => $kind === 'frame'
                ? ['frame' => "<div class=\"my-frame\">\n    {slides}\n    [<div class=\"my-frame-dots\">{dots}</div>]\n    {prev}{next}\n</div>"]
                : ['wide' => "<div class=\"my-banner\" style=\"background: {background}\">\n    [<div class=\"my-banner-title\">{title}</div>]\n    [<a class=\"my-banner-button\" href=\"{link}\">{button}</a>]\n</div>"],
            'css' => '',
            'fields' => [],
        ]));
    }

    public function edit(Template $template)
    {
        return $this->form($template);
    }

    public function store(Request $request)
    {
        $template = Template::query()->create($this->data($request, new Template));

        return $this->afterSave($request, $template, __('Template created.'));
    }

    public function update(Request $request, Template $template)
    {
        $template->update($this->data($request, $template));

        return $this->afterSave($request, $template, __('Template saved.'));
    }

    /** Not while something is drawn with it: a banner without a template is a banner nobody sees. */
    public function destroy(Template $template)
    {
        $used = $template->banners()->count() + $template->sliders()->count();

        if ($used > 0) {
            return back()->withErrors(__('":name" is used by :count banners or sliders. Give them another template first.', ['name' => $template->label(), 'count' => $used]));
        }

        $template->delete();

        return redirect()->route('admin.banners.templates.index')->with('status', __('Template deleted.'));
    }

    public function restore(Template $template, Presets $presets)
    {
        return $presets->restore($template)
            ? redirect()->route('admin.banners.templates.edit', $template)->with('status', __('Template restored from its preset. The banners keep what was typed into them.'))
            : back()->withErrors(__('There is no preset ":key" in the module or the theme any more.', ['key' => $template->key]));
    }

    /** The presets of the module and the theme not in the list yet. */
    public function seed(Presets $presets)
    {
        $added = $presets->seed();

        return back()->with('status', trans_choice(':count template added from the presets.|:count templates added from the presets.', $added, ['count' => $added]));
    }

    public function export(Template $template)
    {
        $file = [
            'torgash' => 'banner-template',
            'key' => $template->key,
            'kind' => $template->kind,
            'name' => $template->name,
            'shape' => $template->shape,
            'html' => $template->html,
            'css' => (string) $template->css,
            'fields' => $template->fieldList(),
        ];

        return response()->streamDownload(
            fn () => print(json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'banner-template-'.$template->key.'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /** A template file from another shop or a theme: a new template, under a free key. */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|max:512']);
        $data = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);

        if (! is_array($data) || ($data['torgash'] ?? null) !== 'banner-template' || ! in_array($data['kind'] ?? '', Template::KINDS, true)) {
            return back()->withErrors(__('This is not a banner template file.'));
        }

        $key = $this->freeKey(Str::slug((string) ($data['key'] ?? 'template')) ?: 'template');
        $html = [];

        foreach ((array) ($data['html'] ?? []) as $shape => $text) {
            if (in_array($shape, $data['kind'] === 'frame' ? ['frame'] : Template::SHAPES, true)) {
                [$text, $css] = TemplateText::clean((string) $text);
                $html[$shape] = $text;
                $data['css'] = trim(((string) ($data['css'] ?? ''))."\n".$css);
            }
        }

        $template = Template::query()->create([
            'key' => $key,
            'kind' => $data['kind'],
            'name' => mb_substr((string) ($data['name'] ?? $key), 0, 190),
            'shape' => $data['kind'] === 'frame' && in_array($data['shape'] ?? '', Template::SHAPES, true) ? $data['shape'] : null,
            'html' => $html,
            'css' => (string) ($data['css'] ?? ''),
            'fields' => array_values(array_filter(array_map([Fields::class, 'shape'], (array) ($data['fields'] ?? [])))),
        ]);

        return redirect()->route('admin.banners.templates.edit', $template)->with('status', __('Template imported.'));
    }

    /**
     * The template as it stands in the form, drawn with sample values — a
     * page of its own, put into the editor's frame. Every shape a banner
     * template has; a frame with three sample slides.
     */
    public function preview(Request $request)
    {
        $template = new Template($this->data($request, new Template, preview: true));
        $template->key = $template->key ?: 'preview';
        $renderer = app(Renderer::class);
        $samples = $this->samples($template);
        $out = '';

        if ($template->isFrame()) {
            $drawn = [];

            foreach ([1, 2, 3] as $n) {
                $drawn[] = '<div class="torgash-banner"><div style="height:100%;min-height:16rem;display:flex;align-items:center;justify-content:center;border-radius:1rem;font:600 2rem system-ui;color:#fff;background:hsl('.(200 + $n * 40).' 60% 55%)">'.$n.'</div></div>';
            }

            $aside = '<div style="display:grid;gap:1rem;height:100%"><div style="border-radius:1rem;background:#fff;padding:1.5rem">'.e(__('Beside it')).'</div><div style="border-radius:1rem;background:#fff;padding:1.5rem">'.e(__('Beside it')).'</div></div>';
            $out = $renderer->frame($template, $drawn, Slider::shapeOptions(['autoplay' => 0, 'per_view' => $request->input('per_view', 1)]), $aside, $samples);
        } else {
            $width = ['wide' => '100%', 'card' => '18rem', 'strip' => '100%'];
            $height = ['wide' => '25rem', 'card' => '26rem', 'strip' => 'auto'];

            foreach ($template->shapes() as $shape) {
                $html = Renderer::expand((string) $template->html[$shape], function (string $name, string $modifiers) use ($template, $samples) {
                    if ($name === 'loading') {
                        return 'loading="lazy"';
                    }

                    $field = collect($template->fieldList())->firstWhere('key', $name);

                    if (! $field) {
                        return null;
                    }

                    if ($field['type'] === 'image') {
                        return str_contains($modifiers, '|width') ? '1200' : (str_contains($modifiers, '|height') ? '700' : self::samplePicture());
                    }

                    return Fields::render($field, $samples, null, $modifiers);
                });

                $out .= '<p style="font:600 0.75rem system-ui;opacity:.5;margin:1rem 0 .5rem">'.e(self::shapeLabel($shape)).'</p>'
                    .'<div style="width:'.$width[$shape].';height:'.$height[$shape].'"><div class="torgash-banner" data-template="'.e($template->key).'" data-shape="'.$shape.'">'.$html.'</div></div>';
            }

            $out = app(PageStyles::class)->take($template).$out;
        }

        return response(view('torgash.banners::admin.templates.preview', ['body' => $out])->render())
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    protected function afterSave(Request $request, Template $template, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.banners.templates.index')
            : redirect()->route('admin.banners.templates.edit', $template))->with('status', $message);
    }

    protected function form(Template $template)
    {
        return view('torgash.banners::admin.templates.form', [
            'template' => $template,
            'engine' => Fields::ENGINE[$template->kind] ?? [],
            'preset' => $template->exists ? (app(Presets::class)->all()[$template->key] ?? null) : null,
            'differs' => $template->exists ? app(Presets::class)->differs($template) : null,
        ]);
    }

    /**
     * What the form posted, made safe to store: the HTML of each shape with
     * any <style> moved to the CSS and any <script> dropped; the fields of
     * the tokens the HTML really uses, a token nobody described becoming a
     * plain text field.
     *
     * @return array<string, mixed>
     */
    protected function data(Request $request, Template $template, bool $preview = false): array
    {
        $kind = $template->exists ? $template->kind : ($request->input('kind') === 'frame' ? 'frame' : 'banner');

        $validated = $request->validate([
            'name' => $preview ? 'nullable|string|max:190' : 'required|string|max:190',
            'key' => $template->exists || $preview ? 'nullable' : ['required', 'string', 'max:64', 'regex:'.Fields::KEY_PATTERN, Rule::unique('m_banners_templates', 'key')],
            'shape' => ['nullable', Rule::in(Template::SHAPES)],
            'html' => 'nullable|array',
            'html.*' => 'nullable|string|max:65535',
            'css' => 'nullable|string|max:65535',
            'fields' => 'nullable|array',
        ], [
            'key.regex' => __('The key is small Latin letters, digits and dashes, starting with a letter.'),
        ]);

        $css = (string) ($validated['css'] ?? '');
        $html = [];

        foreach ($kind === 'frame' ? ['frame'] : Template::SHAPES as $shape) {
            [$text, $moved] = TemplateText::clean((string) ($validated['html'][$shape] ?? ''));

            if ($text !== '') {
                $html[$shape] = $text;
            }

            if ($moved !== '') {
                $css = trim($css."\n".$moved);
            }
        }

        $used = [];

        foreach ($html as $text) {
            $used = [...$used, ...TemplateText::tokens($text)];
        }

        $used = array_values(array_diff(array_unique($used), Fields::ENGINE[$kind]));
        $posted = [];

        foreach ((array) ($validated['fields'] ?? []) as $row) {
            if (is_array($row) && isset($row['key'])) {
                $row['options'] = Fields::optionsFromText((string) ($row['options'] ?? ''));
                $posted[strtolower((string) $row['key'])] = $row;
            }
        }

        // In the order the form had them (the order of the banner's form);
        // a token nobody described yet joins at the end.
        $known = array_values(array_filter(array_keys($posted), fn ($key) => in_array($key, $used, true)));
        $fields = [];

        foreach ([...$known, ...array_diff($used, $known)] as $key) {
            $field = Fields::shape(($posted[$key] ?? []) + ['key' => $key, 'type' => 'text', 'label' => Str::headline($key)]);

            if ($field) {
                $fields[] = $field;
            }
        }

        return array_filter([
            'key' => $template->exists ? null : ($validated['key'] ?? null),
            'kind' => $template->exists ? null : $kind,
            'name' => $validated['name'] ?? ($preview ? 'Preview' : null),
            'shape' => $kind === 'frame' ? ($validated['shape'] ?? 'wide') : null,
            'html' => $html,
            'css' => $css,
            'fields' => $fields,
        ], fn ($value) => $value !== null) + ($kind === 'frame' ? [] : ['shape' => null]);
    }

    /** @return array<string, mixed> a value for each field to preview with */
    protected function samples(Template $template): array
    {
        $samples = [];

        foreach ($template->fieldList() as $field) {
            $samples[$field['key']] = match ($field['type']) {
                'text', 'rich' => label_text($field['label']),
                'link' => '/',
                'checkbox' => true,
                'icon' => 'sparkles',
                'links' => [['text' => label_text($field['label']).' 1', 'link' => '/'], ['text' => label_text($field['label']).' 2', 'link' => '/']],
                default => $field['default'],
            };
        }

        return $samples;
    }

    public static function shapeLabel(string $shape): string
    {
        return ['wide' => __('Wide slide'), 'card' => __('Upright card'), 'strip' => __('Low strip')][$shape] ?? $shape;
    }

    protected static function samplePicture(): string
    {
        return 'data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="700" viewBox="0 0 1200 700"><rect width="1200" height="700" fill="#cbd5e1"/><circle cx="820" cy="330" r="190" fill="#94a3b8"/></svg>');
    }

    protected function freeKey(string $key): string
    {
        $key = preg_match(Fields::KEY_PATTERN, $key) ? $key : 'template-'.$key;
        $candidate = $key;

        for ($n = 2; Template::query()->where('key', $candidate)->exists(); $n++) {
            $candidate = $key.'-'.$n;
        }

        return $candidate;
    }
}
