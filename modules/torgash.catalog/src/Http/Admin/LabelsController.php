<?php

namespace Torgash\Catalog\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Support\CategoryTree;

class LabelsController extends Controller
{
    public const COLORS = ['primary', 'secondary', 'accent', 'neutral', 'info', 'success', 'warning', 'error'];

    public const POSITIONS = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];

    /**
     * The product page has places OFF the photo too — a row of labels over
     * the short description, and one under the buy button (where a pictogram
     * such as "RAM 32 GB" reads as part of the offer). A card has only its
     * photo's corners.
     */
    public const DETAIL_ROWS = ['above-description', 'under-buy'];

    /** Either place may say "not here" — a pictogram belongs beside the buy button, not on every card. */
    public const HIDDEN = 'hidden';

    /**
     * The list, searched in every language and in the admin name, narrowed by
     * status. A handful of rows, so filtered in PHP rather than with a jsonb
     * query per language — the categories list's own arrangement.
     */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['enabled', 'disabled'], true) ? $request->query('status') : null;
        $all = Label::withCount('products')->orderBy('sort')->orderBy('id')->get();

        $labels = $all
            ->when($q !== '', fn ($rows) => $rows->filter(fn (Label $l) => str_contains(
                mb_strtolower(json_encode($l->name, JSON_UNESCAPED_UNICODE).' '.$l->name_for_admin),
                mb_strtolower($q),
            )))
            ->when($status, fn ($rows) => $rows->filter(fn (Label $l) => $l->enabled === ($status === 'enabled')))
            ->values();

        return view('torgash.catalog::admin.labels.index', [
            'labels' => $labels,
            'filters' => ['q' => $q, 'status' => $status],
            'statusCounts' => [
                '' => $all->count(),
                'enabled' => $all->where('enabled', true)->count(),
                'disabled' => $all->where('enabled', false)->count(),
            ],
        ]);
    }

    /** The list's in-place switch: answers with the status cell. */
    public function setStatus(Request $request, Label $label)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $label->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.catalog::admin.labels._status', ['label' => $label]);
    }

    /** Several at once — through the models, so the audit hears each one. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $labels = Label::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $labels->each->delete();
            $message = trans_choice(':count label deleted.|:count labels deleted.', $labels->count(), ['count' => $labels->count()]);
        } else {
            $labels->each(fn (Label $label) => $label->update(['enabled' => $validated['action'] === 'enable']));
            $message = trans_choice(':count label updated.|:count labels updated.', $labels->count(), ['count' => $labels->count()]);
        }

        return redirect()->back()->with('status', $message);
    }

    public function create()
    {
        return view('torgash.catalog::admin.labels.form', [
            'label' => new Label,
            'categories' => app(CategoryTree::class)->options(),
            'features' => Feature::with('values')->orderBy('sort')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $label = Label::create($this->data($request, null));
        $this->attachImage($request, $label);

        return $this->afterSave($request, $label, __('Label created.'));
    }

    public function edit(Label $label)
    {
        // Neighbours in the list's own order.
        $ids = Label::orderBy('sort')->orderBy('id')->pluck('id')->all();
        $at = array_search($label->id, $ids, true);

        return view('torgash.catalog::admin.labels.form', [
            'label' => $label,
            'prevLabel' => $at !== false && $at > 0 ? Label::find($ids[$at - 1]) : null,
            'nextLabel' => $at !== false && isset($ids[$at + 1]) ? Label::find($ids[$at + 1]) : null,
            'categories' => app(CategoryTree::class)->options(),
            'features' => Feature::with('values')->orderBy('sort')->get(),
        ]);
    }

    public function update(Request $request, Label $label)
    {
        $label->update($this->data($request, $label));
        $this->attachImage($request, $label);

        return $this->afterSave($request, $label, __('Label saved.'));
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, Label $label, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.catalog.labels.index')
            : redirect()->route('admin.catalog.labels.edit', $label))->with('status', $message);
    }

    public function destroy(Label $label)
    {
        $label->delete();

        return redirect()->route('admin.catalog.labels.index')->with('status', __('Label deleted.'));
    }

    protected function attachImage(Request $request, Label $label): void
    {
        if (! $request->hasFile('image')) {
            return;
        }

        $file = $request->file('image');

        // An SVG is a document that can carry a script: rebuilt from an
        // allow-list before it is published (App\Core\Support\SafeSvg), and
        // refused when nothing drawable is left.
        if (strtolower($file->getClientOriginalExtension()) === 'svg' || $file->getMimeType() === 'image/svg+xml') {
            $svg = \App\Core\Support\SafeSvg::clean((string) file_get_contents($file->getRealPath()));

            if ($svg === null) {
                throw \Illuminate\Validation\ValidationException::withMessages(['image' => __('This SVG could not be read as a drawing.')]);
            }

            $label->addMediaFromString($svg)->usingFileName(\Illuminate\Support\Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'label').'.svg')->toMediaCollection('image');

            return;
        }

        $label->addMedia($file)->toMediaCollection('image');
    }

    protected function data(Request $request, ?Label $label): array
    {
        $locale = admin_content_locale();
        $isBaseLocale = $locale === content_base_locale();

        $validated = $request->validate([
            'name' => [Rule::requiredIf($isBaseLocale), 'nullable', 'string'],
            'section_title' => 'nullable|string|max:190',
            'name_for_admin' => 'required|string|max:190',
            'style' => ['required', Rule::in(Label::STYLES)],
            'icon' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]*$/'],
            'color' => ['required', Rule::in([...self::COLORS, Label::CUSTOM_COLOR])],
            'colors.light.bg' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'colors.light.text' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'colors.dark.bg' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'colors.dark.text' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'size_list' => ['nullable', Rule::in(Label::SIZES)],
            'size_detail' => ['nullable', Rule::in(Label::SIZES)],
            'image_height_list' => 'nullable|integer|min:12|max:160',
            'image_height_detail' => 'nullable|integer|min:12|max:160',
            'image_shape' => ['nullable', Rule::in(Label::SHAPES)],
            'tooltip' => 'nullable|string|max:500',
            'position_list' => ['required', Rule::in([...self::POSITIONS, self::HIDDEN])],
            'position_detail' => ['required', Rule::in([...self::POSITIONS, ...self::DETAIL_ROWS, self::HIDDEN])],
            'sort' => 'nullable|integer',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            // SVG too — cleaned before it is kept (attachImage()).
            'image' => 'nullable|file|mimes:png,jpg,jpeg,webp,gif,svg|max:2048',
        ]);

        $name = $this->mergeTranslatable($label?->name, $validated['name'] ?? '', $locale);
        $sectionTitle = $this->mergeTranslatable($label?->section_title, $validated['section_title'] ?? '', $locale);
        $tooltip = $this->mergeTranslatable($label?->tooltip, $validated['tooltip'] ?? '', $locale);

        // Only the light pair is required of a custom colour; the dark one is
        // stored only while its box is ticked, so unticking it really goes
        // back to "the same in both themes".
        $colors = $request->input('colors', []);
        $customColors = $validated['color'] === Label::CUSTOM_COLOR ? array_filter([
            'light' => ['bg' => $colors['light']['bg'] ?? '#111827', 'text' => $colors['light']['text'] ?? '#ffffff'],
            'dark' => $request->boolean('colors_dark')
                ? ['bg' => $colors['dark']['bg'] ?? '#f3f4f6', 'text' => $colors['dark']['text'] ?? '#111827']
                : null,
        ]) : ($label?->custom_colors);

        return [
            'name' => $name,
            'section_title' => $sectionTitle ?: null,
            'name_for_admin' => $validated['name_for_admin'],
            'style' => $validated['style'],
            // A Lucide name the pack does not have would draw nothing: kept
            // only when the file is there.
            'icon' => ($validated['icon'] ?? '') !== '' && is_file(resource_path('icons/lucide/'.$validated['icon'].'.svg')) ? $validated['icon'] : null,
            'color' => $validated['color'],
            'custom_colors' => $customColors,
            'size_list' => $validated['size_list'] ?? 'sm',
            'size_detail' => $validated['size_detail'] ?? 'sm',
            'image_height_list' => (int) ($validated['image_height_list'] ?? 28),
            'image_height_detail' => (int) ($validated['image_height_detail'] ?? 28),
            'image_shape' => $validated['image_shape'] ?? 'as_is',
            'tooltip' => $tooltip ?: null,
            'position_list' => $validated['position_list'],
            'position_detail' => $validated['position_detail'],
            'sort' => (int) ($validated['sort'] ?? 0),
            'enabled' => $request->boolean('enabled'),
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'conditions' => $this->buildConditions($request),
        ];
    }

    /** Writes $incoming into $existing[$locale] (JSONB translatable field), removing the key if blank. */
    protected function mergeTranslatable(?array $existing, string $incoming, string $locale): array
    {
        $existing ??= [];

        if (trim($incoming) === '') {
            unset($existing[$locale]);
        } else {
            $existing[$locale] = $incoming;
        }

        return $existing;
    }

    /**
     * Turns the rule-row repeater's POST fields into the flat {match, rules}
     * shape Label::applyConditions() reads. Rows missing a field/operator/
     * value are dropped rather than rejected — the admin can be mid-edit.
     */
    protected function buildConditions(Request $request): ?array
    {
        $rows = $request->input('rules', []);
        $rules = [];

        foreach ($rows as $row) {
            $field = $row['field'] ?? null;
            if (! in_array($field, ['price', 'stock', 'age_days', 'category', 'feature', 'discount', 'rrp', 'views', 'sales'], true)) {
                continue;
            }

            if ($field === 'rrp') {
                $rules[] = ['field' => 'rrp'];

                continue;
            }

            if ($field === 'category') {
                $value = array_values(array_filter((array) ($row['category_ids'] ?? [])));
                if (empty($value)) {
                    continue;
                }
                $rules[] = [
                    'field' => 'category',
                    'operator' => 'in',
                    'value' => array_map('intval', $value),
                ];

                continue;
            }

            if ($field === 'feature') {
                if (empty($row['feature_id']) || $row['value'] === '' || $row['value'] === null) {
                    continue;
                }
                $rules[] = [
                    'field' => 'feature',
                    'operator' => 'eq',
                    'feature_id' => (int) $row['feature_id'],
                    'value' => (int) $row['value'],
                ];

                continue;
            }

            if (! isset($row['operator']) || $row['value'] === '' || $row['value'] === null) {
                continue;
            }

            $rules[] = [
                'field' => $field,
                'operator' => $row['operator'],
                'value' => $row['value'],
            ];
        }

        if (empty($rules)) {
            return null;
        }

        return [
            'match' => $request->input('match') === 'any' ? 'any' : 'all',
            'rules' => $rules,
        ];
    }
}
