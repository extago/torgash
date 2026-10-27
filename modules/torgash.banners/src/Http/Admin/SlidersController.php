<?php

namespace Torgash\Banners\Http\Admin;

use App\Core\Display\DisplayRules;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Torgash\Banners\Fields;
use Torgash\Banners\Listing;
use Torgash\Banners\Models\Banner;
use Torgash\Banners\Models\Slider;
use Torgash\Banners\Models\Template;
use Torgash\Blocks\Models\Block;

/**
 * Sliders (PLAN §51): a frame, which banners in which order, how the strip
 * moves, a second slider beside it. A banner whose template cannot draw the
 * frame's shape is said so here, not discovered on the storefront.
 */
class SlidersController extends Controller
{
    public function index()
    {
        return view('torgash.banners::admin.sliders.index', [
            'sliders' => Slider::query()->with(['frame', 'aside:id,name'])->withCount('banners')->orderBy('name')->get(),
            'placed' => $this->placements(),
        ]);
    }

    public function create()
    {
        $first = Template::query()->kind('frame')->orderBy('id')->first();

        if (! $first) {
            return redirect()->route('admin.banners.sliders.index')->withErrors(__('There is no frame template yet. Add one in Templates first.'));
        }

        return $this->form(new Slider(['frame_id' => $first->id, 'settings' => Slider::DEFAULTS, 'values' => []]));
    }

    public function edit(Slider $slider)
    {
        return $this->form($slider);
    }

    public function store(Request $request)
    {
        $slider = DB::transaction(function () use ($request) {
            $slider = Slider::query()->create($this->data($request, new Slider));
            $this->order($slider, $request);

            return $slider;
        });

        return $this->afterSave($request, $slider, __('Slider created.'));
    }

    public function update(Request $request, Slider $slider)
    {
        DB::transaction(function () use ($request, $slider) {
            $slider->update($this->data($request, $slider));
            $this->order($slider, $request);
        });

        return $this->afterSave($request, $slider, __('Slider saved.'));
    }

    /** The banners stay — they may stand in other sliders; the blocks showing it show nothing. */
    public function destroy(Slider $slider)
    {
        $slider->delete();

        return redirect()->route('admin.banners.sliders.index')->with('status', __('Slider deleted.'));
    }

    protected function afterSave(Request $request, Slider $slider, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.banners.sliders.index')
            : redirect()->route('admin.banners.sliders.edit', $slider))->with('status', $message);
    }

    protected function form(Slider $slider)
    {
        $inSlider = $slider->exists ? $slider->banners()->with(['media', 'template'])->get() : collect();

        return view('torgash.banners::admin.sliders.form', [
            'slider' => $slider,
            'options' => $slider->options(),
            'listing' => Listing::settings((array) $slider->settings),
            'frames' => Template::query()->kind('frame')->orderBy('name')->get(),
            'inSlider' => $inSlider,
            'others' => Banner::query()->with('template')->whereNotIn('id', $inSlider->pluck('id'))->orderByDesc('id')->get(),
            'asides' => Slider::query()->when($slider->exists, fn ($q) => $q->whereKeyNot($slider->id))->orderBy('name')->get(['id', 'name']),
            'placed' => $slider->exists ? ($this->placements()[$slider->id] ?? []) : [],
        ]);
    }

    /** @return array<string, mixed> */
    protected function data(Request $request, Slider $slider): array
    {
        // "4,3" is how a Russian keyboard types a fraction.
        foreach (['per_view', 'per_view_tablet', 'per_view_phone'] as $count) {
            if (is_string($request->input($count))) {
                $request->merge([$count => str_replace(',', '.', $request->input($count))]);
            }
        }

        $validated = $request->validate([
            'name' => 'required|string|max:190',
            'frame_id' => ['required', 'integer', Rule::exists('m_banners_templates', 'id')->where('kind', 'frame')],
            'aside_id' => ['nullable', 'integer', Rule::exists('m_banners_sliders', 'id'), Rule::notIn([$slider->id])],
            'effect' => ['nullable', Rule::in(['slide', 'fade'])],
            'autoplay' => 'nullable|integer|min:0|max:60',
            'per_view' => 'nullable|numeric|min:1|max:8',
            'per_view_tablet' => 'nullable|numeric|min:1|max:6',
            'per_view_phone' => 'nullable|numeric|min:1|max:4',
            'drag' => ['nullable', Rule::in(Slider::DRAGS)],
            'step' => ['nullable', Rule::in(Slider::STEPS)],
            'speed' => ['nullable', Rule::in(array_keys(Slider::SPEEDS))],
            'listing_after' => 'nullable|integer|min:1|max:100',
            'listing_every' => 'nullable|integer|min:0|max:100',
            'values' => 'nullable|array',
        ]);

        $frame = Template::query()->findOrFail($validated['frame_id']);
        $values = (array) $slider->values;

        // The frame's own fields — a colour, a switch — but no pictures: a
        // slider holds banners, not media.
        foreach ($frame->fieldList() as $field) {
            if ($field['type'] === 'image') {
                continue;
            }

            $raw = $validated['values'][$field['key']] ?? null;
            $value = Fields::accept($field, match ($field['type']) {
                'checkbox' => $raw ?? false,
                'links' => (array) ($raw ?? []),
                default => $raw ?? '',
            });

            if ($value !== null) {
                $values[$field['key']] = match (true) {
                    $field['type'] === 'links' => Fields::mergeLinks($values[$field['key']] ?? [], $value) ?: null,
                    in_array($field['type'], Fields::TRANSLATABLE, true) => put_translation(is_array($values[$field['key']] ?? null) ? $values[$field['key']] : null, $value) ?: null,
                    default => $value,
                };
            }
        }

        return [
            'name' => $validated['name'],
            'frame_id' => $frame->id,
            'aside_id' => $validated['aside_id'] ?? null,
            'values' => array_filter($values, fn ($value) => $value !== null),
            'settings' => Slider::shapeOptions([
                'effect' => $validated['effect'] ?? 'slide',
                'autoplay' => (int) ($validated['autoplay'] ?? 0),
                'loop' => $request->boolean('loop'),
                'per_view' => $validated['per_view'] ?? 1,
                'per_view_tablet' => $validated['per_view_tablet'] ?? 1,
                'per_view_phone' => $validated['per_view_phone'] ?? 1,
                'drag' => $validated['drag'] ?? 'snap',
                'step' => $validated['step'] ?? 'one',
                'speed' => $validated['speed'] ?? 'normal',
            ]) + [
                // Between the products of a catalogue listing, and where:
                // core's display rules, asked with the listing's context.
                'listing' => [
                    'on' => $request->boolean('listing_on'),
                    'after' => (int) ($validated['listing_after'] ?? 6),
                    'every' => (int) ($validated['listing_every'] ?? 0),
                    'once' => $request->boolean('listing_once'),
                    'rules' => app(DisplayRules::class)->normalise($request->input('rules', [])) ?? [],
                    'match' => $request->input('rules_match') === DisplayRules::MATCH_ANY ? DisplayRules::MATCH_ANY : DisplayRules::MATCH_ALL,
                ],
            ],
        ];
    }

    /** The banners in the order the list was dragged into. */
    protected function order(Slider $slider, Request $request): void
    {
        $posted = array_values(array_unique(array_map('intval', (array) $request->input('banners', []))));
        $known = Banner::query()->whereIn('id', $posted)->pluck('id')->all();
        $sync = [];

        foreach (array_values(array_filter($posted, fn ($id) => in_array($id, $known, true))) as $i => $id) {
            $sync[$id] = ['position' => $i + 1];
        }

        $slider->banners()->sync($sync);
    }

    /**
     * Which blocks show each slider: zone and title, for "where is it".
     *
     * @return array<int, list<array{zone: string, title: string, enabled: bool}>>
     */
    protected function placements(): array
    {
        $placed = [];

        foreach (Block::query()->where('type', 'banners')->get() as $block) {
            $id = (int) (((array) $block->settings)['slider'] ?? 0);

            if ($id) {
                $placed[$id][] = ['zone' => $block->zone, 'title' => $block->displayTitle(), 'enabled' => (bool) $block->enabled];
            }
        }

        return $placed;
    }
}
