<?php

namespace Torgash\Banners\Http\Admin;

use App\Core\Display\DisplayRules;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Torgash\Banners\Clicks;
use Torgash\Banners\Fields;
use Torgash\Banners\Models\Banner;
use Torgash\Banners\Models\Slider;
use Torgash\Banners\Models\Template;
use Torgash\Media\ImageProcessor;

/**
 * The banners list and a banner's form (PLAN §51). The form is the chosen
 * template's fields and nothing else — the HTML is not the banner editor's
 * to see or break. Values of another template's fields stay on the row, so
 * switching the template and back loses nothing.
 */
class BannersController extends Controller
{
    public const STATES = ['running', 'scheduled', 'ended', 'off'];

    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'state' => in_array($request->query('state'), self::STATES, true) ? $request->query('state') : '',
        ];

        // A shop has dozens of these, not thousands: read once, counted per
        // state for the tabs, searched in the name and the words.
        $all = Banner::query()->with(['media', 'template', 'sliders:id,name'])->orderByDesc('id')->get();
        $searched = $filters['q'] === '' ? $all : $all->filter(
            fn (Banner $banner) => mb_stripos($banner->name.' '.json_encode($banner->values, JSON_UNESCAPED_UNICODE), $filters['q']) !== false
        );

        $shown = $filters['state'] === '' ? $searched->values() : $searched->filter(fn (Banner $banner) => $banner->state() === $filters['state'])->values();

        return view('torgash.banners::admin.banners.index', [
            'banners' => $shown,
            'clicks' => app(Clicks::class)->summary($shown->pluck('id')->all()),
            'filters' => $filters,
            'counts' => ['' => $searched->count()] + collect(self::STATES)
                ->mapWithKeys(fn ($state) => [$state => $searched->filter(fn (Banner $banner) => $banner->state() === $state)->count()])->all(),
        ]);
    }

    public function create(Request $request)
    {
        $first = Template::query()->kind('banner')->orderBy('id')->first();

        if (! $first) {
            return redirect()->route('admin.banners.index')->withErrors(__('There is no banner template yet. Add one in Templates first.'));
        }

        $banner = new Banner(['template_id' => $first->id, 'enabled' => true, 'values' => [], 'rules_match' => DisplayRules::MATCH_ALL]);

        // "+ New banner" from a slider's form puts the new one straight into it.
        $sliderId = (int) $request->query('slider');

        return $this->form($banner, $sliderId ? [$sliderId] : []);
    }

    public function edit(Banner $banner)
    {
        return $this->form($banner, $banner->sliders()->pluck('m_banners_sliders.id')->all());
    }

    public function store(Request $request)
    {
        [$banner, $template] = DB::transaction(function () use ($request) {
            [$data, $template] = $this->data($request, new Banner);
            $banner = Banner::query()->create($data);
            $this->sliders($banner, $request);

            return [$banner, $template];
        });

        $errors = $this->images($banner, $template, $request);

        return $this->afterSave($request, $banner, __('Banner created.'), $errors);
    }

    public function update(Request $request, Banner $banner)
    {
        $template = DB::transaction(function () use ($request, $banner) {
            [$data, $template] = $this->data($request, $banner);
            $banner->update($data);
            $this->sliders($banner, $request);

            return $template;
        });

        $errors = $this->images($banner, $template, $request);

        return $this->afterSave($request, $banner, __('Banner saved.'), $errors);
    }

    public function destroy(Banner $banner)
    {
        $banner->delete();

        return redirect()->route('admin.banners.index')->with('status', __('Banner deleted.'));
    }

    /**
     * The next banner starts from this one: its template, values and
     * pictures — in no slider and switched off, so the copy is never on the
     * storefront before it has been made into what it is for.
     */
    public function duplicate(Banner $banner)
    {
        $copy = $banner->replicate(['created_at', 'updated_at']);
        $copy->name = $banner->name.' ('.__('copy').')';
        $copy->enabled = false;
        $copy->save();

        foreach ($banner->media as $media) {
            $media->copy($copy, $media->collection_name);
        }

        return redirect()->route('admin.banners.edit', $copy)->with('status', __('Banner copied. The copy is off and in no slider until you put it there.'));
    }

    /** The list's in-place switch: answers with the cell. */
    public function setStatus(Request $request, Banner $banner)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $banner->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.banners::admin.banners._status', ['banner' => $banner]);
    }

    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        // Model by model: the audit hears Eloquent events, and the pictures
        // go with a deleted banner only through its model.
        $banners = Banner::query()->whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $banners->each->delete();

            return back()->with('status', trans_choice(':count banner deleted.|:count banners deleted.', $banners->count(), ['count' => $banners->count()]));
        }

        $banners->each(fn (Banner $banner) => $banner->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count banner updated.|:count banners updated.', $banners->count(), ['count' => $banners->count()]));
    }

    /** @param list<string> $errors */
    protected function afterSave(Request $request, Banner $banner, string $message, array $errors = [])
    {
        $redirect = $request->input('after_save') === 'close' && $errors === []
            ? redirect()->route('admin.banners.index')
            : redirect()->route('admin.banners.edit', $banner);

        $redirect->with('status', $message);

        // Only when there is something to say: an empty bag still reads as "errors".
        return $errors === [] ? $redirect : $redirect->withErrors($errors);
    }

    /** @param list<int> $sliderIds */
    protected function form(Banner $banner, array $sliderIds)
    {
        return view('torgash.banners::admin.banners.form', [
            'banner' => $banner,
            'templates' => Template::query()->kind('banner')->orderBy('name')->get(),
            'sliders' => Slider::query()->with('frame:id,shape')->orderBy('name')->get(['id', 'name', 'frame_id']),
            'inSliders' => array_map('intval', old('sliders', $sliderIds)),
            'clicks' => $banner->exists ? (app(Clicks::class)->summary([$banner->id])[$banner->id] ?? ['week' => 0, 'month' => 0, 'total' => 0]) : null,
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: Template} */
    protected function data(Request $request, Banner $banner): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:190',
            'template_id' => ['required', 'integer', Rule::exists('m_banners_templates', 'id')->where('kind', 'banner')],
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'values' => 'nullable|array',
        ]);

        $template = Template::query()->findOrFail($validated['template_id']);
        $posted = (array) ($validated['values'] ?? []);
        $values = (array) $banner->values;
        $errors = [];

        foreach ($template->fieldList() as $field) {
            if ($field['type'] === 'image') {
                continue;
            }

            $raw = $posted[$field['key']] ?? null;
            $value = Fields::accept($field, match ($field['type']) {
                'checkbox' => $raw ?? false,
                'links' => (array) ($raw ?? []),
                default => $raw ?? '',
            });

            if ($value === null) {
                // A link that is not an address is refused, never stored into an href.
                if ($field['type'] === 'link') {
                    $errors['values.'.$field['key']] = __('":field" is an address on this site (/sale) or a full one (https://…).', ['field' => label_text($field['label'])]);
                }

                continue;
            }

            // Words in the language being edited; every other language is kept —
            // for a list of links, row by row.
            $values[$field['key']] = match (true) {
                $field['type'] === 'links' => Fields::mergeLinks($values[$field['key']] ?? [], $value) ?: null,
                in_array($field['type'], Fields::TRANSLATABLE, true) => put_translation(is_array($values[$field['key']] ?? null) ? $values[$field['key']] : null, $value) ?: null,
                default => $value,
            };
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [[
            'name' => $validated['name'],
            'template_id' => $template->id,
            'values' => array_filter($values, fn ($value) => $value !== null),
            'starts_at' => isset($validated['starts_at']) ? Carbon::parse($validated['starts_at']) : null,
            'ends_at' => isset($validated['ends_at']) ? Carbon::parse($validated['ends_at']) : null,
            'enabled' => $request->boolean('enabled'),
            'rules' => app(DisplayRules::class)->normalise($request->input('rules', [])),
            'rules_match' => $request->input('rules_match') === DisplayRules::MATCH_ANY ? DisplayRules::MATCH_ANY : DisplayRules::MATCH_ALL,
        ], $template];
    }

    /**
     * The sliders it stands in. A slider it joins puts it last; one it stays
     * in keeps its place.
     */
    protected function sliders(Banner $banner, Request $request): void
    {
        $wanted = Slider::query()->whereIn('id', array_map('intval', (array) $request->input('sliders', [])))->pluck('id')->all();
        $current = $banner->sliders()->pluck('m_banners_sliders.id')->all();

        $banner->sliders()->detach(array_diff($current, $wanted));

        foreach (array_diff($wanted, $current) as $sliderId) {
            $last = (int) DB::table('m_banners_slider_banner')->where('slider_id', $sliderId)->max('position');
            $banner->sliders()->attach($sliderId, ['position' => $last + 1]);
        }
    }

    /**
     * The template's pictures: a new file replaces the old one, "remove"
     * takes it away. A picture that cannot be read is said, and the rest of
     * the banner is saved all the same.
     *
     * @return list<string>
     */
    protected function images(Banner $banner, Template $template, Request $request): array
    {
        $errors = [];

        foreach ($template->fieldList() as $field) {
            if (! in_array($field['type'], ['image', 'video'], true)) {
                continue;
            }

            $collection = Fields::collection($field['key']);
            $file = $request->file('images.'.$field['key']);

            if ($field['type'] === 'video') {
                $errors = [...$errors, ...$this->video($banner, $collection, $file, $request->boolean('remove_images.'.$field['key']))];

                continue;
            }

            if ($request->boolean('remove_images.'.$field['key']) || $file) {
                $banner->clearMediaCollection($collection);
            }

            if (! $file) {
                continue;
            }

            if (! $file->isValid()) {
                $errors[] = __('The picture could not be uploaded: :error', ['error' => $file->getErrorMessage()]);

                continue;
            }

            try {
                app(ImageProcessor::class)->addUpload($banner, $file, $collection);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * A video behind a slide: mp4 or webm by its own bytes, up to
     * `Fields::videoMaxBytes()`, kept as it came — no thumbnails, no
     * conversion (a loop of a few seconds needs none).
     *
     * @return list<string>
     */
    protected function video(Banner $banner, string $collection, ?UploadedFile $file, bool $remove): array
    {
        if ($remove && ! $file) {
            $banner->clearMediaCollection($collection);

            return [];
        }

        if (! $file) {
            return [];
        }

        if (! $file->isValid()) {
            return [__('The video could not be uploaded: :error', ['error' => $file->getErrorMessage()])];
        }

        $type = Fields::videoType($file->getRealPath());

        if ($type === null) {
            return [__('This is not an mp4 or webm video, whatever its name says.')];
        }

        if ($file->getSize() > Fields::videoMaxBytes()) {
            return [__('The video is :size MB; up to :max MB is taken here.', ['size' => (int) ceil($file->getSize() / 1048576), 'max' => (int) (Fields::videoMaxBytes() / 1048576)])];
        }

        $banner->clearMediaCollection($collection);
        $banner->addMedia($file->getRealPath())
            ->preservingOriginal()
            ->usingFileName((Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'video').'.'.Fields::VIDEO_TYPES[$type])
            ->toMediaCollection($collection);

        return [];
    }
}
