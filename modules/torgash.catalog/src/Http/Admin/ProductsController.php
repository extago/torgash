<?php

namespace Torgash\Catalog\Http\Admin;

use App\Core\Seo\MetaFields;
use App\Core\Seo\SharePicture;
use App\Core\Support\Currencies;
use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Throwable;
use Torgash\Catalog\Features\OwnRows;
use Torgash\Catalog\Features\ProductFeatureSet;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\PriceType;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductPriceTypeOverride;
use Torgash\Catalog\Models\ProductTab;
use Torgash\Catalog\Pricing\Prices;
use Torgash\Catalog\Stock\AvailabilityStatuses;
use Torgash\Catalog\Support\BulkEdit;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Catalog\Support\ShortDescription;
use Torgash\Media\Gallery;
use Torgash\Media\ImageProcessor;

class ProductsController extends Controller
{
    public function __construct(
        protected ImageProcessor $images,
    ) {}

    /** Columns the list may be sorted by — allow-listed so the param can't reach arbitrary SQL. */
    public const SORTABLE = ['sku', 'price', 'stock', 'status', 'created_at'];

    /** The filters that narrow WHICH products the list holds — what "all found" re-applies. */
    public const NARROWING = ['q', 'status', 'category', 'availability', 'stock', 'price_min', 'price_max'];

    /** How many rows a page may show — the admin's choice at the list's toolbar. */
    public const PER_PAGE = [20, 50, 100, 200];

    public function index(Request $request)
    {
        $filters = $this->filtersFrom($request);
        $tree = app(CategoryTree::class);
        $locale = admin_content_locale();

        return view('torgash.catalog::admin.products.index', [
            'products' => $this->filtered($filters)->paginate($filters['per'])->withQueryString(),
            'trashedCount' => Product::onlyTrashed()->count(),
            'filters' => $filters,
            'statusCounts' => Product::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'categoryOptions' => $tree->options(),
            'availabilityOptions' => app(AvailabilityStatuses::class)->options(),
            // For the bulk dialog's pickers: the path, so two "Accessories"
            // under different parents can be told apart.
            'categoryPicker' => $tree->ordered()->map(fn ($c) => [
                'id' => $c->id,
                'label' => collect(array_reverse($tree->ancestors($c->id, true)))
                    ->map(fn ($id) => $tree->find($id)?->t('name', $locale))->filter()->implode(' / '),
            ])->sortBy('label')->values(),
            'labelPicker' => Label::orderBy('sort')->get()->map(fn ($l) => [
                'id' => $l->id, 'label' => $l->plainName($locale), 'color' => $l->color,
            ])->values(),
            'shopCurrency' => shop_currency(),
        ]);
    }

    /**
     * What the list is narrowed to, read from a request — ONE reading, used by
     * the list itself and by a bulk action over "everything found", so the
     * two can never disagree about which products those are.
     */
    protected function filtersFrom(Request $request): array
    {
        $price = fn (string $key) => is_numeric($request->input($key)) ? (float) $request->input($key) : null;

        return [
            'q' => trim((string) $request->input('q', '')),
            'status' => in_array($request->input('status'), Product::STATUSES, true) ? $request->input('status') : null,
            'category' => (int) $request->input('category') ?: null,
            'availability' => array_key_exists((string) $request->input('availability'), app(AvailabilityStatuses::class)->all())
                ? (string) $request->input('availability') : null,
            'stock' => in_array($request->input('stock'), ['in', 'out'], true) ? $request->input('stock') : null,
            'price_min' => $price('price_min'),
            'price_max' => $price('price_max'),
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'created_at',
            'dir' => $request->input('dir') === 'asc' ? 'asc' : 'desc',
            'per' => in_array((int) $request->input('per'), self::PER_PAGE, true) ? (int) $request->input('per') : self::PER_PAGE[0],
        ];
    }

    /**
     * Admin-side search, deliberately NOT the storefront's `search_vector`
     * tsquery: that index is built for shopper phrases, and an admin looking
     * for "ST-20" wants a prefix match on the SKU, which full-text tokenising
     * would never return. Plain ILIKE across sku/slug/name covers what this
     * screen is for.
     */
    protected function filtered(array $filters)
    {
        // with('media'): every row renders a 120px thumbnail, which lazy-loads
        // the media relation once per row otherwise (20 extra queries a page).
        return $this->narrowed($filters)
            ->with('media')
            ->orderBy($filters['sort'], $filters['dir']);
    }

    /**
     * The WHERE half of the list, with no order and nothing eager-loaded —
     * what a bulk action walks with chunkById(). A category means its
     * subcategories too, as everywhere else in the catalogue; prices are the
     * shop's books, in major units, as the price column reads on this screen.
     */
    protected function narrowed(array $filters)
    {
        return Product::query()
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';
                $query->where(fn ($q) => $q
                    ->where('sku', 'ilike', $like)
                    ->orWhere('slug', 'ilike', $like)
                    ->orWhereRaw('name::text ilike ?', [$like]));
            })
            ->when(in_array($filters['status'], Product::STATUSES, true),
                fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->whereHas('categories',
                fn ($c) => $c->whereIn('m_catalog_categories.id', app(CategoryTree::class)->descendants($category))))
            ->when($filters['availability'] ?? null, fn ($query, $key) => $query->where('availability_status', $key))
            ->when(($filters['stock'] ?? null) === 'in', fn ($query) => $query->where('stock', '>', 0))
            ->when(($filters['stock'] ?? null) === 'out', fn ($query) => $query->where('stock', '<=', 0))
            ->when(($filters['price_min'] ?? null) !== null, fn ($query) => $query->where('price', '>=', (int) round($filters['price_min'] * 100)))
            ->when(($filters['price_max'] ?? null) !== null, fn ($query) => $query->where('price', '<=', (int) round($filters['price_max'] * 100)));
    }

    /**
     * Inline edit from the list screen — price/stock/sku only, the three fields
     * worth changing without opening the full form. Returns the re-rendered
     * row so HTMX swaps it in place; everything else about the product is
     * untouched, so this never becomes a second, diverging save path.
     */
    public function quickUpdate(Request $request, Product $product)
    {
        // `stock` is "sometimes", not "required": a product whose stock is split
        // across warehouses renders that cell read-only, so the row genuinely
        // has no stock input to send. Requiring it made every such row fail
        // validation with a 422 — which HTMX drops on the floor, so the save
        // just silently did nothing.
        $validator = Validator::make($request->all(), [
            'sku' => ['required', 'string', 'max:190', $this->uniqueRule('sku', $product)],
            'price' => 'required|numeric|min:0',
            'stock' => 'sometimes|integer',
        ]);

        // Re-rendered row + message instead of a bare 422, for the same reason:
        // a 4xx is invisible to the default HTMX swap, and "nothing happened"
        // is the worst possible feedback for a failed save.
        if ($validator->fails()) {
            return $this->rowResponse($product, $validator->errors()->first());
        }

        $validated = $validator->validated();

        // Typed in the product's OWN currency — the list screen shows no
        // currency picker, so the only honest reading of a number typed there
        // is "the same currency as last time". Converted (or not) by the one
        // class that owns that arithmetic.
        $data = ['sku' => $validated['sku']] + app(Prices::class)->columns(
            ['price' => $validated['price']],
            $product->price_currency,
        );

        // Same guard as the full form: a split product's stock is an aggregate
        // owned by the warehouses module, never writable from here.
        if (isset($validated['stock'])
            && ! hook_filter('catalog.product.stock_locked', false, ['product' => $product])) {
            $data['stock'] = (int) $validated['stock'];
        }

        $product->update($data);

        return $this->rowResponse($product);
    }

    public function setStatus(Request $request, Product $product)
    {
        $request->validate(['status' => ['required', Rule::in(Product::STATUSES)]]);
        $product->update(['status' => $request->input('status')]);

        return $this->rowResponse($product);
    }

    /**
     * Bulk action over checked rows. Delete is a soft delete (same trash the
     * per-row button uses), so a mis-click is recoverable rather than final.
     */
    /** More copies than this in one click is a mistake, not a catalogue. */
    public const MAX_DUPLICATES = 50;

    /**
     * Bulk action over the ticked rows — or over EVERYTHING the list's filters
     * found (`scope=found`, re-read from the same filters the list used, so
     * "all 243 found" means the 243 on screen and not whatever a page held).
     * Delete is a soft delete (the same trash the per-row button uses), and
     * every change goes through the models — see BulkEdit.
     */
    public function bulk(Request $request)
    {
        $availability = array_keys(app(AvailabilityStatuses::class)->all());

        $validated = $request->validate([
            'action' => ['required', Rule::in([...Product::STATUSES, 'trash', 'duplicate', 'edit'])],
            'scope' => ['nullable', Rule::in(['ids', 'found'])],
            'ids' => 'required_unless:scope,found|array',
            'ids.*' => 'integer',
            'price_mode' => ['nullable', Rule::in(BulkEdit::MODES)],
            'price_value' => 'required_with:price_mode|nullable|numeric',
            'list_price_mode' => ['nullable', Rule::in([...BulkEdit::MODES, 'clear'])],
            'list_price_value' => 'required_if:list_price_mode,set,add,percent|nullable|numeric',
            'round' => ['nullable', Rule::in(BulkEdit::ROUNDING)],
            'stock_mode' => ['nullable', Rule::in(['set', 'add'])],
            'stock_value' => 'required_with:stock_mode|nullable|integer',
            'availability_status' => ['nullable', Rule::in($availability)],
            'categories_add' => 'array', 'categories_add.*' => 'integer',
            'categories_remove' => 'array', 'categories_remove.*' => 'integer',
            'labels_add' => 'array', 'labels_add.*' => 'integer',
            'labels_remove' => 'array', 'labels_remove.*' => 'integer',
        ]);

        $query = ($validated['scope'] ?? 'ids') === 'found'
            ? $this->narrowed($this->filtersFrom($request))
            : Product::whereIn('id', $validated['ids'] ?? []);
        $total = (clone $query)->count();
        $action = $validated['action'];

        if ($total === 0) {
            return back()->withErrors(['action' => __('No products to change.')]);
        }

        if ($action === 'duplicate') {
            if ($total > self::MAX_DUPLICATES) {
                return back()->withErrors(['action' => __('At most :n products can be duplicated at once.', ['n' => self::MAX_DUPLICATES])]);
            }
            $query->get()->each(fn (Product $product) => $this->copyOf($product));

            return redirect()->back()->with('status', trans_choice(':count product duplicated; the copies are disabled.|:count products duplicated; the copies are disabled.', $total, ['count' => $total]));
        }

        if ($action === 'trash') {
            $query->chunkById(200, fn ($products) => $products->each->delete());

            return redirect()->back()->with('status', __(':n products moved to trash.', ['n' => $total]));
        }

        $changes = in_array($action, Product::STATUSES, true) ? ['status' => $action] : $this->changes($validated);
        if (array_filter($changes) === []) {
            return back()->withErrors(['action' => __('Nothing to change: fill in at least one field.')]);
        }

        $edit = app(BulkEdit::class);
        $query->chunkById(200, fn ($products) => $edit->apply($products, $changes));
        $result = $edit->result();

        $message = trans_choice(':count product changed.|:count products changed.', $result['changed'], ['count' => $result['changed']]);
        $notes = [
            'negative_price' => ':count left as it was: the price would have gone below zero.|:count left as they were: the price would have gone below zero.',
            'stock_locked' => ':count kept its stock: it is counted by warehouses.|:count kept their stock: it is counted by warehouses.',
        ];
        foreach ($result['skipped'] as $reason => $count) {
            $message .= ' '.trans_choice($notes[$reason], $count, ['count' => $count]);
        }

        return redirect()->back()->with('status', $message);
    }

    /** The edit dialog's fields, read into BulkEdit's shape; an empty mode means "leave it". */
    protected function changes(array $validated): array
    {
        $number = fn (string $field) => empty($validated[$field.'_mode']) ? null : [
            'mode' => $validated[$field.'_mode'],
            'value' => $validated[$field.'_value'] ?? null,
        ];

        return [
            'price' => $number('price'),
            'list_price' => $number('list_price'),
            'round' => (int) ($validated['round'] ?? 0),
            'stock' => $number('stock'),
            'availability_status' => $validated['availability_status'] ?? null,
            'categories_add' => $validated['categories_add'] ?? [],
            'categories_remove' => $validated['categories_remove'] ?? [],
            'labels_add' => $validated['labels_add'] ?? [],
            'labels_remove' => $validated['labels_remove'] ?? [],
        ];
    }

    /** One row, rendered standalone for an HTMX swap — see the list's _row partial. */
    protected function rowResponse(Product $product, ?string $error = null)
    {
        return view('torgash.catalog::admin.products._row', ['product' => $product, 'rowError' => $error]);
    }

    public function create()
    {
        return view('torgash.catalog::admin.products.form', $this->formData(new Product));
    }

    public function store(Request $request)
    {
        $product = Product::create($this->data($request));
        $this->syncRelations($request, $product);
        $rejected = $this->attachUploads($request, $product);

        return $this->afterSave($request, $product, __('Product created.'), $rejected);
    }

    /** The related-products picker's answer to what the admin types; never the product itself. */
    public function lookup(Request $request, \Torgash\Catalog\Admin\ProductLookup $lookup)
    {
        return response()->json($lookup->search((string) $request->query('q', ''), array_filter([(int) $request->query('except')])));
    }

    /**
     * Characteristics found by name for the form's "add a characteristic"
     * box (PLAN §63.9), the ones already on the form left out.
     */
    public function featureSearch(Request $request, ProductFeatureSet $set)
    {
        $needle = trim((string) $request->query('feature_q', (string) $request->query('q', '')));

        return view('torgash.catalog::admin.products._feature_search', [
            'features' => $set->search($needle, (array) $request->query('on_form', [])),
            'needle' => $needle,
            'productId' => (int) $request->query('product') ?: null,
        ]);
    }

    /** One characteristic as a field of the form, to join it for this edit. */
    public function featureField(Request $request)
    {
        $feature = Feature::with(['group', 'values'])->findOrFail((int) $request->query('feature'));
        $product = $request->filled('product') ? Product::find((int) $request->query('product')) : null;

        return view('torgash.catalog::admin.products._feature_field', [
            'featureField' => $feature,
            'featureAssigned' => $product?->featureAssignments()->where('feature_id', $feature->id)->first(),
            'featureLocale' => admin_content_locale(),
        ]);
    }

    public function edit(Product $product)
    {
        return view('torgash.catalog::admin.products.form', $this->formData($product));
    }

    public function update(Request $request, Product $product)
    {
        // A changed address leaves its redirect from the model (KeepsAddress).
        $product->update($this->data($request, $product));
        $this->syncRelations($request, $product);
        $rejected = $this->attachUploads($request, $product);
        Gallery::applyFormInput($product, $request->input('media', []));

        return $this->afterSave($request, $product, __('Product updated.'), $rejected);
    }

    /**
     * Saving keeps you on the product you were editing — bouncing back to the
     * list after every save was the single most annoying thing about this
     * screen. "Save and close" is the explicit opt-out.
     *
     * @param  list<string>  $rejected  photos that failed their own checks; the
     *                                  product itself is already saved, so these
     *                                  are reported rather than thrown.
     */
    protected function afterSave(Request $request, Product $product, string $message, array $rejected = [])
    {
        // The single funnel both store() and update() come through, so a module
        // that added its own field to the form (see catalog.product.pricing_extra)
        // gets exactly one chance to save it, whichever path was taken.
        hook_action('catalog.product.saved', ['product' => $product, 'request' => $request]);

        $redirect = $request->input('after_save') === 'close'
            ? redirect()->route('admin.catalog.products.index')
            : redirect()->route('admin.catalog.products.edit', $product);

        return $rejected
            ? $redirect->with('status', $message)->withErrors(['images' => $rejected])
            : $redirect->with('status', $message);
    }

    /** Copies the record, its relations and its photos into a fresh draft. */
    public function duplicate(Product $product)
    {
        $copy = $this->copyOf($product);

        return redirect()->route('admin.catalog.products.edit', $copy)
            ->with('status', __('Product duplicated — this is the new copy, still disabled.'));
    }

    /** The copy itself — one per click on a row, or one per ticked row in bulk. */
    protected function copyOf(Product $product): Product
    {
        $copy = $product->replicate([...Product::GENERATED_COLUMNS, ...Product::DERIVED_COLUMNS, 'created_at', 'updated_at', 'deleted_at']);
        $copy->sku = $this->uniqueSku($product->sku);
        $copy->slug = $this->uniqueSlug($product->slug);
        $copy->name = $this->suffixTranslations($product->name, ' ('.__('copy').')');
        $copy->status = Product::STATUS_DISABLED;
        $copy->share_image = SharePicture::copy($product->share_image);
        $copy->save();

        $copy->categories()->sync($product->categories->pluck('id'));
        $copy->labels()->sync($product->labels->pluck('id'));
        $copy->relatedProducts()->sync(
            $product->relatedProducts->mapWithKeys(fn ($p) => [$p->id => ['sort' => $p->pivot->sort]])->all(),
        );

        foreach ($product->featureAssignments as $assignment) {
            $copy->featureAssignments()->create($assignment->only(['feature_id', 'feature_value_id', 'value_text', 'value_translations']));
        }

        foreach ($product->getMedia('gallery') as $media) {
            $media->copy($copy, 'gallery');
        }

        // Its own tabs come along: they are content about THIS product, and a
        // copy made to be edited into the next model wants them as a starting
        // point exactly as it wants the description.
        foreach (ProductTab::where('product_id', $product->id)->get() as $tab) {
            $copy->tabs()->create($tab->only(['title', 'content', 'sort', 'enabled', 'rules', 'rules_match']));
        }

        return $copy;
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->route('admin.catalog.products.index')
            ->with('status', __('Product moved to trash.'));
    }

    public function trash()
    {
        return view('torgash.catalog::admin.products.trash', [
            'products' => Product::onlyTrashed()->orderByDesc('deleted_at')->paginate(20),
        ]);
    }

    public function restore(int $id)
    {
        $product = Product::onlyTrashed()->findOrFail($id);
        $product->restore();

        return redirect()->route('admin.catalog.products.edit', $product)
            ->with('status', __('Product restored.'));
    }

    /** Permanent — media rows and files go with it, so it is trash-only. */
    public function forceDelete(int $id)
    {
        Product::onlyTrashed()->findOrFail($id)->forceDelete();

        return redirect()->route('admin.catalog.products.trash')
            ->with('status', __('Product deleted permanently.'));
    }

    public function emptyTrash()
    {
        Product::onlyTrashed()->get()->each->forceDelete();

        return redirect()->route('admin.catalog.products.trash')->with('status', __('Trash emptied.'));
    }

    protected function formData(Product $product): array
    {
        $priceTypes = PriceType::where('is_base', false)->orderBy('sort')->get();

        return [
            'product' => $product,
            'categories' => app(CategoryTree::class)->ordered(),
            // The product's own set (Features\ProductFeatureSet): what its
            // categories declare, what it already has, never every one.
            'globalFeatures' => app(ProductFeatureSet::class)->for(
                $product,
                old('categories', $product->exists ? $product->categories()->pluck('m_catalog_categories.id')->all() : []),
            ),
            'assignedFeatures' => $product->exists ? $product->featureAssignments->keyBy('feature_id') : collect(),
            // Own rows the page prints a second time beside the shop's
            // characteristic of the same name (Features\OwnRows).
            'printedTwice' => $product->exists
                ? app(OwnRows::class)->printedTwice($product->loadMissing('featureAssignments.feature', 'featureAssignments.featureValue'))
                : [],
            'labels' => Label::orderBy('sort')->get(),
            'customerGroups' => CustomerGroup::orderBy('id')->get(),
            'priceTiers' => $product->exists ? $product->priceTiers()->orderBy('min_qty')->get() : collect(),
            'priceTypes' => $priceTypes,
            // Keyed by price type, the number as TYPED (product's currency) —
            // the form edits that, not the derived books amount.
            'priceOverrides' => $product->exists
                ? $product->priceTypeOverrides->mapWithKeys(fn ($o) => [$o->price_type_id => $o->typedPrice()])
                : collect(),
            'priceGroupPreview' => $product->exists
                ? $priceTypes->mapWithKeys(fn (PriceType $t) => [$t->id => $product->priceForType($t)])
                : collect(),
            // The pricing card's currency picker, and the note under it. Off by
            // default: a catalogue priced entirely in the shop's own currency
            // has no use for either.
            'currencies' => app(Currencies::class)->all(),
            'pricesInOtherCurrencies' => (bool) setting('torgash.catalog', 'prices_in_other_currencies'),
            // What the shop would print with the box left empty — shown as the
            // field's placeholder, so "blank" reads as an answer rather than as
            // something forgotten.
            'composedShortDescription' => $product->exists
                ? app(ShortDescription::class)->composedFor($product)
                : '',
            // Its own tabs — the shop-wide ones live on their own screen and are
            // not listed here, because they are not this product's to edit.
            'productTabs' => $product->exists
                ? ProductTab::where('product_id', $product->id)->orderBy('sort')->orderBy('id')->get()
                : collect(),
            'prevProduct' => $product->exists
                ? Product::where('id', '<', $product->id)->orderByDesc('id')->first() : null,
            'nextProduct' => $product->exists
                ? Product::where('id', '>', $product->id)->orderBy('id')->first() : null,
        ];
    }

    protected function syncRelations(Request $request, Product $product): void
    {
        $product->categories()->sync($request->input('categories', []));
        $product->labels()->sync($request->input('labels', []));
        $this->syncGlobalFeatures($request, $product);
        $this->syncRelatedProducts($request, $product);
        $this->syncPriceTiers($request, $product);
        $this->syncPriceGroupOverrides($request, $product);
    }

    /**
     * One optional override per price type — a fixed, small, global list,
     * so a plain loop of named inputs (overrides[price_type_id]) is simpler
     * than the JSON-blob repeaters used where row count is arbitrary (Price
     * Tiers, Values). Blank clears the override and falls back to that
     * type's markup rule/adjustment_percent.
     */
    protected function syncPriceGroupOverrides(Request $request, Product $product): void
    {
        $submitted = $request->input('overrides', []);
        $prices = app(Prices::class);
        $currency = $product->price_currency;

        foreach (PriceType::where('is_base', false)->pluck('id') as $priceTypeId) {
            $raw = $submitted[$priceTypeId] ?? '';

            if (trim((string) $raw) === '') {
                ProductPriceTypeOverride::where('product_id', $product->id)->where('price_type_id', $priceTypeId)->delete();

                continue;
            }

            // Typed in the product's price currency, like the tiers above.
            ProductPriceTypeOverride::updateOrCreate(
                ['product_id' => $product->id, 'price_type_id' => $priceTypeId],
                $prices->row((float) $raw, $currency),
            );
        }
    }

    /**
     * Only the create screen posts files this way; on edit the gallery editor
     * uploads straight to the Media module.
     *
     * @return list<string> one message per photo that was refused — reported
     *                      back to the user, never silently swallowed
     */
    protected function attachUploads(Request $request, Product $product): array
    {
        $rejected = [];

        foreach ($request->file('images', []) as $file) {
            try {
                $this->images->addUpload($product, $file);
            } catch (Throwable $e) {
                $rejected[] = $file->getClientOriginalName().': '.$e->getMessage();
            }
        }

        return $rejected;
    }

    /** Sort follows submission order (the picker's selected order); excludes accidental self-reference. */
    protected function syncRelatedProducts(Request $request, Product $product): void
    {
        $ids = array_values(array_filter(
            array_map('intval', $request->input('related_products', [])),
            fn (int $id) => $id !== $product->id,
        ));

        $pivotData = [];
        foreach ($ids as $i => $id) {
            $pivotData[$id] = ['sort' => $i];
        }

        $product->relatedProducts()->sync($pivotData);
    }

    /**
     * One row per (product, feature): select-type features store feature_value_id,
     * text/number store value_text. Unassigned/blank features are removed rather
     * than left as empty rows.
     *
     * ONE language per save, as everywhere in the form: a picked value and a
     * number are the same in every language and save from any of them, but a
     * typed TEXT outside the base language is only that language's word for
     * the base value (`global_features_words`) — blank removes the word, never
     * the value, and the base word itself is the base language's alone.
     */
    protected function syncGlobalFeatures(Request $request, Product $product): void
    {
        $submitted = (array) $request->input('global_features', []);
        $words = content_locale_is_base() ? null : (array) $request->input('global_features_words', []);
        $locale = admin_content_locale();

        // Only what the form SENT (PLAN §63.9): the form shows the product's
        // own set, not the shop's thousand, so a characteristic it never
        // drew is not a characteristic the admin emptied.
        $sentIds = array_map('intval', array_keys($words !== null ? $words + $submitted : $submitted));
        $features = $sentIds === [] ? collect() : Feature::whereIn('id', $sentIds)->get();

        foreach ($features as $feature) {
            if ($words !== null && $feature->isTypedText()) {
                $assignment = $product->featureAssignments()->where('feature_id', $feature->id)->first();

                if ($assignment && array_key_exists($feature->id, $words)) {
                    $assignment->update([
                        'value_translations' => put_translation($assignment->value_translations, (string) $words[$feature->id], $locale) ?: null,
                    ]);
                }

                continue;
            }

            $raw = $submitted[$feature->id] ?? null;

            if ($raw === null || $raw === '') {
                $product->featureAssignments()->where('feature_id', $feature->id)->delete();

                continue;
            }

            $attrs = $feature->isChoiceType()
                ? ['feature_value_id' => (int) $raw, 'value_text' => null, 'value_translations' => null]
                : ['feature_value_id' => null, 'value_text' => (string) $raw];

            $product->featureAssignments()->updateOrCreate(['feature_id' => $feature->id], $attrs);
        }
    }

    /**
     * Wholesale/quantity-break rows, submitted as one JSON blob (same
     * pattern as `features` — see the form's Alpine repeater) rather than
     * indexed form fields, since rows have no stable id to key updateOrCreate
     * on. Other engines' admin saves do the same
     * delete-then-reinsert rather than diffing.
     */
    protected function syncPriceTiers(Request $request, Product $product): void
    {
        $rows = json_decode($request->input('price_tiers', '[]'), true) ?: [];
        $validGroupIds = CustomerGroup::pluck('id')->all();

        // A tier is negotiated in the product's own price currency — the same
        // one its cost, price and RRP are in — so it is converted and kept the
        // same way: books amount in `price`, the typed number in `price_original`.
        $prices = app(Prices::class);
        $currency = $product->price_currency;

        $tiers = collect($rows)
            ->filter(fn ($row) => isset($row['min_qty'], $row['price']) && $row['min_qty'] !== '' && $row['price'] !== '')
            ->map(fn ($row) => [
                'customer_group_id' => in_array((int) ($row['customer_group_id'] ?? 0), $validGroupIds, true)
                    ? (int) $row['customer_group_id'] : null,
                'min_qty' => max(1, (int) $row['min_qty']),
                ...$prices->row(max(0, (float) $row['price']), $currency),
            ]);

        $product->priceTiers()->delete();

        if ($tiers->isNotEmpty()) {
            // Same (product, group, min_qty) combination submitted twice — keep the cheapest.
            $tiers->groupBy(fn ($t) => $t['customer_group_id'].':'.$t['min_qty'])
                ->each(fn ($group) => $product->priceTiers()->create($group->sortBy('price')->first()));
        }
    }

    protected function data(Request $request, ?Product $product = null): array
    {
        $locale = admin_content_locale();
        $isBaseLocale = $locale === content_base_locale();

        $validated = $request->validate([
            // A NEW product may leave it blank: the model fills it in (a
            // module's template, else P-<id>). An existing one keeps one.
            'sku' => [$product?->exists ? 'required' : 'nullable', 'string', 'max:190', $this->uniqueRule('sku', $product)],
            'name' => [Rule::requiredIf($isBaseLocale), 'nullable', 'string'],
            'description' => 'nullable|string',
            'short_description' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'list_price' => 'nullable|numeric|min:0',
            // Which currency those three were typed in. Only honoured when the
            // shop turned the feature on: otherwise a stale form (or a crafted
            // request) could price a product in a currency the shop does not
            // mean to use, and the number would then move on its own.
            'price_currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'stock' => 'required|integer',
            'availability_status' => ['nullable', Rule::in(array_keys(app(AvailabilityStatuses::class)->all()))],
            'weight' => 'nullable|numeric|min:0',
            'needs_delivery' => 'nullable|boolean',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'features' => 'nullable|json',
            // The order of those features and the sections they sit in — see
            // Product::featureRows(). Posted by the same editor that posts
            // `features`, and cleaned up below rather than trusted.
            'features_layout' => 'nullable|json',
            // The same rows in the content language, when it is not the base
            // one: {k: {key: label}, v: {key: value}, s: {section: heading}}.
            'features_words' => 'nullable|json',
            'image_url' => 'nullable|string',
            'slug' => 'nullable|string',
            'variation_group' => 'nullable|string|max:190',
            'status' => ['required', Rule::in(Product::STATUSES)],
            'images.*' => 'image',
            // What the listings sort by. Editable on purpose — popularity
            // because the shop asked to be able to push a product up without
            // touching Views/Sales (the labels read those), the date because
            // "Newest first" sorts by it and an imported catalogue would
            // otherwise be entirely new.
            'popularity' => 'nullable|integer|min:0',
            'views_count' => 'nullable|integer|min:0',
            'sales_count' => 'nullable|integer|min:0',
            'created_at' => 'nullable|date',
        ] + MetaFields::rules());

        $name = $this->mergeTranslatable($product?->name, $validated['name'] ?? '', $locale);
        $description = $this->mergeTranslatable($product?->description, $validated['description'] ?? '', $locale);
        $shortDescription = $this->mergeTranslatable($product?->short_description, $validated['short_description'] ?? '', $locale);
        $baseLocale = content_base_locale();
        // ?? before ?:, because a "nullable" rule leaves the key ABSENT (not
        // null) when the request omits it entirely — the real form always posts
        // both fields, but a hand-built request need not.
        $slugSource = ($validated['slug'] ?? '') ?: ($name[$baseLocale] ?? (reset($name) ?: ($validated['sku'] ?? '')));

        $data = [
            'sku' => trim((string) ($validated['sku'] ?? '')),
            'name' => $name,
            'description' => $description,
            'short_description' => $shortDescription,
            'slug' => \Torgash\Catalog\Support\Slugs::make($slugSource),
            'stock' => (int) $validated['stock'],
            // Nullable in the request: a form built before this field existed
            // (a test, a script) leaves a product exactly as it always
            // behaved — gated purely by the stock number, which "in_stock" IS.
            'availability_status' => $validated['availability_status'] ?? $product?->availability_status ?? 'in_stock',
            // Whatever unit the shop types in, grams and millimetres out — the
            // same major/minor split as price. An empty field stays NULL: "not
            // measured" is an answer shipping acts on, unlike a zero.
            'weight' => units()->weightToStorage($validated['weight'] ?? null),
            // Absent from a form built before the field (a script, a test):
            // what the product said before, else "it is delivered".
            'needs_delivery' => $request->has('needs_delivery_form') ? $request->boolean('needs_delivery') : ($product?->needs_delivery ?? true),
            'length' => units()->lengthToStorage($validated['length'] ?? null),
            'width' => units()->lengthToStorage($validated['width'] ?? null),
            'height' => units()->lengthToStorage($validated['height'] ?? null),
            // A map, always. `nullable|json` also accepts a LIST, and the editor's
            // JSON tab can legitimately show one — it normalises before posting,
            // but a hand-built request need not, and a list would land in the
            // column as `{"0": …}` and quietly stop matching every lookup.
            // ONE language per save (the content language at the top): the
            // base one writes the rows themselves, any other only their words
            // in it — the structure is shared by every language.
            ...($request->has('features_words') && ! content_locale_is_base()
                ? ['features_translations' => $this->featureWords($product, (string) $validated['features_words'], $locale)]
                : [
                    'features' => $features = $this->featureMap($validated['features'] ?? null),
                    'features_layout' => $this->featuresLayout($validated['features_layout'] ?? null, (array) $features),
                ]),
            // Pictures by external link: kept as the list of addresses that
            // will actually be read (https or the shop's own path). A form
            // without the field — the setting is off and the field hidden —
            // leaves the stored links alone rather than erasing them.
            'image_url' => $request->has('image_url')
                ? (implode(', ', Product::parseImageUrls((string) ($validated['image_url'] ?? ''))) ?: null)
                : $product?->image_url,
            'variation_group' => ($validated['variation_group'] ?? '') ?: null,
            'status' => $validated['status'],
            // Who may see it (PLAN §46); absent from the form, kept as it was.
            'customer_groups' => (function () use ($request, $product) {
                if (! $request->boolean('customer_groups_set')) {
                    return $product?->customer_groups;
                }
                $known = array_keys(\Torgash\Catalog\Support\Assortment::choices());
                $picked = array_values(array_intersect((array) $request->input('customer_groups', []), $known));

                return $picked === [] ? null : $picked;
            })(),
            // What the product says about its own page — merged into the other
            // languages rather than overwriting them. See App\Core\Seo\MetaFields.
        ] + MetaFields::attributes($request, $product);

        // Absent stays absent: a form built before these fields existed (a
        // test, a script, the API) must leave the counters where they are
        // rather than resetting them to zero.
        foreach (['popularity', 'views_count', 'sales_count'] as $counter) {
            if (array_key_exists($counter, $validated) && $validated[$counter] !== null) {
                $data[$counter] = (int) $validated[$counter];
            }
        }

        // An emptied date field is not an instruction to forget when the
        // product was created: a new product falls back to now (which is what
        // Eloquent would have done), an existing one keeps what it has. A
        // dirty `created_at` survives the insert — Eloquent stamps it only
        // when the attribute is untouched.
        if (array_key_exists('created_at', $validated)) {
            $data['created_at'] = $validated['created_at']
                ? Carbon::parse($validated['created_at'])
                : ($product?->created_at ?? now());
        }

        // The three amounts, plus the currency they were typed in and the books
        // amounts derived from it. One class owns that arithmetic because the
        // list screen's inline edit and a rate change go through it too.
        $data += app(Prices::class)->columns(
            [
                'price' => $validated['price'],
                'cost_price' => $validated['cost_price'] ?? null,
                'list_price' => $validated['list_price'] ?? null,
            ],
            setting('torgash.catalog', 'prices_in_other_currencies')
                ? ($validated['price_currency'] ?? null)
                : ($product?->price_currency),
        );

        // A split product's Stock is a computed aggregate (see Product::adjustStock
        // and catalog.product.stock_locked) — the form shows it read-only, but a
        // crafted request could still post a value, so drop it here too rather
        // than trust the client. Editing happens per-warehouse instead.
        if ($product?->exists && hook_filter('catalog.product.stock_locked', false, ['product' => $product])) {
            unset($data['stock']);
        }

        // Numbers the SHOP moves while the page sits open — an order takes
        // stock, a visitor adds a view — are written only when the admin
        // actually changed them. The form says what it showed (`seen[…]`); a
        // value equal to that is the page repeating last week's number, and
        // writing it back would resurrect sold units and erase a week of
        // views. A changed value is an instruction and is written as typed.
        if ($product?->exists) {
            foreach (self::LIVE_NUMBERS as $live) {
                $seen = $request->input('seen.'.$live);

                if ($seen !== null && array_key_exists($live, $data) && (int) $data[$live] === (int) $seen) {
                    unset($data[$live]);
                }
            }
        }

        return $data;
    }

    /** What moves on its own between loading the form and saving it — see above. */
    public const LIVE_NUMBERS = ['stock', 'popularity', 'views_count', 'sales_count'];

    /**
     * Queries the table directly rather than the model, so trashed rows — which
     * still hold their sku/slug — are counted. Otherwise the save would pass
     * validation and then die on the database constraint.
     */
    protected function uniqueRule(string $column, ?Product $product): Unique
    {
        return Rule::unique('m_catalog_products', $column)->ignore($product?->id);
    }

    protected function uniqueSku(string $sku): string
    {
        $base = preg_replace('/-copy(-\d+)?$/', '', $sku).'-copy';

        return $this->firstFree($base, 'sku');
    }

    protected function uniqueSlug(string $slug): string
    {
        return $this->firstFree(preg_replace('/-copy(-\d+)?$/', '', $slug).'-copy', 'slug');
    }

    /** Appends -2, -3 … until the column is actually free, trashed rows included. */
    protected function firstFree(string $base, string $column): string
    {
        $candidate = $base;
        $suffix = 1;

        while (Product::withTrashed()->where($column, $candidate)->exists()) {
            $candidate = $base.'-'.(++$suffix);
        }

        return $candidate;
    }

    /** @param  array<string,string>|null  $values */
    protected function suffixTranslations(?array $values, string $suffix): array
    {
        return collect($values ?? [])->map(fn ($v) => $v.$suffix)->all();
    }

    /**
     * One language's words for the product's own characteristics, put beside
     * the others: a blank removes that word in this language (the base words
     * show again), every other language is kept — core's put_translation()
     * rule, for a map of rows rather than one string.
     *
     * @return array<string, array<string, array<string, string>>>|null
     */
    protected function featureWords(?Product $product, string $posted, string $locale): ?array
    {
        $all = (array) ($product?->features_translations ?? []);
        $typed = json_decode($posted, true);

        if (! is_array($typed)) {
            return $all ?: null;
        }

        $mine = [];
        foreach (['k', 'v', 's'] as $part) {
            foreach ((array) ($typed[$part] ?? []) as $base => $word) {
                $word = trim((string) $word);
                if ($word !== '' && is_string($base) && $base !== '') {
                    $mine[$part][$base] = mb_substr($word, 0, 500);
                }
            }
        }

        if ($mine === []) {
            unset($all[$locale]);
        } else {
            $all[$locale] = $mine;
        }

        return $all ?: null;
    }

    /**
     * The posted individual attributes as the column's own shape: key => value.
     *
     * A list of `{key, value}` rows — what the JSON tab shows once a product has
     * sections — is read as one; anything else that is not a map is nothing.
     *
     * @return array<string, mixed>
     */
    protected function featureMap(?string $posted): array
    {
        $decoded = json_decode(($posted ?? '') ?: '{}', true);

        if (! is_array($decoded)) {
            return [];
        }

        if (! array_is_list($decoded)) {
            return $decoded;
        }

        $map = [];

        foreach ($decoded as $row) {
            if (is_array($row) && ($row['type'] ?? 'feature') !== 'section' && ($row['key'] ?? '') !== '') {
                $map[(string) $row['key']] = $row['value'] ?? '';
            }
        }

        return $map;
    }

    /**
     * The posted row order, reduced to what it is allowed to say.
     *
     * Sections keep their label; feature rows keep nothing but a key, and only
     * one that the product actually has — a layout is a way to ORDER values,
     * never a second place they could be defined. Null when there is nothing
     * to remember, so an ordinary product carries no layout at all and renders
     * the way it always did.
     *
     * @param  array<string, mixed>  $features
     * @return list<array<string, string>>|null
     */
    protected function featuresLayout(?string $posted, array $features): ?array
    {
        $rows = json_decode((string) $posted, true);

        if (! is_array($rows)) {
            return null;
        }

        $layout = [];
        $seen = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (($row['type'] ?? '') === 'section') {
                $label = trim((string) ($row['label'] ?? ''));

                if ($label !== '') {
                    $layout[] = ['type' => 'section', 'label' => $label];
                }

                continue;
            }

            $key = (string) ($row['key'] ?? '');

            // Each key once: a duplicate row would print the same spec twice.
            if ($key !== '' && ! isset($seen[$key]) && array_key_exists($key, $features)) {
                $layout[] = ['type' => 'feature', 'key' => $key];
                $seen[$key] = true;
            }
        }

        return $layout ?: null;
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
