<?php

namespace Torgash\Catalog\Http\Admin;

use App\Core\Display\DisplayRules;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductTab;
use Torgash\Catalog\Support\ProductSections;

/**
 * The tabs a shop writes onto its product pages.
 *
 * One screen for both kinds, because they are one table: a tab with no product
 * is shown wherever its display rules allow, a tab with one belongs to that
 * product and is reached from its form. Creating from a product's card carries
 * `?product=` through, which is the only difference between the two paths.
 */
class ProductTabsController extends Controller
{
    /**
     * ONE list in the order the product page reads: the page's own two
     * sections (whether or not a row names them yet — "you may write a heading
     * for this one" is only discoverable if they are on the screen), the shop's
     * own tabs, and what modules add (`catalog.product.tabs.admin`, read-only
     * here: a module's section is managed where the module is). Tabs written
     * for one product are a second, separate list — they are not part of the
     * shop-wide order, and they are edited on that product.
     */
    public function index(Request $request)
    {
        $productId = (int) $request->query('product', 0);
        $q = trim((string) $request->query('q', ''));
        $locale = admin_content_locale();
        $matches = fn (?array $words) => $q === '' || str_contains(mb_strtolower(json_encode($words, JSON_UNESCAPED_UNICODE)), mb_strtolower($q));

        $tabs = ProductTab::with('product')->orderBy('sort')->orderBy('id')->get();

        // Every named section — the page's own and the modules' — is one kind
        // of row here: a row with its key renames, moves or hides it.
        $rows = collect(ProductSections::known())
            ->map(function (array $known, string $section) use ($tabs) {
                $row = $tabs->first(fn (ProductTab $t) => $t->section === $section && $t->product_id === null);

                return ['kind' => $known['module'] ? 'module' : 'built_in', 'section' => $section, 'known' => $known, 'tab' => $row, 'sort' => $row?->sort ?? $known['sort']];
            })
            ->values()
            ->concat($tabs->filter(fn (ProductTab $t) => $t->product_id === null && $t->section === null)
                ->map(fn (ProductTab $t) => ['kind' => 'own', 'tab' => $t, 'sort' => $t->sort]))
            ->filter(function (array $r) use ($matches) {
                return $r['kind'] === 'own'
                    ? $matches($r['tab']->title) || $matches($r['tab']->heading)
                    : $matches($r['tab']?->title) || $matches(['x' => $r['known']['label']]);
            })
            ->sortBy('sort')
            ->values();

        $perProduct = $tabs
            ->filter(fn (ProductTab $t) => $t->product_id !== null)
            ->when($productId, fn ($c) => $c->where('product_id', $productId))
            ->filter(fn (ProductTab $t) => $matches($t->title) || $matches(['x' => $t->product?->t('name', $locale).' '.$t->product?->sku]))
            ->values();

        return view('torgash.catalog::admin.product-tabs.index', [
            'rows' => $productId ? collect() : $rows,
            'perProduct' => $perProduct,
            'product' => $productId ? Product::find($productId) : null,
            'filters' => ['q' => $q],
            'asSections' => app(ProductSections::class)->asSections(),
        ]);
    }

    /**
     * The same switch for a named section no row speaks for yet (the page's
     * own or a module's): the first click MAKES that row — usual name,
     * usual position — because a row is how a section is switched off.
     */
    public function setSectionStatus(Request $request, string $section)
    {
        $known = ProductSections::known();
        abort_unless(isset($known[$section]), 404);
        $request->validate(['enabled' => 'required|boolean']);

        $tab = ProductTab::firstOrCreate(
            ['section' => $section, 'product_id' => null],
            ['title' => [], 'sort' => $known[$section]['sort'], 'rules_match' => DisplayRules::MATCH_ALL, 'enabled' => true],
        );
        $tab->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.catalog::admin.product-tabs._status', ['tab' => $tab, 'cellId' => $this->cellId($request, $tab)]);
    }

    /** The list's in-place switch: answers with the status cell. */
    public function setStatus(Request $request, ProductTab $tab)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $tab->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.catalog::admin.product-tabs._status', ['tab' => $tab, 'cellId' => $this->cellId($request, $tab)]);
    }

    /**
     * The cell answers under the id it was asked from: a section's row made by
     * its first click keeps the section's cell id, so the next click still
     * finds it. Only an id this screen could have written gets through.
     */
    protected function cellId(Request $request, ProductTab $tab): string
    {
        $asked = (string) $request->input('cell', '');

        return preg_match('/^product-(tab|section)-status-[a-z0-9_-]+$/', $asked) ? $asked : 'product-tab-status-'.$tab->id;
    }

    public function create(Request $request)
    {
        $productId = (int) $request->query('product', 0);
        $section = (string) $request->query('section', '');
        $known = ProductSections::known();
        $section = array_key_exists($section, $known) ? $section : null;

        // One row per section: asking to name a section that already has a row
        // opens that row rather than making a second answer to one question.
        if ($section) {
            $existing = ProductTab::where('section', $section)
                ->where('product_id', $productId ?: null)
                ->first();

            if ($existing) {
                return redirect()->route('admin.catalog.product-tabs.edit', $existing);
            }
        }

        return $this->form(new ProductTab([
            'enabled' => true,
            // A row naming a built-in section starts where that section already
            // stands, so writing a heading for it does not move it.
            'sort' => $section ? $known[$section]['sort'] : 50,
            'rules_match' => DisplayRules::MATCH_ALL,
            'product_id' => Product::whereKey($productId)->value('id'),
            'section' => $section,
        ]));
    }

    public function edit(ProductTab $tab)
    {
        return $this->form($tab);
    }

    public function store(Request $request)
    {
        $tab = ProductTab::create($this->data($request));

        return $this->afterSave($request, $tab, __('Tab created.'));
    }

    public function update(Request $request, ProductTab $tab)
    {
        $tab->update($this->data($request, $tab));

        return $this->afterSave($request, $tab, __('Tab saved.'));
    }

    public function destroy(ProductTab $tab)
    {
        $product = $tab->product;
        $tab->delete();

        // Back to wherever the tab was being edited from: a product's own tab
        // was reached from its form, and dumping the manager on a shop-wide
        // list is a page of rows they were not looking at.
        return $product
            ? redirect()->route('admin.catalog.products.edit', $product)->with('status', __('Tab deleted.'))
            : redirect()->route('admin.catalog.product-tabs.index')->with('status', __('Tab deleted.'));
    }

    protected function form(ProductTab $tab)
    {
        // Nothing but the row: the rule editor builds itself from core's
        // registry, so a module that adds a kind changes neither this
        // controller nor the view.
        return view('torgash.catalog::admin.product-tabs.form', [
            'tab' => $tab,
            'known' => $tab->section ? (ProductSections::known()[$tab->section] ?? null) : null,
        ]);
    }

    /** Back to the form; "Save and close" returns to wherever the tab is listed. */
    protected function afterSave(Request $request, ProductTab $tab, string $message)
    {
        if ($request->input('after_save') === 'close') {
            return ($tab->product
                ? redirect()->route('admin.catalog.products.edit', $tab->product)
                : redirect()->route('admin.catalog.product-tabs.index'))->with('status', $message);
        }

        return redirect()->route('admin.catalog.product-tabs.edit', $tab)->with('status', $message);
    }

    /** @return array<string, mixed> */
    protected function data(Request $request, ?ProductTab $tab = null): array
    {
        $locale = admin_content_locale();
        $isBaseLocale = $locale === content_base_locale();

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:190'],
            'heading' => 'nullable|string|max:250',
            'content' => 'nullable|string',
            'product_id' => ['nullable', Rule::exists('m_catalog_products', 'id')],
            'sort' => 'nullable|integer|min:0|max:999',
            'rules_match' => ['nullable', Rule::in([DisplayRules::MATCH_ALL, DisplayRules::MATCH_ANY])],
            // Which named section this row speaks for, if any: the page's own
            // two or one a module declared. A name nobody declares would be a
            // row that renames nothing and is drawn nowhere.
            'section' => ['nullable', Rule::in(array_keys(ProductSections::known()))],
        ]);

        // A written section needs a word in the strip; one that only renames a
        // built-in may leave it, and keeps the engine's own word.
        $section = $tab?->section ?? ($validated['section'] ?? null);
        if ($isBaseLocale && ! $section && trim($validated['title'] ?? '') === '') {
            throw ValidationException::withMessages(['title' => __('A tab needs a title.')]);
        }

        // A section whose names are the module's own (each kit is called what
        // the shop called it) takes no title: one would be saved and ignored.
        $renamable = ! $section || (ProductSections::known()[$section]['renamable'] ?? true);

        return [
            // `title` is NOT NULL: a section kept under its usual name stores
            // no words at all, which is an empty map, not a missing one.
            'title' => ($renamable ? $this->merged($tab?->title, $validated['title'] ?? '', $locale) : null) ?? [],
            'heading' => $this->merged($tab?->heading, $validated['heading'] ?? '', $locale),
            'section' => $section,
            // A row that names a built-in section has no body of its own: the
            // page makes that section's content.
            'content' => $section ? null : $this->merged($tab?->content, $validated['content'] ?? '', $locale),
            'product_id' => $validated['product_id'] ?? null,
            'sort' => (int) ($validated['sort'] ?? 50),
            'enabled' => $request->boolean('enabled'),
            // Normalised by the engine, not here: what a storable rule IS can
            // only have one author, and a half-filled row must not become a
            // rule that fails closed for ever.
            'rules' => app(DisplayRules::class)->normalise($request->input('rules')),
            'rules_match' => $validated['rules_match'] ?? DisplayRules::MATCH_ALL,
        ];
    }

    /** One language written into a translatable column, the key removed when blank. */
    protected function merged(?array $existing, string $incoming, string $locale): ?array
    {
        $values = $existing ?? [];

        if (trim($incoming) === '') {
            unset($values[$locale]);
        } else {
            $values[$locale] = $incoming;
        }

        return $values === [] ? null : $values;
    }
}
