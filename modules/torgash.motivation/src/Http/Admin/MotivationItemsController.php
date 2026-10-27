<?php

namespace Torgash\Motivation\Http\Admin;

use App\Core\Modules\ModuleManager;
use App\Core\Support\Icons;
use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Media\ImageProcessor;
use Torgash\Motivation\Models\MotivationItem;

class MotivationItemsController extends Controller
{
    public function __construct(protected ImageProcessor $images) {}

    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'state' => in_array($request->query('state'), ['on', 'off'], true) ? $request->query('state') : '',
        ];

        // A handful of cards, never a thousand: read once, filtered in PHP, so
        // the search matches the title in every language it is written in.
        $all = MotivationItem::with(['categories', 'products', 'customerGroups'])->orderBy('sort')->orderBy('id')->get();
        $searched = $filters['q'] === '' ? $all : $all->filter(fn (MotivationItem $item) => collect($item->name ?? [])
            ->contains(fn ($name) => mb_stripos((string) $name, $filters['q']) !== false));

        return view('torgash.motivation::admin.index', [
            'items' => $searched->filter(fn (MotivationItem $item) => match ($filters['state']) {
                'on' => $item->enabled,
                'off' => ! $item->enabled,
                default => true,
            })->values(),
            'filters' => $filters,
            'stateCounts' => [
                '' => $searched->count(),
                'on' => $searched->where('enabled', true)->count(),
                'off' => $searched->where('enabled', false)->count(),
            ],
        ]);
    }

    /** The list's in-place switch: answers with the cell. */
    public function setStatus(Request $request, MotivationItem $item)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $item->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.motivation::admin._status', ['item' => $item]);
    }

    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $items = MotivationItem::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $items->each->delete();

            return back()->with('status', trans_choice(':count card deleted.|:count cards deleted.', $items->count(), ['count' => $items->count()]));
        }

        $items->each(fn (MotivationItem $item) => $item->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count card updated.|:count cards updated.', $items->count(), ['count' => $items->count()]));
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, MotivationItem $item, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.motivation.index')
            : redirect()->route('admin.motivation.edit', $item))->with('status', $message);
    }

    public function create()
    {
        return view('torgash.motivation::admin.form', $this->formData(new MotivationItem));
    }

    public function store(Request $request)
    {
        $item = MotivationItem::create($this->data($request));
        $this->syncScoping($request, $item);
        $this->attachIcon($request, $item);

        return $this->afterSave($request, $item, __('Card created.'));
    }

    public function edit(MotivationItem $item)
    {
        return view('torgash.motivation::admin.form', $this->formData($item));
    }

    public function update(Request $request, MotivationItem $item)
    {
        $item->update($this->data($request, $item));
        $this->syncScoping($request, $item);
        $this->attachIcon($request, $item);

        return $this->afterSave($request, $item, __('Card updated.'));
    }

    public function destroy(MotivationItem $item)
    {
        $item->delete();

        return redirect()->route('admin.motivation.index')->with('status', __('Card deleted.'));
    }

    /** Copies everything, including scoping and the icon file — same idea as product duplication. */
    public function duplicate(MotivationItem $item)
    {
        $copy = $item->replicate(['created_at', 'updated_at']);
        $copy->name = collect($item->name ?? [])->map(fn ($v) => $v.' ('.__('copy').')')->all();
        $copy->save();

        $copy->categories()->sync($item->categories->pluck('id'));
        $copy->products()->sync($item->products->pluck('id'));
        $copy->customerGroups()->sync($item->customerGroups->pluck('id'));

        if ($item->icon_type === 'image' && ($media = $item->getFirstMedia('icon'))) {
            $media->copy($copy, 'icon');
        }

        return redirect()->route('admin.motivation.edit', $copy)->with('status', __('Card duplicated.'));
    }

    protected function formData(MotivationItem $item): array
    {
        $locale = admin_content_locale();
        $categories = app(CategoryTree::class)->ordered();
        $categoryNames = $categories->mapWithKeys(fn ($c) => [$c->id => $c->t('name', $locale)]);

        // Full "Parent / Child" path so a deep category tree stays readable in
        // the picker, same as the product form's category picker.
        $categoryPath = function ($category) use ($categories, $categoryNames) {
            $parts = [$categoryNames[$category->id] ?? $category->slug];
            $parentId = $category->parent_id;
            $guard = 0;
            while ($parentId && $guard++ < 20) {
                $parent = $categories->firstWhere('id', $parentId);
                if (! $parent) {
                    break;
                }
                array_unshift($parts, $categoryNames[$parent->id] ?? $parent->slug);
                $parentId = $parent->parent_id;
            }

            return implode(' / ', $parts);
        };

        return [
            'item' => $item,
            'categoryOptions' => $categories->map(fn ($c) => ['id' => $c->id, 'label' => $categoryPath($c)])->sortBy('label')->values(),
            'productOptions' => Product::orderBy('sku')->get(['id', 'sku', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'label' => $p->sku.' — '.$p->t('name', $locale)])->values(),
            'customerGroupOptions' => CustomerGroup::orderBy('id')->get()
                ->map(fn ($g) => ['id' => $g->id, 'label' => $g->t('name', $locale)])->values(),
            'iconNames' => Icons::names(),
            'checkoutEnabled' => app(ModuleManager::class)->isEnabled('torgash.checkout'),
        ];
    }

    protected function syncScoping(Request $request, MotivationItem $item): void
    {
        $item->categories()->sync($request->input('category_ids', []));
        $item->products()->sync($request->input('product_ids', []));
        $item->customerGroups()->sync($request->input('customer_group_ids', []));
    }

    /** singleFile() on the 'icon' collection means media-library replaces the old file on its own. */
    protected function attachIcon(Request $request, MotivationItem $item): void
    {
        if ($request->hasFile('icon_image')) {
            $this->images->addUpload($item, $request->file('icon_image'), 'icon');
        }
    }

    protected function data(Request $request, ?MotivationItem $item = null): array
    {
        $locale = admin_content_locale();
        $isBaseLocale = $locale === content_base_locale();

        $validated = $request->validate([
            'name' => [Rule::requiredIf($isBaseLocale), 'nullable', 'string'],
            'sort' => 'nullable|integer',
            'icon_type' => ['required', Rule::in(MotivationItem::ICON_TYPES)],
            'icon_name' => ['nullable', 'string', 'max:64', function ($attribute, $value, $fail) use ($request) {
                if ($request->input('icon_type') === 'lucide' && $value && ! Icons::exists($value)) {
                    $fail(__('Unknown icon name.'));
                }
            }],
            'icon_color' => ['nullable', Rule::in(MotivationItem::COLORS)],
            'content_type' => ['required', Rule::in(MotivationItem::CONTENT_TYPES)],
            'content' => 'nullable|string',
            'icon_image' => 'nullable|image',
        ]);

        $name = $this->mergeTranslatable($item?->name, $validated['name'] ?? '', $locale);
        $content = $validated['content_type'] === 'html'
            ? $this->mergeTranslatable($item?->content, $validated['content'] ?? '', $locale)
            : null;

        return [
            'name' => $name,
            'sort' => (int) ($validated['sort'] ?? 0),
            'expanded' => $request->boolean('expanded'),
            'enabled' => $request->boolean('enabled'),
            'icon_type' => $validated['icon_type'],
            'icon_name' => $validated['icon_type'] === 'lucide' ? ($validated['icon_name'] ?: null) : null,
            'icon_color' => $validated['icon_type'] === 'lucide' ? ($validated['icon_color'] ?? null) : null,
            'content_type' => $validated['content_type'],
            'content' => $content,
            'exclude_categories' => $request->boolean('exclude_categories'),
            'exclude_products' => $request->boolean('exclude_products'),
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
}
