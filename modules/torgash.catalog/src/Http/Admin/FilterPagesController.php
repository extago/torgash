<?php

namespace Torgash\Catalog\Http\Admin;

use App\Core\Seo\MetaFields;
use App\Core\Seo\RedirectManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\FilterRule;
use Torgash\Catalog\Filters\Flags;
use Torgash\Catalog\Support\CategoryTree;

/**
 * The pages themselves — the ones a rule made and the ones somebody wrote.
 *
 * Two things here are not CRUD boilerplate, and they are the reason this class
 * has a docblock at all.
 *
 * **An address that has been published is never simply withdrawn.** Deleting a
 * page leaves a 301 to its category behind it, and renaming one leaves a 301
 * from the old address — the same rule categories and products already follow,
 * and what lets a shop be reorganised without losing what it earned.
 *
 * **The combination is unique.** Two pages standing for the same set of values
 * are two addresses showing the same products, which is exactly the duplication
 * this table exists to remove — so the fingerprint is checked before the row is
 * written, and refused as a validation message rather than as an error page:
 * the admin has just typed a name and a description, and losing them to a 422
 * for a ticked box is how a form gets called hostile.
 *
 * **A town counts as something the page IS.** A page must stand for something
 * beyond its category — values, a town, or both — so "Ноутбуки в Киеве" is a
 * page with no values at all, and only a page with neither is refused
 * (PLAN §30.9).
 */
class FilterPagesController extends Controller
{
    public function __construct(protected RedirectManager $redirects) {}

    /** The tabs of the list, in order: what a shop looks for when it opens it. */
    public const TABS = ['', 'published', 'off', 'empty'];

    public function index(Request $request)
    {
        $locale = admin_content_locale();
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'category' => (int) $request->query('category'),
            'rule' => (int) $request->query('rule'),
            'tab' => in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : '',
        ];

        $query = FilterPage::query()
            ->when($filters['category'], fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['rule'], fn ($q, $id) => $q->where('rule_id', $id))
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw("coalesce(name->>?, '') ilike ?", [$locale, '%'.$filters['q'].'%'])
                ->orWhere('slug', 'ilike', '%'.$filters['q'].'%')));

        // Counted over what the search leaves, so a tab never promises rows
        // the table will not show. "No products" is the stored count — the
        // one the generator keeps — because it is a list, not the page itself
        // (which works emptiness out live).
        $counts = [
            '' => (clone $query)->count(),
            'published' => (clone $query)->where('enabled', true)->count(),
            'off' => (clone $query)->where('enabled', false)->count(),
            'empty' => (clone $query)->where('products_count', 0)->count(),
        ];

        match ($filters['tab']) {
            'published' => $query->where('enabled', true),
            'off' => $query->where('enabled', false),
            'empty' => $query->where('products_count', 0),
            default => null,
        };

        $pages = $query->with('category', 'rule')
            ->orderBy('category_id')->orderBy('position')->orderBy('id')
            ->paginate(50)->withQueryString();

        return view('torgash.catalog::admin.filter-pages.index', [
            'pages' => $pages,
            'placeNames' => $this->placeNames($pages->pluck('place_id')->all()),
            'categories' => app(CategoryTree::class)->options(),
            'rules' => FilterRule::orderBy('position')->orderBy('id')->pluck('name', 'id'),
            'filters' => $filters,
            'counts' => $counts,
        ]);
    }

    /** The list's in-place switch: answers with the cell. */
    public function setStatus(Request $request, FilterPage $filterPage)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $filterPage->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.catalog::admin.filter-pages._status', ['page' => $filterPage]);
    }

    /**
     * Several at once. Model by model — the audit hears Eloquent events, and a
     * deleted page must leave its 301 exactly as a single delete does.
     */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $pages = FilterPage::with('category')->whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $pages->each(fn (FilterPage $page) => $this->retire($page));

            return back()->with('status', trans_choice(
                ':count filter page deleted; its address now redirects to the category.|:count filter pages deleted; their addresses now redirect to the category.',
                $pages->count(), ['count' => $pages->count()],
            ));
        }

        $pages->each(fn (FilterPage $page) => $page->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count filter page updated.|:count filter pages updated.', $pages->count(), ['count' => $pages->count()]));
    }

    public function create()
    {
        return $this->form(new FilterPage(['enabled' => true, 'in_sitemap' => true, 'in_list' => true]));
    }

    public function edit(FilterPage $filterPage)
    {
        return $this->form($filterPage);
    }

    public function store(Request $request)
    {
        $page = FilterPage::create($this->data($request));
        $this->recount($page);

        return $this->afterSave($request, $page, __('Filter page created.'));
    }

    public function update(Request $request, FilterPage $filterPage)
    {
        $before = $filterPage->loadMissing('category')->path();

        $filterPage->update($this->data($request, $filterPage));
        $this->recount($filterPage);

        $after = $filterPage->load('category')->path();

        if ($after !== $before) {
            $this->redirects->recordChange($before, $after);
        }

        return $this->afterSave($request, $filterPage, __('Filter page updated.'));
    }

    /**
     * Gone, and pointing at the category it narrowed.
     *
     * A 301 rather than the addon's configurable "nothing / 302 / 301": the
     * category genuinely is the page this one was a subset of, so it is the
     * right destination in every case we could think of — and three options
     * here would mean two of them are wrong.
     */
    public function destroy(FilterPage $filterPage)
    {
        $this->retire($filterPage);

        return redirect()->route('admin.catalog.filter-pages.index')
            ->with('status', __('Filter page deleted; its address now redirects to the category.'));
    }

    /**
     * The stored count, taken now — a page written by hand is otherwise
     * counted only when a rule happens to reach the same combination, and the
     * list would warn "no products" about a full page.
     */
    protected function recount(FilterPage $page): void
    {
        $page->load('category')->forceFill(['products_count' => $page->productsQuery()->count()])->save();
    }

    /** Deleted, leaving a 301 to the category it narrowed — one place for both doors. */
    protected function retire(FilterPage $page): void
    {
        $page->loadMissing('category');

        $path = $page->path();
        $target = '/catalog/'.$page->category->slug;

        $page->delete();
        $this->redirects->put($path, $target);
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, FilterPage $page, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.catalog.filter-pages.index')
            : redirect()->route('admin.catalog.filter-pages.edit', $page))->with('status', $message);
    }

    protected function form(FilterPage $page)
    {
        $categories = app(CategoryTree::class)->options();
        $categoryId = (int) ($page->category_id ?: array_key_first($categories));

        return view('torgash.catalog::admin.filter-pages.form', [
            'page' => $page,
            'categories' => $categories,
            'features' => $this->featuresFor($categoryId),
            'selectedValues' => $page->valueSet(),
            'flags' => app(Flags::class)->all(),
            'selectedFlags' => $page->flagSet(),
            'placeName' => $this->placeNames([$page->place_id])[(int) $page->place_id] ?? '',
        ]);
    }

    /**
     * The characteristic picker on its own, for the category just chosen.
     *
     * Swapped in by HTMX rather than rendered for every category at once: a
     * real catalogue has hundreds of characteristics with thousands of values
     * between them, and a form that ships all of them is slow to load, slow to
     * search and impossible to read. What the shop sees is what the STOREFRONT
     * would offer on that category — the same assignment, read the same way up
     * the tree — so a page cannot be built out of a characteristic that does
     * not apply where it is published.
     *
     * The ticks are deliberately not carried across the swap: the values of
     * another category are not valid for this one, and silently keeping them
     * would write a page whose address promises what its category cannot show.
     */
    public function values(Request $request)
    {
        return view('torgash.catalog::admin.filter-pages._values', [
            'features' => $this->featuresFor((int) $request->query('category_id')),
            'selectedValues' => [],
        ]);
    }

    /**
     * The characteristics that apply to a category — attached to it, to one of
     * its ancestors, or to nothing at all (which is how Brand and Colour work).
     *
     * The one place in the tree read UPWARDS: the products come from below, the
     * rules from above. The same assignment the storefront panel reads,
     * deliberately — but NOT the same visibility: a page is an ADDRESS, and a
     * characteristic the sidebar does not offer a control for can still be the
     * one a whole landing page is published on (see Features\Places).
     *
     * @return \Illuminate\Support\Collection<int, Feature>
     */
    protected function featuresFor(int $categoryId)
    {
        $query = Feature::orderBy('sort')->with('values');

        if ($categoryId) {
            $branch = app(CategoryTree::class)->ancestors($categoryId, includeSelf: true);

            $query->where(fn ($q) => $q->whereDoesntHave('categories')
                ->orWhereHas('categories', fn ($cq) => $cq->whereIn('category_id', $branch)));
        }

        return $query->get();
    }

    /**
     * Town names for the ids stored on pages — one query for the screen, and
     * nothing at all when no place registry is installed.
     *
     * @param  list<int|null>  $ids
     * @return array<int, string>
     */
    protected function placeNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : (array) hook_filter('locations.place.names', [], ['ids' => $ids]);
    }

    /** @return array<string, mixed> */
    protected function data(Request $request, ?FilterPage $page = null): array
    {
        $locale = admin_content_locale();

        $validated = $request->validate([
            'category_id' => 'required|exists:m_catalog_categories,id',
            'slug' => 'nullable|string|max:190',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'position' => 'nullable|integer',
            'values' => 'array',
            'flags' => 'array',
            'flags.*' => 'string|max:64',
            // No `exists:` rule: the table belongs to another module, and this
            // one has to keep working with it uninstalled — the same reason the
            // column carries no foreign key.
            'place_id' => 'nullable|integer|min:1',
            'price_min' => 'nullable|numeric|min:0',
            'price_max' => 'nullable|numeric|min:0',
        ] + MetaFields::rules());

        $values = FilterPage::canonicalValues((array) ($validated['values'] ?? []));
        $placeId = (int) ($validated['place_id'] ?? 0) ?: null;
        $priceMin = FilterPage::minorAmount($validated['price_min'] ?? null);
        $priceMax = FilterPage::minorAmount($validated['price_max'] ?? null);

        if ($priceMin !== null && $priceMax !== null && $priceMin > $priceMax) {
            throw ValidationException::withMessages([
                'price_min' => __('The lower price is above the upper one.'),
            ]);
        }

        // Only keys the shop can actually answer for: a flag nobody registers
        // narrows a page to nothing, and a typo in a form must not publish an
        // empty address. What IS registered is another module's business — the
        // list comes from the registry, never from a constant here.
        $flags = array_values(array_filter(
            FilterPage::canonicalFlags((array) ($validated['flags'] ?? [])),
            fn (string $key) => app(Flags::class)->find($key) !== null,
        ));

        if ($values === [] && ! $placeId && $flags === [] && $priceMin === null && $priceMax === null) {
            throw ValidationException::withMessages([
                'values' => __('A page has to stand for something: choose characteristic values, a system filter, a town, a price range, or several of them.'),
            ]);
        }

        $fingerprint = FilterPage::fingerprintFor((int) $validated['category_id'], $values, $placeId, $flags, $priceMin, $priceMax);

        $clash = FilterPage::where('fingerprint', $fingerprint)
            ->when($page?->exists, fn ($q) => $q->where('id', '!=', $page->id))
            ->first();

        if ($clash) {
            throw ValidationException::withMessages([
                'values' => __('Another page already stands for exactly this in this category.'),
            ]);
        }

        return [
            'category_id' => (int) $validated['category_id'],
            'place_id' => $placeId,
            'slug' => $this->slug($validated, $values, $flags, $page, $priceMin, $priceMax),
            'values' => $values,
            'flags' => $flags ?: null,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'fingerprint' => $fingerprint,
            'name' => $this->translated($page?->name, $locale, $validated['name'] ?? null),
            'description' => $this->translated($page?->description, $locale, $validated['description'] ?? null),
            'position' => (int) ($validated['position'] ?? 0),
            'enabled' => $request->boolean('enabled'),
            'in_sitemap' => $request->boolean('in_sitemap'),
            'in_list' => $request->boolean('in_list'),
        ] + MetaFields::attributes($request, $page);
    }

    /**
     * The address. Typed wins; otherwise the name does, and failing that the
     * value ids — and a number is appended rather than a second page quietly
     * taking an address that is already answering.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int, list<int>>  $values
     * @param  list<string>  $flags
     */
    protected function slug(array $validated, array $values, array $flags, ?FilterPage $page, ?int $priceMin = null, ?int $priceMax = null): string
    {
        // The last resorts, in the order they read best: the values it stands
        // for, else the system filters (their keys ARE words — `in_stock`),
        // else the price range (bare numbers — the same reason the generator's
        // own default price slug is), else the town. Every one of them is only
        // reached when nobody typed a slug and nobody named the page.
        $derived = match (true) {
            $values !== [] => 'f'.implode('-', array_merge(...array_values($values))),
            $flags !== [] => Str::slug(implode('-', $flags)),
            $priceMin !== null || $priceMax !== null => Str::slug(
                ($priceMin !== null ? FilterPage::bareAmount($priceMin) : '')
                .'-'.($priceMax !== null ? FilterPage::bareAmount($priceMax) : ''),
            ),
            default => 'p'.(int) ($validated['place_id'] ?? 0),
        };

        $slug = Str::slug((string) ($validated['slug'] ?? ''))
            ?: Str::slug((string) ($validated['name'] ?? ''))
            ?: $derived;

        $base = $slug;
        $n = 1;

        while (FilterPage::where('category_id', $validated['category_id'])
            ->where('slug', $slug)
            ->when($page?->exists, fn ($q) => $q->where('id', '!=', $page->id))
            ->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    /**
     * One language of a jsonb field, merged into the others — the same rule
     * MetaFields states: a blank box CLEARS this language rather than storing
     * an empty string.
     *
     * @param  array<string, string>|null  $existing
     * @return array<string, string>|null
     */
    protected function translated(?array $existing, string $locale, ?string $typed): ?array
    {
        $values = (array) $existing;
        $typed = trim((string) $typed);

        if ($typed === '') {
            unset($values[$locale]);
        } else {
            $values[$locale] = $typed;
        }

        return $values === [] ? null : $values;
    }
}
