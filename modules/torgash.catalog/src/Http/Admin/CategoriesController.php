<?php

namespace Torgash\Catalog\Http\Admin;

use App\Core\Seo\MetaFields;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Support\CategoryTree;

class CategoriesController extends Controller
{
    /**
     * The tree, in tree order — the screen a shop owner reads to understand
     * the shape of their catalogue is the last place a flat list sorted by
     * `sort` belongs. Not paged: a page boundary cuts a branch in half.
     * Searching (name in every language, or the address) turns it into a flat
     * list of what matched, each with its path, since a match's parents may
     * not match themselves.
     */
    public function index(Request $request)
    {
        $tree = app(CategoryTree::class);
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), Category::STATUSES, true) ? $request->query('status') : null;

        $categories = $tree->ordered()
            ->when($q !== '', fn ($all) => $all->filter(fn (Category $c) => str_contains(mb_strtolower(json_encode($c->name, JSON_UNESCAPED_UNICODE).' '.$c->slug), mb_strtolower($q))))
            ->when($status, fn ($all) => $all->filter(fn (Category $c) => $c->status === $status))
            ->values();

        // How many products each row holds DIRECTLY — one query for the page.
        $productCounts = DB::table('m_catalog_category_product')
            ->whereIn('category_id', $categories->pluck('id'))
            ->groupBy('category_id')->selectRaw('category_id, count(*) as n')->pluck('n', 'category_id');

        return view('torgash.catalog::admin.categories.index', [
            'categories' => $categories,
            'tree' => $tree,
            'filters' => ['q' => $q, 'status' => $status],
            'flat' => $q !== '',
            'productCounts' => $productCounts,
            'statusCounts' => ['' => $tree->all()->count()] + collect(Category::STATUSES)->mapWithKeys(fn ($s) => [$s => $tree->all()->where('status', $s)->count()])->all(),
            'trashedCount' => Category::onlyTrashed()->count(),
        ]);
    }

    /**
     * Several at once: set a status, or move to the trash — through the
     * models, so the audit and the slug redirects still hear each one.
     * `enable` / `disable` are the old words for `active` / `disabled`, still accepted.
     */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in([...Category::STATUSES, 'enable', 'disable', 'trash'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $categories = Category::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'trash') {
            $categories->each->delete();
            $message = trans_choice(':count category moved to trash.|:count categories moved to trash.', $categories->count(), ['count' => $categories->count()]);
        } else {
            $status = ['enable' => Category::STATUS_ACTIVE, 'disable' => Category::STATUS_DISABLED][$validated['action']] ?? $validated['action'];
            $categories->each(function (Category $category) use ($status) {
                $category->status = $status;
                $category->save();
            });
            $message = trans_choice(':count category updated.|:count categories updated.', $categories->count(), ['count' => $categories->count()]);
        }

        return redirect()->back()->with('status', $message);
    }

    /** `?parent=` comes from "Add subcategory" in another category's menu. */
    /** The list's in-place switch: answers with the status cell and the row's name. */
    public function setStatus(Request $request, Category $category)
    {
        $request->validate(['status' => ['required', Rule::in(Category::STATUSES)]]);
        $category->update(['status' => $request->input('status')]);

        return view('torgash.catalog::admin.categories._status', ['category' => $category, 'oob' => true]);
    }

    public function create(Request $request)
    {
        $parent = Category::find((int) $request->query('parent'));

        return view('torgash.catalog::admin.categories.form', [
            'category' => new Category(['parent_id' => $parent?->id]),
            'parents' => app(CategoryTree::class)->options(),
        ]);
    }

    public function store(Request $request)
    {
        $category = Category::create($this->data($request));

        return $this->afterSave($request, $category, __('Category created.'));
    }

    public function edit(Category $category)
    {
        // Neighbours in TREE order — the order the list is read in, so the
        // arrows walk the catalogue the way the shop built it.
        $ids = app(CategoryTree::class)->ordered()->pluck('id')->all();
        $at = array_search($category->id, $ids, true);

        return view('torgash.catalog::admin.categories.form', [
            'category' => $category,
            'prevCategory' => $at !== false && $at > 0 ? Category::find($ids[$at - 1]) : null,
            'nextCategory' => $at !== false && isset($ids[$at + 1]) ? Category::find($ids[$at + 1]) : null,
            'productCount' => $category->products()->count(),
            // The whole branch is excluded, not just this row: making a
            // category the child of its own grandchild is a tree that answers
            // no question, and the picker is where that is best prevented.
            'parents' => app(CategoryTree::class)->options(exclude: [$category->id]),
        ]);
    }

    public function update(Request $request, Category $category)
    {
        // A changed address leaves its redirect from the model (KeepsAddress).
        $category->update($this->data($request, $category));

        return $this->afterSave($request, $category, __('Category saved.'));
    }

    /**
     * Back to the form, as the product form does: being thrown to the list
     * after every save is the annoying half of editing. "Save and close" is
     * the explicit way out.
     */
    protected function afterSave(Request $request, Category $category, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.catalog.categories.index')
            : redirect()->route('admin.catalog.categories.edit', $category))->with('status', $message);
    }

    public function destroy(Category $category)
    {
        $category->delete();

        return redirect()->route('admin.catalog.categories.index')
            ->with('status', __('Category moved to trash.'));
    }

    public function trash()
    {
        return view('torgash.catalog::admin.categories.trash', [
            'categories' => Category::onlyTrashed()->orderByDesc('deleted_at')->get(),
        ]);
    }

    public function restore(int $id)
    {
        Category::onlyTrashed()->findOrFail($id)->restore();

        return redirect()->route('admin.catalog.categories.index')
            ->with('status', __('Category restored.'));
    }

    /**
     * Permanent. The children FK cascades, so this also wipes any subcategory
     * that is still sitting in the trash under it.
     */
    public function forceDelete(int $id)
    {
        Category::onlyTrashed()->findOrFail($id)->forceDelete();

        return redirect()->route('admin.catalog.categories.trash')
            ->with('status', __('Category deleted permanently.'));
    }

    public function emptyTrash()
    {
        Category::onlyTrashed()->get()->each->forceDelete();

        return redirect()->route('admin.catalog.categories.trash')->with('status', __('Trash emptied.'));
    }

    protected function data(Request $request, ?Category $category = null): array
    {
        $locale = admin_content_locale();
        $isBaseLocale = $locale === content_base_locale();

        $validated = $request->validate([
            'name' => [Rule::requiredIf($isBaseLocale), 'nullable', 'string'],
            'slug' => 'nullable|string|unique:m_catalog_categories,slug'.($category ? ','.$category->id : ''),
            'parent_id' => [
                'nullable', 'exists:m_catalog_categories,id',
                // ...and again on the way in, because the picker is only the
                // polite half: a posted id naming this category's own
                // descendant would make a loop nothing could walk out of.
                function (string $attribute, $value, $fail) use ($category) {
                    if ($category?->exists && in_array((int) $value, app(CategoryTree::class)->descendants((int) $category->id), true)) {
                        $fail(__('A category cannot sit inside itself.'));
                    }
                },
            ],
            'sort' => 'nullable|integer',
            'status' => ['nullable', Rule::in(Category::STATUSES)],
            'description' => 'nullable|string',
            'icon' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            // A raster picture: an SVG served from the shop's own origin is a page that can run script.
            'icon_file' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:512'],
            'tile_file' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048'],
        ] + MetaFields::rules());

        $name = $category?->name ?? [];
        if (trim($validated['name'] ?? '') === '') {
            unset($name[$locale]);
        } else {
            $name[$locale] = $validated['name'];
        }
        $baseLocale = content_base_locale();
        $slugSource = $validated['slug'] ?: ($name[$baseLocale] ?? (reset($name) ?: 'category'));

        // The description, in the language being edited — the other
        // languages' texts stay as they were.
        $description = $category?->description ?? [];
        if (trim(strip_tags((string) ($validated['description'] ?? ''))) === '') {
            unset($description[$locale]);
        } else {
            $description[$locale] = $validated['description'];
        }

        return [
            'name' => $name,
            'description' => $description ?: null,
            'slug' => \Torgash\Catalog\Support\Slugs::make($slugSource),
            'parent_id' => $validated['parent_id'] ?? null,
            'sort' => (int) ($validated['sort'] ?? 0),
            'icon' => ($validated['icon'] ?? null) ?: null,
            'icon_image' => $this->iconImage($request, $category),
            'tile_image' => $this->picture($request, $category?->tile_image, 'tile', Category::TILE_DIR),
            // The form sends `status`; a client written for the old switch
            // sends `enabled`, and saying neither changes nothing.
            'status' => $validated['status']
                ?? ($request->has('enabled') ? ($request->boolean('enabled') ? Category::STATUS_ACTIVE : Category::STATUS_DISABLED) : null)
                ?? $category?->status
                ?? Category::STATUS_ACTIVE,
            // Who may see it and everything under it (PLAN §46).
            'customer_groups' => (function () use ($request, $category) {
                if (! $request->boolean('customer_groups_set')) {
                    return $category?->customer_groups;
                }
                $known = array_keys(\Torgash\Catalog\Support\Assortment::choices());
                $picked = array_values(array_intersect((array) $request->input('customer_groups', []), $known));

                return $picked === [] ? null : $picked;
            })(),
        ] + MetaFields::attributes($request, $category);
    }

    /**
     * The category's own icon picture: a new upload replaces the old file, the
     * "remove" box drops it, and otherwise it stays what it was. The old file
     * goes from the disk once nothing points at it any more.
     */
    protected function iconImage(Request $request, ?Category $category): ?string
    {
        return $this->picture($request, $category?->icon_image, 'icon', Category::ICON_DIR);
    }

    /** One of the category's pictures — `<input>_file` uploads, `<input>_image_remove` drops. */
    protected function picture(Request $request, ?string $current, string $input, string $dir): ?string
    {
        if ($request->hasFile($input.'_file')) {
            $file = $request->file($input.'_file');
            $path = $file->storeAs($dir, Str::random(20).'.'.strtolower($file->extension()), 'public');
            $this->forgetPictureFile($current, $dir);

            return $path;
        }

        if ($request->boolean($input.'_image_remove')) {
            $this->forgetPictureFile($current, $dir);

            return null;
        }

        return $current;
    }

    protected function forgetPictureFile(?string $path, string $dir): void
    {
        if ($path && str_starts_with($path, $dir.'/')) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
        }
    }
}
