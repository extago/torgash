<?php

namespace Torgash\Collections\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Torgash\Catalog\Admin\ProductLookup;
use Torgash\Catalog\Filters\Flags;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Collections\Models\Collection;

/**
 * Marketing → Collections: the list and one collection's form — its page
 * (address, words, cover, dates, head) and which products, in the catalogue
 * filter's own words.
 */
class CollectionsController extends Controller
{
    /** Pictures only, checked by their bytes: the public disk is the shop's own address. */
    public const COVER_TYPES = ['image/webp' => 'webp', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/avif' => 'avif'];

    public function index()
    {
        $collections = Collection::orderBy('sort')->orderByDesc('id')->get();

        return view('torgash.collections::admin.index', [
            'collections' => $collections,
            'counts' => $collections->mapWithKeys(fn (Collection $c) => [$c->id => $c->productQuery()->count()]),
        ]);
    }

    public function create()
    {
        return view('torgash.collections::admin.form', $this->formData(new Collection(['name' => [], 'conditions' => []])));
    }

    public function store(Request $request)
    {
        $collection = new Collection;
        $this->fill($collection, $request);
        $collection->save();
        $this->afterSave($collection, $request);

        return redirect()->route('admin.collections.edit', $collection)->with('status', __('The collection is saved.'));
    }

    public function edit(Collection $collection)
    {
        // Neighbours in the list's own order.
        $ids = Collection::orderBy('sort')->orderByDesc('id')->pluck('id')->all();
        $at = array_search($collection->id, $ids, true);

        return view('torgash.collections::admin.form', $this->formData($collection) + [
            'prevCollection' => $at !== false && $at > 0 ? Collection::find($ids[$at - 1]) : null,
            'nextCollection' => $at !== false && isset($ids[$at + 1]) ? Collection::find($ids[$at + 1]) : null,
        ]);
    }

    /** The product pickers' answer to what the admin types — never the whole catalogue in the page. */
    public function products(Request $request, ProductLookup $lookup)
    {
        return response()->json($lookup->search((string) $request->query('q', '')));
    }

    public function update(Request $request, Collection $collection)
    {
        $this->fill($collection, $request);
        $collection->save();
        $this->afterSave($collection, $request);

        return $request->input('after_save') === 'close'
            ? redirect()->route('admin.collections.index')->with('status', __('Saved.'))
            : redirect()->route('admin.collections.edit', $collection)->with('status', __('Saved.'));
    }

    public function destroy(Collection $collection)
    {
        $collection->delete();

        return redirect()->route('admin.collections.index')->with('status', __('The collection is deleted.'));
    }

    protected function fill(Collection $collection, Request $request): void
    {
        $data = $request->validate([
            'name' => [content_locale_is_base() || ! $collection->exists ? 'required' : 'nullable', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9-]*$/', Rule::unique('m_collections', 'slug')->ignore($collection->id)],
            'description' => ['nullable', 'string', 'max:200000'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'sort' => ['nullable', 'integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'categories' => ['array'], 'categories.*' => ['integer'],
            'values' => ['array'], 'values.*' => ['integer'],
            'labels' => ['array'], 'labels.*' => ['integer'],
            'flags' => ['array'], 'flags.*' => ['string', Rule::in(array_keys(app(Flags::class)->all()))],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'picked' => ['array'], 'picked.*' => ['integer'],
            'excluded' => ['array'], 'excluded.*' => ['integer'],
            'cover' => ['nullable', 'file', 'max:8192'],
            'looks_new' => ['array', 'max:20'], 'looks_new.*' => ['file', 'max:12288'],
            'looks' => ['array'],
        ]);

        $name = put_translation($collection->name, (string) ($data['name'] ?? ''));

        $collection->fill([
            'name' => $name,
            'description' => put_translation($collection->description, (string) ($data['description'] ?? '')),
            'meta_title' => put_translation($collection->meta_title, (string) ($data['meta_title'] ?? '')),
            'meta_description' => put_translation($collection->meta_description, (string) ($data['meta_description'] ?? '')),
            'slug' => $this->slug($collection, (string) ($data['slug'] ?? ''), $name),
            'enabled' => $request->boolean('enabled'),
            'listed' => $request->boolean('listed'),
            'sort' => (int) ($data['sort'] ?? 0),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'conditions' => [
                'categories' => array_values(array_unique(array_map('intval', $data['categories'] ?? []))),
                'features' => $this->featuresOf(array_map('intval', $data['values'] ?? [])),
                'labels' => array_values(array_unique(array_map('intval', $data['labels'] ?? []))),
                'flags' => array_values(array_unique($data['flags'] ?? [])),
                'price_min' => FilterPage::minorAmount($data['price_min'] ?? ''),
                'price_max' => FilterPage::minorAmount($data['price_max'] ?? ''),
            ],
        ]);
    }

    /** The cover (replaced, or removed with its box) and the picked / left-out products. */
    protected function afterSave(Collection $collection, Request $request): void
    {
        if ($request->boolean('remove_cover') && $collection->cover) {
            Storage::disk('public')->delete($collection->cover);
            $collection->update(['cover' => null]);
        }

        if ($file = $request->file('cover')) {
            $extension = self::COVER_TYPES[(string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath())] ?? null;

            if ($extension !== null) {
                if ($collection->cover) {
                    Storage::disk('public')->delete($collection->cover);
                }
                $path = Collection::FILES.'/'.$collection->id.'/cover-'.Str::random(8).'.'.$extension;
                Storage::disk('public')->putFileAs(dirname($path), $file, basename($path));
                $collection->update(['cover' => $path]);
            }
        }

        $this->saveLooks($collection, $request);

        $excluded = array_values(array_unique(array_map('intval', (array) $request->input('excluded', []))));
        $picked = array_values(array_diff(array_unique(array_map('intval', (array) $request->input('picked', []))), $excluded));
        $known = Product::withTrashed()->whereIn('id', [...$picked, ...$excluded])->pluck('id')->flip();

        DB::table('m_collection_products')->where('collection_id', $collection->id)->delete();
        $rows = [];
        foreach ([[$picked, false], [$excluded, true]] as [$ids, $isExcluded]) {
            foreach ($ids as $id) {
                if (isset($known[$id])) {
                    $rows[] = ['collection_id' => $collection->id, 'product_id' => $id, 'excluded' => $isExcluded];
                }
            }
        }
        if ($rows !== []) {
            DB::table('m_collection_products')->insert($rows);
        }
    }

    /** The looks: points and captions of those there, deletions, new pictures. */
    protected function saveLooks(Collection $collection, Request $request): void
    {
        $looksInput = array_filter((array) $request->input('looks', []), 'is_array');
        $decoded = array_map(fn ($input) => (array) json_decode((string) ($input['points'] ?? '[]'), true), $looksInput);
        $named = collect($decoded)->flatten(1)->map(fn ($point) => is_array($point) ? (int) ($point['product_id'] ?? 0) : 0)->filter()->unique()->values()->all();
        $productIds = Product::withTrashed()->whereIn('id', $named)->pluck('id')->flip();

        foreach ($looksInput as $lookId => $input) {
            $look = $collection->looks()->find((int) $lookId);
            if (! $look) {
                continue;
            }
            if (! empty($input['delete'])) {
                $look->delete();

                continue;
            }

            $points = [];
            foreach ($decoded[$lookId] as $point) {
                $productId = (int) ($point['product_id'] ?? 0);
                if (is_array($point) && isset($productIds[$productId])) {
                    $points[] = [
                        'x' => round(max(0, min(100, (float) ($point['x'] ?? 0))), 1),
                        'y' => round(max(0, min(100, (float) ($point['y'] ?? 0))), 1),
                        'product_id' => $productId,
                    ];
                }
            }

            $look->update([
                'points' => $points,
                'caption' => put_translation($look->caption, (string) ($input['caption'] ?? '')),
                'sort' => (int) ($input['sort'] ?? $look->sort),
            ]);
        }

        foreach ((array) $request->file('looks_new', []) as $file) {
            $extension = self::COVER_TYPES[(string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath())] ?? null;
            if ($extension === null) {
                continue;
            }
            $path = Collection::FILES.'/'.$collection->id.'/look-'.Str::random(10).'.'.$extension;
            Storage::disk('public')->putFileAs(dirname($path), $file, basename($path));
            $collection->looks()->create(['image' => $path, 'points' => [], 'sort' => (int) $collection->looks()->max('sort') + 1]);
        }
    }

    /** @return array<int, list<int>> feature id => its picked values */
    protected function featuresOf(array $valueIds): array
    {
        $out = [];

        foreach (DB::table('m_catalog_feature_values')->whereIn('id', $valueIds)->get(['id', 'feature_id']) as $row) {
            $out[(int) $row->feature_id][] = (int) $row->id;
        }

        return $out;
    }

    /** What was typed, else the name in the base language transliterated, else collection-<n>; never somebody else's. */
    protected function slug(Collection $collection, string $typed, array $name): string
    {
        $base = Str::slug($typed !== '' ? $typed : ($name[content_base_locale()] ?? (reset($name) ?: ''))) ?: 'collection-'.(Collection::max('id') + 1);
        $slug = $base;

        for ($n = 2; Collection::where('slug', $slug)->whereKeyNot($collection->id)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    protected function formData(Collection $collection): array
    {
        $locale = admin_content_locale();
        $links = $collection->exists
            ? DB::table('m_collection_products')->where('collection_id', $collection->id)->get(['product_id', 'excluded'])
            : collect();
        $conditions = (array) ($collection->conditions ?? []);

        return [
            'collection' => $collection,
            'categoryOptions' => collect(app(CategoryTree::class)->options())->map(fn ($label, $id) => ['id' => (int) $id, 'label' => $label])->values(),
            'valueOptions' => Feature::query()->with('values')->orderBy('id')->get()
                ->flatMap(fn (Feature $f) => $f->values->map(fn ($v) => ['id' => (int) $v->id, 'label' => $f->t('name', $locale).': '.$v->t('value', $locale)]))
                ->values(),
            'flagOptions' => app(Flags::class)->options(),
            'labelOptions' => \Torgash\Catalog\Models\Label::orderBy('sort')->orderBy('id')->get()
                ->map(fn ($l) => ['id' => (int) $l->id, 'label' => $l->plainName($locale).($l->hasRules() && ! $l->rulesAreSql() ? ' '.__('(hand-picked only)') : '')])->values(),
            'selectedLabels' => array_map('intval', (array) ($conditions['labels'] ?? [])),
            // The words of the products the looks' points stand on, by id.
            'lookProducts' => collect(app(ProductLookup::class)->options(
                $collection->exists ? $collection->looks->flatMap(fn ($l) => array_column((array) $l->points, 'product_id'))->all() : []
            ))->pluck('label', 'id')->all(),
            'selectedCategories' => array_map('intval', (array) ($conditions['categories'] ?? [])),
            'selectedValues' => collect((array) ($conditions['features'] ?? []))->flatten()->map(fn ($id) => (int) $id)->values()->all(),
            'selectedFlags' => (array) ($conditions['flags'] ?? []),
            'picked' => $links->where('excluded', false)->pluck('product_id')->map(fn ($id) => (int) $id)->values()->all(),
            'excluded' => $links->where('excluded', true)->pluck('product_id')->map(fn ($id) => (int) $id)->values()->all(),
            'matches' => $collection->exists ? $collection->productQuery()->count() : null,
        ];
    }
}
