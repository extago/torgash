@extends('admin.layout')

@section('title', $product->exists ? __('Edit product') : __('New product'))
@section('own_heading', '1')

@section('content')
    @php
        $locale = admin_content_locale();

        // A warehouses module, if installed, answers this for a product whose
        // stock is split across warehouses — the plain Stock field becomes a
        // read-only aggregate then, edited per-warehouse instead. No listener
        // (or a new, unsaved product) means false: stock behaves as it always did.
        $stockLocked = $product->exists && hook_filter('catalog.product.stock_locked', false, ['product' => $product]);

        $tabFieldMap = [
            'general' => ['sku', 'slug', 'name', 'description', 'short_description', 'price', 'stock', 'availability_status', 'weight', 'length', 'width', 'height', 'image_url', 'images', 'images.*', 'categories', 'labels', 'status', 'popularity', 'views_count', 'sales_count', 'created_at'],
            'attributes' => ['features', 'global_features'],
            'variations' => ['variation_group'],
            'related' => ['related_products'],
            'seo' => ['meta_title', 'meta_description', 'h1', 'canonical_url', 'noindex'],
        ];
        $initialTab = 'general';
        foreach ($tabFieldMap as $tabKey => $fields) {
            if (collect($fields)->contains(fn ($f) => $errors->has($f))) {
                $initialTab = $tabKey;
                break;
            }
        }
        // A module's own tab of this form — the options and kits a product
        // carries, tomorrow something else. It returns a key, a label and
        // finished HTML, which is rendered INSIDE this form: whatever it draws
        // is submitted with everything else, and `catalog.product.saved` is
        // where the module reads it back. Asked once and used twice, for the
        // strip and for the panels at the bottom.
        //
        // A SMALL one — a switch and two fields, like "this is a gift card" —
        // says `'section' => true` and becomes a headed section of one shared
        // "Modules" tab instead: a whole tab per such switch grew the strip
        // past the width of the screen. A real editor (options, kits) keeps
        // its own tab.
        $moduleTabs = array_values(array_filter(
            hook_filter('catalog.product.form_tabs', [], ['product' => $product]),
            fn ($tab) => is_array($tab) && ($tab['key'] ?? '') !== '' && ($tab['html'] ?? '') !== '',
        ));
        $moduleSections = array_values(array_filter($moduleTabs, fn ($tab) => ! empty($tab['section'])));
        $moduleTabs = array_values(array_filter($moduleTabs, fn ($tab) => empty($tab['section'])));

        $tabs = [
            'general' => __('General'),
            'attributes' => __('Attributes'),
            'variations' => __('Variations'),
            'related' => __('Related products'),
        ];

        foreach ($moduleTabs as $moduleTab) {
            $tabs[$moduleTab['key']] = $moduleTab['label'] ?? $moduleTab['key'];
        }

        if ($moduleSections !== []) {
            $tabs['modules'] = __('Modules');
        }

        $tabs['seo'] = __('SEO');

        // Panels start hidden in the markup except the active one, so the page
        // never flashes every tab at once before Alpine takes over.
        $panelStyle = fn (string $key) => $key === $initialTab ? '' : 'display:none';

        // Each category as its path ("Parent / Child") in the tree's own
        // order, for the picker under the name. A disabled category is still
        // assignable on purpose — that is how a shop keeps a TECHNICAL grouping
        // for promotions and automation rules to point at, with no page, no
        // menu entry and no breadcrumb — it just says so.
        $categoryNames = $categories->mapWithKeys(fn ($c) => [$c->id => $c->t('name', $locale)]);
        $categoryById = $categories->keyBy('id');
        $selectedCategoryIds = array_map('intval', old('categories', $product->exists ? $product->categories->pluck('id')->all() : array_filter([(int) request()->query('category')])));
        $categoryTreeOptions = $categories->map(function ($c) use ($categoryById, $categoryNames) {
            $parts = [$categoryNames[$c->id] ?: $c->slug];
            $parentId = $c->parent_id;
            $guard = 0;
            while ($parentId && $guard++ < 20 && ($parent = $categoryById->get($parentId))) {
                array_unshift($parts, $categoryNames[$parent->id] ?: $parent->slug);
                $parentId = $parent->parent_id;
            }

            return ['id' => (int) $c->id, 'parts' => $parts, 'off' => $c->isListed() ? null : mb_strtolower($c->statusLabel())];
        })->values();

        $labelOptions = $labels->map(fn ($l) => [
            'id' => $l->id,
            // plainName(): the picker has no product to resolve [discount] against.
            'label' => $l->plainName($locale),
            'color' => $l->color,
        ])->values();
        $selectedLabelIds = array_map('intval', old('labels', $product->exists ? $product->labels->pluck('id')->all() : []));
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <form method="POST" enctype="multipart/form-data"
          action="{{ $product->exists ? route('admin.catalog.products.update', $product) : route('admin.catalog.products.store') }}"
          x-data="productForm('{{ $initialTab }}')" class="flex flex-col gap-4 w-full">
        @csrf
        @if ($product->exists) @method('PUT') @endif
        {{-- Enter in any field presses the FIRST submit button of the form; the
             actions menu (before Cancel) holds "Save and close", so without
             this one Enter would close the product. --}}
        <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true"></button>
        {{-- What this page showed of the numbers the shop moves by itself
             (stock, the counters): the server writes one only when it was
             changed here, so a form left open for a week does not put back
             last week's stock. --}}
        @if ($product->exists)
            @foreach (\Torgash\Catalog\Http\Admin\ProductsController::LIVE_NUMBERS as $liveNumber)
                <input type="hidden" name="seen[{{ $liveNumber }}]" value="{{ (int) $product->{$liveNumber} }}">
            @endforeach
        @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.catalog.products.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to products') }}" aria-label="{{ __('Back to products') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs uppercase tracking-wide opacity-50 leading-none">{{ $product->exists ? __('Edit product') : __('New product') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $product->exists ? $product->t('name', $locale) : __('New product') }}</div>
            </div>

            @if ($product->exists && (($prevProduct ?? null) || ($nextProduct ?? null)))
                <div class="join">
                    @if ($prevProduct ?? null)
                        <a href="{{ route('admin.catalog.products.edit', $prevProduct) }}" class="btn btn-sm join-item" title="{{ __('Previous product') }}" aria-label="{{ __('Previous product') }}">
                            <x-icon name="chevron-left" />
                        </a>
                    @else
                        <span class="btn btn-sm join-item btn-disabled"><x-icon name="chevron-left" /></span>
                    @endif
                    @if ($nextProduct ?? null)
                        <a href="{{ route('admin.catalog.products.edit', $nextProduct) }}" class="btn btn-sm join-item" title="{{ __('Next product') }}" aria-label="{{ __('Next product') }}">
                            <x-icon name="chevron-right" />
                        </a>
                    @else
                        <span class="btn btn-sm join-item btn-disabled"><x-icon name="chevron-right" /></span>
                    @endif
                </div>
            @endif

            @if ($product->exists && auth()->user()?->can('catalog.manage'))
                {{-- The menu's destructive actions submit the standalone forms below
                     via the HTML "form" attribute — a nested <form> here would leak
                     its _method into this product's own submit. --}}
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-56 mt-2 p-2 z-50 border border-base-300">
                        <li>
                            <button type="submit" name="after_save" value="close">
                                <x-icon name="check" /> {{ __('Save and close') }}
                            </button>
                        </li>
                        <li>
                            <a href="{{ route('catalog.product', $product->slug) }}" target="_blank" rel="noopener">
                                <x-icon name="external-link" /> {{ __('Preview') }}
                            </a>
                        </li>
                        <li>
                            <button type="submit" form="product-duplicate">
                                <x-icon name="copy" /> {{ __('Duplicate') }}
                            </button>
                        </li>
                        <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
                        <li>
                            <button type="submit" form="product-delete" class="text-error">
                                <x-icon name="trash-2" /> {{ __('Move to trash') }}
                            </button>
                        </li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ route('admin.catalog.products.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                @can('catalog.manage')
                    <button class="btn btn-primary btn-sm">{{ $product->exists ? __('Save') : __('Create') }}</button>
                @else
                    {{-- A reader (catalog.view): the card as it is, nothing to press. --}}
                    <span class="badge badge-ghost badge-sm">{{ __('Read only') }}</span>
                @endcan

            </x-slot:actions>
        </x-admin.form-bar>

        @if ($errors->any())
            <div class="alert alert-error text-sm flex-col items-start gap-1">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <div class="flex items-end gap-3 flex-wrap">
            <div role="tablist" class="tabs tabs-border flex-1" x-show="view === 'tabs'">
                @foreach ($tabs as $key => $label)
                    <a role="tab" class="tab" :class="tab === '{{ $key }}' ? 'tab-active font-semibold' : ''"
                       @click.prevent="tab = '{{ $key }}'" href="#">{{ $label }}</a>
                @endforeach
            </div>
            <div x-show="view === 'all'" x-cloak class="flex-1 text-sm opacity-50 pb-2">{{ __('All sections on one page') }}</div>

            <div class="join" title="{{ __('Switch between tabbed and single-page layout') }}">
                <button type="button" class="btn btn-sm join-item" :class="view === 'tabs' ? 'btn-active' : ''"
                        @click="setView('tabs')">
                    <x-icon name="columns-3" size="sm" /> {{ __('Tabs') }}
                </button>
                <button type="button" class="btn btn-sm join-item" :class="view === 'all' ? 'btn-active' : ''"
                        @click="setView('all')">
                    <x-icon name="layout-dashboard" size="sm" /> {{ __('All') }}
                </button>
            </div>
        </div>

        {{-- ── General ───────────────────────────────────────────────────
             Two columns on a wide screen: on the left what is WRITTEN and
             weighed (the words, the photos, the money, the stock), on the
             right a narrow column of what the product IS in the shop (its
             status, where it is filed, its address, its ranking) — the
             layout every serious admin has settled on, and the answer to a
             single card of thirty fields in a row. One column below xl, in
             the same order. --}}
        <div x-show="shows('general')" style="{{ $panelStyle('general') }}" class="flex flex-col gap-4">
            <h2 class="text-lg font-semibold pt-2" x-show="view === 'all'" x-cloak>{{ __('General') }}</h2>

            {{-- Two rows on the left — the words and photos, then the money,
                 stock and tabs — beside ONE right column spanning both. The
                 second row is 1fr so the tall right column is measured against
                 it alone: with two auto rows (or the second block outside the
                 grid, as it was) the first row stretched to the right column's
                 height and left a hole under the photos. --}}
            <div class="grid xl:grid-cols-[minmax(0,1fr)_22rem] xl:grid-rows-[auto_1fr] gap-4 items-start">
            <div class="flex flex-col gap-4 min-w-0 xl:col-start-1 xl:row-start-1">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Basics') }}</h3>
                        <x-admin.translatable-field name="name" label="{{ __('Name') }}" :value="$product->name ?? []" required placeholder="{{ __('Name') }}" />

                        @include('torgash.catalog::admin.products._categories', ['catOptions' => $categoryTreeOptions, 'catSelected' => $selectedCategoryIds])

                        {{-- :record is what lets the editor put IMAGES inside this text:
                             it is the row they would belong to. Passed unconditionally —
                             an unsaved product has no id, the media module answers null,
                             and the editor simply has no image button until the first
                             save, which is the same rule the Photos card follows. --}}
                        {{-- The one line under the name. Left blank on most of a
                             catalogue on purpose: with the characteristics ticked
                             for it, the shop composes one — see the Short
                             description settings. --}}
                        <x-admin.translatable-field name="short_description" label="{{ __('Short description') }}"
                            :value="$product->short_description ?? []" textarea :rows="2"
                            placeholder="{{ $composedShortDescription ?: __('Composed from the characteristics when left empty') }}" />

                        <x-admin.translatable-field name="description" label="{{ __('Description') }}" :value="$product->description ?? []" :record="$product" textarea rich :rows="6" placeholder="{{ __('Description') }}" />
                    </div>
                </section>

                {{-- Photos: second, where the eye goes after the name. --}}
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Photos') }}</h3>
                        @if ($product->exists)
                            @include('torgash.media::admin.gallery', ['owner' => 'product', 'model' => $product])
                        @else
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Add photos') }}</legend>
                                <input name="images[]" type="file" accept="image/*" multiple class="file-input file-input-bordered w-full">
                                <div class="text-xs opacity-50">{{ __('Captions, order, rotation and previews become available once the product is saved.') }}</div>
                            </fieldset>
                        @endif

                        {{-- Pictures by external link — behind a catalogue setting, off
                             by default, and said plainly why (Product::externalImageUrls()).
                             Still drawn while switched off if the product HAS links, so
                             switching the setting off never hides data it kept. --}}
                        @php $externalImagesOn = (bool) setting('torgash.catalog', 'external_images', false); @endphp
                        @if ($externalImagesOn || filled($product->image_url))
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Photos by external links') }}</legend>
                                <div role="alert" class="alert alert-warning text-sm items-start mb-2">
                                    <x-icon name="triangle-alert" class="shrink-0" />
                                    <div>
                                        <div class="font-medium">{{ __('Not recommended — upload the photos, or use "Add by URL" above, which downloads them.') }}</div>
                                        <ul class="list-disc ms-4 mt-1 opacity-80">
                                            <li>{{ __('Nothing is downloaded: no thumbnails, the card loads the original, however large.') }}</li>
                                            <li>{{ __('The picture vanishes when the other site deletes it or blocks linking — and nobody here notices.') }}</li>
                                            <li>{{ __('No caption, alt text, rotation or order — only while the product has no photos of its own.') }}</li>
                                            <li>{{ __('The customer\'s browser asks the other site for it.') }}</li>
                                        </ul>
                                        @unless ($externalImagesOn)
                                            <div class="mt-2 font-medium">{{ __('Switched off in the catalogue settings: these links are kept but not shown on the storefront.') }}</div>
                                        @endunless
                                    </div>
                                </div>
                                <textarea name="image_url" rows="2" class="textarea textarea-bordered w-full font-mono text-sm"
                                          placeholder="https://cdn.example.com/photo-1.jpg, https://cdn.example.com/photo-2.jpg">{{ old('image_url', $product->image_url) }}</textarea>
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('One or more addresses, separated by commas or new lines. Only https:// addresses are shown.') }}</p>
                            </fieldset>
                        @endif
                    </div>
                </section>

            </div>

            {{-- The narrow column: what the product IS in the shop. --}}
            <div class="flex flex-col gap-4 min-w-0 xl:col-start-2 xl:row-start-1 xl:row-span-2">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        @php $currentStatus = old('status', $product->status ?? \Torgash\Catalog\Models\Product::STATUS_ACTIVE); @endphp
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend sr-only">{{ __('Status') }}</legend>
                            <select name="status" aria-label="{{ __('Status') }}" class="select select-bordered w-full">
                                <option value="active" @selected($currentStatus === 'active')>{{ __('Active') }}</option>
                                <option value="hidden" @selected($currentStatus === 'hidden')>{{ __('Hidden') }}</option>
                                <option value="disabled" @selected($currentStatus === 'disabled')>{{ __('Disabled') }}</option>
                            </select>
                            <p class="label text-xs opacity-60 whitespace-normal">
                                {{ __('Hidden keeps the product off every listing and out of search, but its own URL still opens. Disabled makes it unreachable.') }}
                            </p>
                        </fieldset>

                        {{-- Who may see it (PLAN §46): nothing ticked is everybody.
                             The hidden marker tells "untick all" from a form that
                             never had the field. --}}
                        @php $shownTo = (array) old('customer_groups', $product->customer_groups ?? []); @endphp
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Who sees it') }}</legend>
                            <input type="hidden" name="customer_groups_set" value="1">
                            @foreach (\Torgash\Catalog\Support\Assortment::choices() as $groupToken => $groupLabel)
                                <label class="label cursor-pointer justify-start gap-2 text-sm">
                                    <input type="checkbox" name="customer_groups[]" value="{{ $groupToken }}" class="checkbox checkbox-sm" @checked(in_array($groupToken, $shownTo, true))>
                                    {{ $groupLabel }}
                                </label>
                            @endforeach
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Nothing ticked — everybody. Ticked — only them: others do not find it in the catalogue, search or the sitemap.') }}</p>
                        </fieldset>
                    </div>
                </section>

                {{-- The categories moved under the name; the labels stay here. --}}
                @if ($labels->isNotEmpty())
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-4">
                            <x-admin.picker
                                name="labels[]"
                                :options="$labelOptions"
                                :selected="$selectedLabelIds"
                                :label="__('Labels')"
                                :placeholder="__('Search labels…')"
                                :empty="__('No labels selected.')"
                                badge
                                :manage-url="route('admin.catalog.labels.index')" />
                        </div>
                    </section>
                @endif

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Address and SKU') }}</h3>
                        <div class="flex flex-col gap-4">
                            {{-- A new product may leave it blank: the model fills it in
                                 (a template of torgash.sku, else P-<id>). --}}
                            <label class="fieldset p-0"><span class="fieldset-legend">{{ __('SKU') }}{{ $product->exists ? ' *' : '' }}</span>
                                <input name="sku" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('sku', $product->sku) }}" @required($product->exists) class="input input-bordered w-full"
                                       @unless ($product->exists) placeholder="{{ __('Leave blank to assign one') }}" @endunless>
                            </label>
                            <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Slug') }}</span>
                                <input name="slug" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('slug', $product->slug) }}" class="input input-bordered w-full" placeholder="{{ __('auto from name') }}">
                            </label>
                        </div>
                    </div>
                </section>

                {{-- What the listing sorts by, and both of them editable.

                     Popularity is ONE number moved by two events whose
                     weights are catalogue settings (see Support\Popularity);
                     it is typed over here because the shop asked to be able
                     to push a product up without falsifying Views/Sales,
                     which the labels read. Sales counts UNITS and Popularity
                     counts how often the product was chosen — two different
                     questions, which is why both are here.

                     The creation date is editable for the same reason
                     other engines make it so: "Newest first" sorts by it, and a catalogue
                     imported today would otherwise claim every phone in it is
                     new. Whoever changes it is recorded in the audit — for a
                     product this field is a decision, not a timestamp. --}}
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Ranking') }}</h3>
                        <div class="grid grid-cols-3 gap-3 items-end">
                            <label class="flex flex-col gap-1">
                                <span class="text-xs opacity-60">{{ __('Popularity') }}</span>
                                <input type="number" name="popularity" min="0" step="1"
                                       value="{{ old('popularity', $product->popularity ?? 0) }}"
                                       class="input input-bordered w-full">
                            </label>
                            <label class="flex flex-col gap-1">
                                <span class="text-xs opacity-60">{{ __('Views') }}</span>
                                <input type="number" name="views_count" min="0" step="1"
                                       value="{{ old('views_count', $product->views_count ?? 0) }}"
                                       class="input input-bordered w-full">
                            </label>
                            <label class="flex flex-col gap-1">
                                <span class="text-xs opacity-60">{{ __('Sales (units)') }}</span>
                                <input type="number" name="sales_count" min="0" step="1"
                                       value="{{ old('sales_count', $product->sales_count ?? 0) }}"
                                       class="input input-bordered w-full">
                            </label>
                        </div>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs opacity-60">{{ __('Created') }}</span>
                            <input type="datetime-local" name="created_at"
                                   value="{{ old('created_at', ($product->created_at ?? now())->format('Y-m-d\TH:i')) }}"
                                   class="input input-bordered w-full">
                        </label>
                        <p class="text-xs opacity-60">
                            {{ __('Popularity grows by itself — how much a view and an order are worth is set in the catalogue settings. The creation date is what "Newest first" sorts by: set it to the release date when importing, or to today to put the product back at the front.') }}
                        </p>
                    </div>
                </section>
            </div>

            {{-- The left column's second row: the money, the stock and the
                 product's own tabs. --}}
            <div class="flex flex-col gap-4 min-w-0 xl:col-start-1 xl:row-start-2">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Price') }}</h3>
                        <div class="grid md:grid-cols-3 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Price (major units)') }} *</legend>
                                @php $pricedIn = old('price_currency', $product->price_currency ?? ''); @endphp
                                <div class="flex gap-2">
                                    <input name="price" type="number" step="0.01" min="0" aria-label="{{ __('Price (major units)') }}"
                                           value="{{ old('price', $product->exists ? $product->typedPrice() / 100 : '') }}" required
                                           class="input input-bordered w-full" placeholder="0.00">

                                    {{-- All three amounts on this card are in ONE currency: a
                                         product's cost, its price and its RRP are the same
                                         negotiation. The typed number is what stays fixed —
                                         the shop's own price is derived from it and moves
                                         with the rate. --}}
                                    @if ($pricesInOtherCurrencies)
                                        <select name="price_currency" aria-label="{{ __('Currency') }}" class="select select-bordered w-32 shrink-0">
                                            <option value="">{{ shop_currency() }}</option>
                                            @foreach ($currencies as $code => $currency)
                                                @continue($code === shop_currency())
                                                <option value="{{ $code }}" @selected($pricedIn === $code)>{{ $code }} {{ $currency->symbol }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </div>
                            </fieldset>
                            <label class="fieldset p-0"><span class="fieldset-legend">{{ __('RRP (list price)') }}</span>
                                <input name="list_price" type="number" step="0.01" min="0"
                                       value="{{ old('list_price', $product->list_price ? $product->typedPrice('list_price') / 100 : '') }}"
                                       class="input input-bordered w-full" placeholder="0.00">
                            </label>
                            <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Cost price') }}</span>
                                <input name="cost_price" type="number" step="0.01" min="0"
                                       value="{{ old('cost_price', $product->cost_price ? $product->typedPrice('cost_price') / 100 : '') }}"
                                       class="input input-bordered w-full" placeholder="0.00">
                            </label>
                        </div>
                        <div class="text-xs opacity-50 -mt-2">
                            {{ __('Cost price feeds markup rules on price groups; RRP shows crossed out on the product page when it exceeds the price the customer actually pays. Both optional.') }}
                        </div>

                        {{-- What the shop's own books will hold for this product, said
                             while it can still be changed: the conversion happens on
                             save, and a shop owner who cannot see the result would be
                             finding it out from a report. --}}
                        @if ($product->exists && $product->price_currency)
                            <div class="text-xs opacity-60 -mt-2">
                                {{ __('Kept in the shop\'s books as :amount, at 1 :from = :rate :to. Recomputed whenever that rate changes.', [
                                    'amount' => format_price($product->price, shop_currency()),
                                    'from' => shop_currency(),
                                    'rate' => format_rate($currencies[$product->price_currency]->rate ?? 1, thousands: ' '),
                                    'to' => $product->price_currency,
                                ]) }}
                            </div>
                        @endif

                        {{-- Extension point for anything that changes what this
                             price means rather than what it is — the taxes module
                             puts its per-product tax choice here. Whatever it
                             renders is saved by listening to catalog.product.saved. --}}
                        {!! hook_filter('catalog.product.pricing_extra', '', ['product' => $product]) !!}

                        <div>
                            <div class="text-sm font-medium">
                                {{ __('Pricing tiers') }}
                                @if ($product->exists && $product->price_currency)
                                    <span class="badge badge-sm badge-ghost font-mono">{{ $product->price_currency }}</span>
                                @endif
                            </div>
                            <div class="text-xs opacity-50 mb-2">
                                {{ __('Optional wholesale / quantity-break prices. The customer always gets whichever price is lowest — the list price above (with their group discount, if any) or the cheapest tier they qualify for by quantity and group.') }}
                                @if ($product->exists && $product->price_currency)
                                    {{ __('Entered in the product\'s price currency, like the price above; the shop\'s books follow the rate.') }}
                                @endif
                            </div>
                            @php
                                $initialTiers = collect($priceTiers ?? [])->map(fn ($t) => [
                                    'customer_group_id' => $t->customer_group_id,
                                    'min_qty' => $t->min_qty,
                                    'price' => $t->typedPrice() / 100,
                                ])->values();
                            @endphp
                            <div x-data="{
                                    rows: {{ \Illuminate\Support\Js::from($initialTiers) }},
                                    addRow() { this.rows.push({ customer_group_id: '', min_qty: 1, price: '' }) },
                                    removeRow(i) { this.rows.splice(i, 1) },
                                 }" class="flex flex-col gap-2">
                                {{-- Tiers and the price groups below share ONE grid
                                     (who · from how many · price · remove), so a price
                                     sits under a price; full-size fields like every
                                     other field of this card. --}}
                                <template x-for="(row, i) in rows" :key="i">
                                    {{-- On a phone the group takes a line of its own over the numbers. --}}
                                    <div class="grid grid-cols-[minmax(0,2fr)_7rem_minmax(0,3fr)_2.5rem] max-sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_2.5rem] gap-2 items-center">
                                        <select x-model="row.customer_group_id" aria-label="{{ __('Customer group') }}" class="select select-bordered w-full max-sm:col-span-3">
                                            <option value="">{{ __('All customers') }}</option>
                                            @foreach ($customerGroups as $group)
                                                <option value="{{ $group->id }}">{{ $group->t('name') }}</option>
                                            @endforeach
                                        </select>
                                        <input type="number" x-model="row.min_qty" min="1" placeholder="{{ __('Min qty') }}" aria-label="{{ __('Min qty') }}" class="input input-bordered w-full">
                                        <input type="number" x-model="row.price" min="0" step="0.01" placeholder="{{ __('Price') }}" aria-label="{{ __('Price') }}" class="input input-bordered w-full">
                                        <button type="button" @click="removeRow(i)" class="btn btn-ghost btn-square text-error"
                                                title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                                            <x-icon name="trash-2" size="sm" />
                                        </button>
                                    </div>
                                </template>
                                <button type="button" @click="addRow()" class="btn btn-sm self-start gap-1.5"><x-icon name="plus" size="sm" /> {{ __('Add tier') }}</button>
                                <input type="hidden" name="price_tiers" :value="JSON.stringify(rows)">
                            </div>
                        </div>

                        @if ($priceTypes->isNotEmpty())
                            <div>
                                <div class="text-sm font-medium">
                                    {{ __('Price groups') }}
                                    @if ($product->exists && $product->price_currency)
                                        <span class="badge badge-sm badge-ghost font-mono">{{ $product->price_currency }}</span>
                                    @endif
                                </div>
                                <div class="text-xs opacity-50 mb-2">
                                    {{ __('Leave blank to use the price type\'s own markup rule / adjustment — an override here always wins.') }}
                                    @if ($product->exists && $product->price_currency)
                                        {{ __('Entered in the product\'s price currency.') }}
                                    @endif
                                </div>
                                <div class="flex flex-col gap-2">
                                    @foreach ($priceTypes as $type)
                                        <div class="grid grid-cols-[minmax(0,2fr)_7rem_minmax(0,3fr)_2.5rem] max-sm:grid-cols-[minmax(0,1fr)_2.5rem] gap-2 items-center">
                                            <div class="col-span-2 max-sm:col-span-2 text-sm flex items-center gap-1.5">
                                                {{ $type->t('name', admin_content_locale()) }}
                                                <span class="badge badge-sm badge-ghost font-mono">{{ $type->code }}</span>
                                            </div>
                                            <input type="number" name="overrides[{{ $type->id }}]" min="0" step="0.01"
                                                   value="{{ old('overrides.'.$type->id, ($priceOverrides[$type->id] ?? null) !== null ? $priceOverrides[$type->id] / 100 : '') }}"
                                                   {{-- The computed price, in whatever the admin reads money in.
                                                        format_price() here showed it in the storefront's session
                                                        currency, which is not a currency anybody on this screen chose. --}}
                                                   placeholder="{{ isset($priceGroupPreview[$type->id]) ? __('computed').': '.admin_price($priceGroupPreview[$type->id]) : __('no formula') }}"
                                                   class="input input-bordered w-full">
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Stock and shipping') }}</h3>
                        <div class="grid md:grid-cols-2 gap-4">
                            <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Stock') }} *</span>
                                <input name="stock" type="number" value="{{ old('stock', $product->stock ?? 0) }}" required
                                       @if ($stockLocked) readonly title="{{ __('Split across warehouses — edit it there instead.') }}" @endif
                                       class="input input-bordered w-full {{ $stockLocked ? 'opacity-60' : '' }}" placeholder="0">
                            </label>
                            <div>
                            {{-- A second axis from Status below (PLAN §31.3): whether the
                                 product can be bought right now and whether the number
                                 above even applies. An open registry, so a module can add
                                 its own without this screen changing. --}}
                            @php
                                $availabilityStatuses = app(\Torgash\Catalog\Stock\AvailabilityStatuses::class);
                                $currentAvailability = old('availability_status', $product->availability_status ?? 'in_stock');
                            @endphp
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Availability') }}</legend>
                                <select name="availability_status" aria-label="{{ __('Availability') }}" class="select select-bordered w-full">
                                    @foreach ($availabilityStatuses->options() as $key => $label)
                                        <option value="{{ $key }}" @selected($currentAvailability === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <p class="text-xs opacity-50 mt-1">
                                    {{ __('Not the same as the Status: a discontinued product can stay Active (still on the site, just not buyable), and a hidden one keeps whatever availability it had.') }}
                                </p>
                            </fieldset>
                            </div>
                        </div>

                        {{-- Extension point: a warehouses module shows a per-warehouse
                             breakdown and a link to manage it, right under the field
                             it explains. Empty for a plain (unsplit) product. --}}
                        {!! hook_filter('catalog.product.stock_extra', '', ['product' => $product]) !!}

                        {{-- Weight and the box. Four engine fields, not features:
                             shipping prices by them, an import maps onto them and a
                             carrier's request names them. What the admin types is
                             whatever the shop's units say (Settings → Store);
                             storage is always grams and millimetres. --}}
                        {{-- Is it sent anywhere at all — off for a service, a download,
                             a gift card: a basket of such things is placed with no
                             address and no delivery (PLAN §40.10/2). --}}
                        <input type="hidden" name="needs_delivery_form" value="1">
                        <input type="hidden" name="needs_delivery" value="0">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="needs_delivery" value="1" class="toggle toggle-sm toggle-success"
                                   @checked(old('needs_delivery', $product->needs_delivery ?? true))>
                            <span class="label-text">{{ __('Needs delivery') }}</span>
                            <span class="text-xs opacity-60">{{ __('Off for a service, a download or an electronic gift card: an order of only such goods asks for no address and no delivery.') }}</span>
                        </label>

                        <div>
                            <div class="text-sm font-medium mb-2">{{ __('Weight and dimensions') }}</div>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <label class="fieldset p-0">
                                    <span class="fieldset-legend">{{ __('Weight') }}, {{ units()->weightLabel() }}</span>
                                    <input name="weight" type="number" step="{{ units()->weightStep() }}" min="0"
                                           value="{{ old('weight', units()->weightToForm($product->weight ?? null)) }}"
                                           class="input input-bordered w-full" placeholder="—">
                                </label>
                                {{-- `$dimension`, not `$field`: an @include further down
                                     inherits whatever is in scope here, and a variable
                                     called `$field` is exactly what a shared partial
                                     defaults. See the note in the gallery partial. --}}
                                @foreach (['length' => __('Length'), 'width' => __('Width'), 'height' => __('Height')] as $dimension => $label)
                                    <label class="fieldset p-0">
                                        <span class="fieldset-legend">{{ $label }}, {{ units()->lengthLabel() }}</span>
                                        <input name="{{ $dimension }}" type="number" step="{{ units()->lengthStep() }}" min="0"
                                               value="{{ old($dimension, units()->lengthToForm($product->$dimension ?? null)) }}"
                                               class="input input-bordered w-full" placeholder="—">
                                    </label>
                                @endforeach
                            </div>
                            <div class="text-xs opacity-50 mt-1">
                                @php $resolved = $product->exists ? $product->resolvedWeight() : null; @endphp
                                @if ($resolved !== null && $product->weight === null)
                                    {{ __('Empty here, but a characteristic says') }} <span class="font-medium">{{ units()->formatWeight($resolved) }}</span> —
                                    {{ __('shipping uses that. Fill the field to override it.') }}
                                @else
                                    {{ __('Left empty, a weight is "not measured": a method that needs one will say so instead of charging as if the parcel were weightless. All three sides are needed before volumetric weight can be computed.') }}
                                @endif
                            </div>
                        </div>
                    </div>
                </section>

                {{-- This product's own tabs. Links, not an editor: a tab is a title
                     and a body of rich text, which is the whole tab form — and the
                     rule the gallery card already follows applies here too, since
                     an unsaved product has no id to hang one on. --}}
                @if ($product->exists)
                    <div class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <div class="flex items-center gap-2">
                                <h3 class="font-semibold grow">{{ __('Tabs on this product') }}</h3>
                                <a href="{{ route('admin.catalog.product-tabs.create', ['product' => $product->id]) }}"
                                   class="btn btn-ghost btn-sm">{{ __('Add a tab') }}</a>
                            </div>

                            @forelse ($productTabs as $productTab)
                                <div class="flex items-center gap-2 text-sm">
                                    <a href="{{ route('admin.catalog.product-tabs.edit', $productTab) }}" class="link link-hover grow">
                                        {{ $productTab->t('title', $locale) ?: __('(untitled)') }}
                                    </a>
                                    @unless ($productTab->enabled)
                                        <span class="badge badge-ghost badge-sm">{{ __('Disabled') }}</span>
                                    @endunless
                                    <span class="opacity-50 text-xs">{{ $productTab->sort }}</span>
                                </div>
                            @empty
                                <p class="text-sm opacity-60">
                                    {{ __('None of its own. Shop-wide tabs are managed under Catalogue → Product tabs.') }}
                                </p>
                            @endforelse
                        </div>
                    </div>
                @endif
            </div>
            </div>
        </div>

        {{-- ── Attributes ────────────────────────────────────────────────── --}}
        {{-- Three cards: the shop's shared characteristics across the page,
             then this product's own list beside the one setting that reads it
             (the variation group names those keys as its axes). Every tab
             but the pair below keeps to the width of the General tab's left
             column, so every panel shares its right edge. --}}
        <div x-show="shows('attributes')" style="{{ $panelStyle('attributes') }}" class="flex flex-col gap-4">
            <h2 class="text-lg font-semibold pt-2" x-show="view === 'all'" x-cloak>{{ __('Attributes') }}</h2>

            {{-- The shop's characteristics this product's form shows: its own
                 set (Features\ProductFeatureSet — what its categories declare
                 plus what it already has), by group, a filter over the names
                 that hides rows without a request, and a box to bring in any
                 other by name for this edit. The save touches only what the
                 form sent (PLAN §63.9). --}}
            <section class="card bg-base-100 border border-base-300 xl:max-w-[calc(100%-23rem)]"
                     x-data="{ featureQ: '', featureMatches(el) { const q = this.featureQ.trim().toLowerCase(); return q === '' || (el.dataset.featureName || '').includes(q); } }">
              <div class="card-body gap-4">
                <div class="flex flex-wrap items-start gap-3">
                    <div class="grow min-w-48">
                        <h3 class="font-semibold">{{ __('Global characteristics') }}</h3>
                        <div class="text-xs opacity-50">
                            {!! __('Managed in :link — shared, filterable specs. Leave a field empty to skip it for this product.', [
                                'link' => '<a href="'.route('admin.catalog.features.index').'" class="link">'.__('Features').'</a>',
                            ]) !!}
                            {{ __('Shown: the ones bound to this product\'s categories and the ones it already has; any other joins by name below.') }}
                        </div>
                    </div>
                    @if ($globalFeatures->count() > 8)
                        <label class="input input-sm w-64 gap-2">
                            <x-icon name="list-filter" size="sm" class="opacity-50" />
                            <input type="search" x-model="featureQ" class="grow" placeholder="{{ __('Filter by name…') }}" aria-label="{{ __('Filter by name…') }}">
                        </label>
                    @endif
                </div>

                @php
                    $featureLocale = $locale;
                    $featureGroups = $globalFeatures->groupBy(fn ($gf) => $gf->group_id ?? 0);
                @endphp
                @forelse ($featureGroups as $featureGroupId => $featureGroupItems)
                    @php
                        $featureGroupName = $featureGroupItems->first()->group?->t('name', $locale);
                        $featureGroupFilled = $featureGroupItems->filter(fn ($gf) => $assignedFeatures->has($gf->id))->count();
                    @endphp
                    <details class="group/features" open>
                        <summary class="cursor-pointer select-none flex items-center gap-2 text-sm font-medium py-1">
                            <x-icon name="chevron-right" size="sm" class="opacity-50 transition-transform group-open/features:rotate-90" />
                            {{ $featureGroupName ?: __('Without a group') }}
                            <span class="badge badge-sm badge-ghost tabular-nums">{{ $featureGroupFilled }} / {{ $featureGroupItems->count() }}</span>
                        </summary>
                        <div class="grid md:grid-cols-2 2xl:grid-cols-3 gap-4 pt-2 pb-1">
                            @foreach ($featureGroupItems as $gf)
                                @include('torgash.catalog::admin.products._feature_field', ['featureField' => $gf, 'featureAssigned' => $assignedFeatures->get($gf->id)])
                            @endforeach
                        </div>
                    </details>
                @empty
                    <p class="text-sm opacity-60">{{ __('No characteristic is bound to this product\'s categories yet — add one by name below, or bind them on the Features screen.') }}</p>
                @endforelse

                {{-- Any other characteristic, for this edit: found by name,
                     drawn in as the same field, saved like the rest. --}}
                <div id="product-features-added" class="grid md:grid-cols-2 2xl:grid-cols-3 gap-4 empty:hidden"></div>
                <div>
                    <label class="input input-sm w-full max-w-md gap-2">
                        <x-icon name="plus" size="sm" class="opacity-50" />
                        <input type="search" name="feature_q" autocomplete="off" class="grow" placeholder="{{ __('Add a characteristic by name…') }}"
                               hx-get="{{ route('admin.catalog.products.features.search', ['product' => $product->id]) }}" hx-trigger="input changed delay:300ms, search"
                               hx-target="#product-features-found"
                               hx-vals='js:{on_form: [...document.querySelectorAll(".product-feature-field")].map((f) => f.dataset.featureId)}'>
                    </label>
                    <div id="product-features-found"></div>
                </div>
              </div>
            </section>

            <section class="card bg-base-100 border border-base-300 min-w-0 xl:max-w-[calc(100%-23rem)]"><div class="card-body gap-4">
            <div>
                <h3 class="font-semibold">{{ __('Individual attributes') }}</h3>
                <div class="text-xs opacity-50 mb-2">
                    {{ __('Words for this product\'s page only, printed under the characteristics above. Filters, variations and labels read only the ones above; for a spec many products share, add a Global characteristic instead.') }}
                </div>
                @if ($printedTwice)
                    {{-- The same thing printed twice (Features\OwnRows): said, not refused — an
                         own row may be the longer wording of a filter's value on purpose. --}}
                    <div class="alert alert-warning text-sm mb-2">
                        {{ __('The page shows these twice — here and among the characteristics above: :rows. If a row is only a longer wording of the value, untick Specifications on that characteristic so it only filters; otherwise delete the row.', ['rows' => '"'.implode('", "', array_keys($printedTwice)).'"']) }}
                    </div>
                @endif
                @php
                    $initialFeatures = old('features') ? json_decode(old('features'), true) : ($product->features ?? []);

                    // One list, two row types — the same shape that is stored
                    // (see the features_layout migration) and the same one the
                    // product page reads back. Built through featureRows() so
                    // the editor cannot disagree with the storefront about
                    // order, unplaced keys or empty sections.
                    // keepEmptySections: a section the admin added a second ago
                    // has nothing under it yet, and the storefront's "no heading
                    // over nothing" rule would delete it on the way back — which
                    // reads exactly like the save having failed.
                    $rowSource = old('features')
                        ? (new \Torgash\Catalog\Models\Product([
                            'features' => $initialFeatures ?: [],
                            'features_layout' => json_decode(old('features_layout', '[]'), true) ?: [],
                        ]))->featureRows(null, true, false)
                        : $product->featureRows(null, true, false);

                    $initialFeaturesRows = collect($rowSource)->map(fn (array $row) => $row['type'] === 'section'
                        ? ['type' => 'section', 'label' => $row['label'], 'key' => '', 'value' => '']
                        : [
                            'type' => 'feature',
                            'label' => '',
                            'key' => $row['key'],
                            'value' => is_scalar($row['value']) ? $row['value'] : json_encode($row['value'], JSON_UNESCAPED_UNICODE),
                        ])->values();
                @endphp
@if (content_locale_is_base() || ! $product->exists)
                <div x-data="{
                        mode: 'fields',
                        /* Every row carries a number of its own (`_id`): the list is
                           keyed by it, so a row dragged to a new place takes its
                           fields with it — keyed by position, Alpine would have kept
                           the elements where they were and only swapped their words. */
                        nextId: 1,
                        rows: {{ \Illuminate\Support\Js::from($initialFeaturesRows) }},
                        init() { this.rows = this.rows.map((r) => this.numbered(r)) },
                        numbered(row) { return Object.assign(row, { _id: this.nextId++ }) },
                        json: {{ \Illuminate\Support\Js::from(json_encode($initialFeatures ?: (object) [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) }},
                        addRow() { this.rows.push(this.numbered({ type: 'feature', key: '', value: '', label: '' })) },
                        addSection() { this.rows.push(this.numbered({ type: 'section', key: '', value: '', label: '' })) },
                        removeRow(i) { this.rows.splice(i, 1) },
                        /* By a drag (x-sort — Alpine Sort, which works under a
                           finger; PLAN §62) or by the arrows a finger gets. */
                        move(id, to) {
                            const from = this.rows.findIndex(r => r._id === id);
                            if (from === -1 || from === to || to < 0 || to >= this.rows.length) return;
                            const [row] = this.rows.splice(from, 1);
                            this.rows.splice(to, 0, row);
                        },
                        str(v) { return typeof v === 'string' ? v : JSON.stringify(v) },
                        values() {
                            const obj = {};
                            this.rows.forEach(r => { if (r.type !== 'section' && r.key) obj[r.key] = r.value; });
                            return obj;
                        },
                        /* The JSON tab shows what is actually there, in the shape
                           that says it. A plain map is what a spec list looks like
                           everywhere else (it is what exim exports, and what one
                           pastes in from a supplier), so a product without
                           sections keeps it. Add a section and the map can no
                           longer describe the thing — the full list appears
                           instead, because a raw view that hides half the data is
                           worse than a longer one. */
                        toJson() {
                            const hasSections = this.rows.some(r => r.type === 'section' && r.label);

                            this.json = JSON.stringify(hasSections
                                ? this.rows.filter(r => r.type === 'section' ? r.label : r.key)
                                      .map(r => r.type === 'section'
                                          ? { type: 'section', label: r.label }
                                          : { type: 'feature', key: r.key, value: r.value })
                                : this.values(), null, 2);
                            this.mode = 'json';
                        },
                        /* Both shapes are accepted on the way back. A LIST is the
                           whole picture and replaces everything, order included; a
                           MAP is values only and merges, so pasting a supplier's
                           spec map does not wipe the sections around it. */
                        toFields() {
                            try {
                                const parsed = JSON.parse(this.json || '{}');

                                this.rows = Array.isArray(parsed)
                                    ? parsed.map(r => this.numbered(r && r.type === 'section'
                                        ? { type: 'section', label: this.str(r.label ?? ''), key: '', value: '' }
                                        : { type: 'feature', key: this.str(r?.key ?? ''), value: this.str(r?.value ?? ''), label: '' }))
                                    : this.merged(parsed);
                            } catch (e) { /* keep existing rows if JSON is invalid */ }
                            this.mode = 'fields';
                        },
                        merged(obj) {
                            const kept = this.rows.filter(r => r.type === 'section' || r.key in obj);
                            kept.forEach(r => { if (r.type !== 'section') r.value = this.str(obj[r.key]); });
                            const known = kept.filter(r => r.type !== 'section').map(r => r.key);
                            Object.keys(obj).filter(k => !known.includes(k))
                                .forEach(k => kept.push(this.numbered({ type: 'feature', key: k, value: this.str(obj[k]), label: '' })));

                            return kept;
                        },
                        /* Whatever the tab, `features` is posted as a MAP and the
                           order as a list beside it: the two columns have fixed
                           shapes, and saving straight from the JSON tab must not
                           be the one path that stores something else. */
                        parsedJson() {
                            try { return JSON.parse(this.json || '{}'); } catch (e) { return null; }
                        },
                        serialized() {
                            if (this.mode !== 'json') return JSON.stringify(this.values());

                            const parsed = this.parsedJson();

                            if (parsed === null) return this.json; /* invalid: let it come back with the error */
                            if (! Array.isArray(parsed)) return JSON.stringify(parsed);

                            const obj = {};
                            parsed.forEach(r => { if (r && r.type !== 'section' && r.key) obj[r.key] = this.str(r.value ?? ''); });

                            return JSON.stringify(obj);
                        },
                        layout() {
                            const source = this.mode === 'json' && Array.isArray(this.parsedJson())
                                ? this.parsedJson().map(r => r && r.type === 'section'
                                    ? { type: 'section', label: r.label }
                                    : { type: 'feature', key: r?.key })
                                : this.rows.filter(r => r.type === 'section' ? r.label : r.key)
                                      .map(r => r.type === 'section' ? { type: 'section', label: r.label } : { type: 'feature', key: r.key });

                            return JSON.stringify(source.filter(r => r.type === 'section' ? r.label : r.key));
                        },
                     }" class="flex flex-col gap-2">
                    <div class="join">
                        <button type="button" class="btn btn-sm join-item" :class="mode === 'fields' ? 'btn-active' : ''" @click="toFields()">{{ __('Fields') }}</button>
                        <button type="button" class="btn btn-sm join-item" :class="mode === 'json' ? 'btn-active' : ''" @click="toJson()">JSON</button>
                    </div>

                    <template x-if="mode === 'fields'">
                        <div class="flex flex-col gap-2" x-sort="move($item, $position)" x-sort:config="{ delay: 150, delayOnTouchOnly: true }">
                            <template x-for="(row, i) in rows" :key="row._id">
                                <div class="flex gap-2 items-center" x-sort:item="row._id">
                                    {{-- The grip is the handle: a drag starting
                                         inside a text field stays the browser's
                                         own text selection. --}}
                                    <span class="cursor-grab opacity-30 hover:opacity-70 shrink-0 pointer-coarse:p-2" title="{{ __('Drag to reorder') }}" x-sort:handle>
                                        <x-icon name="grip-vertical" size="sm" />
                                    </span>
                                    <span class="hidden pointer-coarse:inline-flex items-center shrink-0">
                                        <button type="button" class="btn btn-ghost btn-sm btn-square" :disabled="i === 0"
                                                title="{{ __('Move earlier') }}" aria-label="{{ __('Move earlier') }}" @click="move(row._id, i - 1)"><x-icon name="arrow-up" size="sm" /></button>
                                        <button type="button" class="btn btn-ghost btn-sm btn-square" :disabled="i === rows.length - 1"
                                                title="{{ __('Move later') }}" aria-label="{{ __('Move later') }}" @click="move(row._id, i + 1)"><x-icon name="arrow-down" size="sm" /></button>
                                    </span>

                                    <template x-if="row.type === 'section'">
                                        <input type="text" x-model="row.label" placeholder="{{ __('Section heading') }}" aria-label="{{ __('Section heading') }}"
                                               class="input input-bordered w-full font-semibold bg-base-200">
                                    </template>

                                    <template x-if="row.type !== 'section'">
                                        <div class="flex gap-2 items-center w-full">
                                            <input type="text" x-model="row.key" placeholder="{{ __('key (e.g. color)') }}" aria-label="{{ __('key (e.g. color)') }}" class="input input-bordered w-2/5">
                                            <input type="text" x-model="row.value" placeholder="{{ __('value') }}" aria-label="{{ __('value') }}" class="input input-bordered w-full">
                                        </div>
                                    </template>

                                    <button type="button" @click="removeRow(i)" class="btn btn-ghost btn-sm text-error shrink-0">
                                        <x-icon name="x" size="sm" />
                                    </button>
                                </div>
                            </template>
                            <div class="flex gap-2">
                                <button type="button" @click="addRow()" class="btn btn-sm self-start">+ {{ __('Add field') }}</button>
                                <button type="button" @click="addSection()" class="btn btn-sm self-start">+ {{ __('Add section') }}</button>
                            </div>
                        </div>
                    </template>
                    <template x-if="mode === 'json'">
                        <div class="flex flex-col gap-1">
                            <textarea x-model="json" rows="6" aria-label="{{ __('Characteristics as JSON') }}" class="textarea textarea-bordered w-full font-mono text-sm" placeholder='{"color": "black"}'></textarea>
                            <div class="text-xs opacity-50">
                                {{ __('A map of values, or — once there are sections — the full list, which also carries the order. Both are accepted: a map only updates values and leaves the sections alone.') }}
                            </div>
                        </div>
                    </template>

                    <input type="hidden" name="features" :value="serialized()">
                    <input type="hidden" name="features_layout" :value="layout()">
                </div>
                @else
                @php
                    // Another language: the rows are the base language's and
                    // stay where they are; what is typed here is their words
                    // in THIS language (features_translations). A blank shows
                    // the base words on the storefront.
                    $wordsLocale = admin_content_locale();
                    $wordsNow = (array) (($product->features_translations ?? [])[$wordsLocale] ?? []);
                    $wordsRows = collect($product->featureRows(null, true, false))->map(fn (array $row) => $row['type'] === 'section'
                        ? ['type' => 'section', 'base' => $row['label'], 'word' => (string) ($wordsNow['s'][$row['label']] ?? '')]
                        : [
                            'type' => 'feature',
                            'base' => $row['key'],
                            'value' => is_scalar($row['value']) ? (string) $row['value'] : json_encode($row['value'], JSON_UNESCAPED_UNICODE),
                            'word' => (string) ($wordsNow['k'][$row['key']] ?? ''),
                            'valueWord' => (string) ($wordsNow['v'][$row['key']] ?? ''),
                        ])->values();
                @endphp
                <div x-data="{
                        rows: {{ \Illuminate\Support\Js::from($wordsRows) }},
                        serialized() {
                            const out = { k: {}, v: {}, s: {} };
                            this.rows.forEach(r => {
                                if (r.type === 'section') { if (r.word.trim()) out.s[r.base] = r.word; return; }
                                if (r.word.trim()) out.k[r.base] = r.word;
                                if (r.valueWord.trim()) out.v[r.base] = r.valueWord;
                            });
                            return JSON.stringify(out);
                        },
                     }" class="flex flex-col gap-2">
                    <div class="alert alert-info py-2 text-sm">
                        <x-icon name="languages" size="sm" />
                        <span>{{ __('Their words in :language. The rows themselves — adding, removing, the order — are edited in the base language. A blank shows the base words.', ['language' => strtoupper($wordsLocale)]) }}</span>
                    </div>
                    <template x-for="(row, i) in rows" :key="i">
                        <div class="flex gap-2 items-center">
                            <template x-if="row.type === 'section'">
                                <input type="text" x-model="row.word" :placeholder="row.base" class="input input-bordered w-full font-semibold bg-base-200">
                            </template>
                            <template x-if="row.type !== 'section'">
                                <div class="flex gap-2 items-center w-full">
                                    <input type="text" x-model="row.word" :placeholder="row.base" class="input input-bordered w-2/5">
                                    <input type="text" x-model="row.valueWord" :placeholder="row.value" class="input input-bordered w-full">
                                </div>
                            </template>
                        </div>
                    </template>
                    <p class="text-sm opacity-60" x-show="rows.length === 0">{{ __('This product has no attributes of its own yet.') }}</p>
                    <input type="hidden" name="features_words" :value="serialized()">
                </div>
                @endif
            </div>

            </div></section>
        </div>

        {{-- ── Variations ────────────────────────────────────────────────
             A group is ONE shared word: every product carrying it is a
             variation of the others. What tells them apart is the shop's own
             choice — the global characteristics whose purpose is "variation
             axis", of which only those that differ in the group are switched
             (Support\Variations, the same class the storefront asks). The tab
             shows the group as the storefront will: its axes, each product's
             answers, and the two ways a product falls out — the same answers
             as an older product (a duplicate: it keeps its page but no picker
             leads to it) and an answer missing on an axis. Read from the
             SAVED group: a word typed here links on Save. --}}
        @php
            $variationInfo = $product->exists && $product->variation_group
                ? app(\Torgash\Catalog\Support\Variations::class)->for($product, everyStatus: true)
                : null;
            $variationMembers = $variationInfo['members'] ?? collect();
            $variationAxes = $variationInfo['axes'] ?? [];
            $variationDuplicates = $variationInfo['duplicates'] ?? [];
            $hasVariationFeatures = \Torgash\Catalog\Models\Feature::where('purpose', \Torgash\Catalog\Models\Feature::PURPOSE_VARIATION)->exists();
        @endphp
        <div x-show="shows('variations')" style="{{ $panelStyle('variations') }}" class="flex flex-col gap-4 xl:max-w-[calc(100%-23rem)]">
            <h2 class="text-lg font-semibold pt-2" x-show="view === 'all'" x-cloak>{{ __('Variations') }}</h2>

            <section class="card bg-base-100 border border-base-300"><div class="card-body gap-3">
                <h3 class="font-semibold">{{ __('Variation group') }}</h3>
                <input name="variation_group" value="{{ old('variation_group', $product->variation_group) }}" class="input input-bordered w-full md:max-w-md font-mono"
                       aria-label="{{ __('Variation group') }}" placeholder="iphone-17-pro-max">
                <div class="text-xs opacity-60">{{ __('e.g. iphone-17-pro-max — same value links products as colour/size options of each other.') }}</div>
                <div class="text-xs opacity-60">
                    {!! __('They are told apart by the characteristics whose purpose is "Variation axis" (set in :link) — only those, and only the ones that differ inside the group.', [
                        'link' => '<a href="'.route('admin.catalog.features.index').'" class="link">'.e(__('Features')).'</a>',
                    ]) !!}
                </div>
                @unless ($hasVariationFeatures)
                    <div class="alert alert-warning text-sm py-2">
                        <x-icon name="triangle-alert" size="sm" />
                        <span>{{ __('No characteristic is marked as a variation axis yet, so no group can be switched on the storefront.') }}</span>
                    </div>
                @endunless
            </div></section>

            @if ($variationMembers->count() > 1)
                <section class="card bg-base-100 border border-base-300"><div class="card-body gap-3">
                    <div class="flex items-baseline justify-between gap-3 flex-wrap">
                        <h3 class="font-semibold">{{ __('Products in this group') }} <span class="opacity-50 font-normal">{{ $variationMembers->count() }}</span></h3>
                        @if ($variationAxes === [])
                            <span class="text-xs text-warning">{{ __('No variation axis differs between them, so the storefront has nothing to switch.') }}</span>
                        @endif
                    </div>
                    <div class="overflow-x-auto -mx-2">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th class="w-12"></th>
                                    <th>{{ __('Name') }}</th>
                                    <th>SKU</th>
                                    @foreach ($variationAxes as $axis)
                                        <th>{{ $axis['feature']->t('name', $locale) }}</th>
                                    @endforeach
                                    <th class="text-end">{{ __('Price') }}</th>
                                    <th class="text-end">{{ __('Stock') }}</th>
                                    <th>{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($variationMembers as $variant)
                                    @php
                                        $isThis = $variant->id === $product->id;
                                        $keptInstead = $variationDuplicates[$variant->id] ?? null;
                                        $keptProduct = $keptInstead ? $variationMembers->firstWhere('id', $keptInstead) : null;
                                    @endphp
                                    <tr @class(['bg-primary/5' => $isThis && ! $keptInstead, 'bg-warning/10' => $keptInstead, 'opacity-60' => $variant->status !== \Torgash\Catalog\Models\Product::STATUS_ACTIVE])>
                                        <td>
                                            <span class="w-10 h-10 rounded-md bg-base-200 overflow-hidden flex items-center justify-center">
                                                @if ($variantImage = $variant->primaryImageUrl(80))
                                                    <img src="{{ $variantImage }}" alt="" class="w-full h-full object-contain" loading="lazy">
                                                @else
                                                    <x-icon name="image" size="sm" class="opacity-30" />
                                                @endif
                                            </span>
                                        </td>
                                        <td>
                                            @if ($isThis)
                                                <span class="font-medium">{{ $variant->t('name', $locale) }}</span>
                                                <span class="badge badge-primary badge-sm ms-1">{{ __('this product') }}</span>
                                            @else
                                                <a href="{{ route('admin.catalog.products.edit', $variant) }}" class="link link-hover">{{ $variant->t('name', $locale) }}</a>
                                            @endif
                                            @if ($keptProduct)
                                                <div class="text-xs text-warning flex items-center gap-1 mt-0.5">
                                                    <x-icon name="triangle-alert" size="sm" />
                                                    {{ __('Not offered: the same answers as an older product,') }}
                                                    <a href="{{ route('admin.catalog.products.edit', $keptProduct) }}" class="link">{{ $keptProduct->sku }}</a>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="font-mono text-xs">{{ $variant->sku }}</td>
                                        @foreach ($variationAxes as $axis)
                                            @php $axisAnswer = $variationInfo['values'][$variant->id][$axis['feature']->id] ?? null; @endphp
                                            <td>
                                                @if ($axisAnswer === null)
                                                    <span class="text-error text-xs" title="{{ __('Without this value the product cannot be chosen by it.') }}">—</span>
                                                @else
                                                    <span class="inline-flex items-center gap-1.5">
                                                        @if ($axisAnswer['swatch'])
                                                            <span class="h-3 w-3 rounded-full border border-black/15" style="background-color: {{ $axisAnswer['swatch'] }}"></span>
                                                        @endif
                                                        {{ $axisAnswer['label'] }}
                                                    </span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="text-end tabular-nums whitespace-nowrap">{{ admin_price($variant->price) }}</td>
                                        <td class="text-end tabular-nums">{{ $variant->stock }}</td>
                                        <td><span class="text-xs">{{ __(ucfirst($variant->status)) }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs opacity-60">{{ __('A hidden or disabled product stays in the group here but is not offered on the storefront.') }}</p>
                </div></section>
            @elseif ($product->variation_group)
                <div class="text-sm opacity-60">{{ __('No other product carries this group yet: give another product the same word and they become variations of each other.') }}</div>
            @endif
        </div>

        {{-- ── SEO ───────────────────────────────────────────────────────── --}}
        <div x-show="shows('seo')" style="{{ $panelStyle('seo') }}" class="xl:max-w-[calc(100%-23rem)]">
            <x-seo.fields :record="$product" picture
                          :url-preview="$product->slug ? url('/product/'.$product->slug) : null" />
        </div>

        {{-- ── Related ───────────────────────────────────────────────────── --}}
        <div x-show="shows('related')" style="{{ $panelStyle('related') }}" class="card bg-base-100 border border-base-300 xl:max-w-[calc(100%-23rem)]">
            <div class="card-body gap-3">
                <div>
                    <h3 class="font-semibold">{{ __('Related products') }}</h3>
                    <div class="text-xs opacity-50">
                        {{ __('Shown as "You may also like" on this product\'s page. One-directional — picking A here doesn\'t make this product show up under A.') }}
                    </div>
                </div>

                @php
                    $relatedIds = array_map('intval', old('related_products', $product->exists ? $product->relatedProducts->pluck('id')->all() : []));
                @endphp

                <x-admin.picker
                    name="related_products[]"
                    :options="app(\Torgash\Catalog\Admin\ProductLookup::class)->options($relatedIds)"
                    :selected="$relatedIds"
                    :source="route('admin.catalog.products.lookup', $product->exists ? ['except' => $product->id] : [])"
                    :label="null"
                    :placeholder="__('Search products by SKU or name…')"
                    :empty="__('None selected yet.')"
                    ordered />
            </div>
        </div>

        {{-- ── A module's own tabs ───────────────────────────────────────── --}}
        @foreach ($moduleTabs as $moduleTab)
            <div x-show="shows('{{ $moduleTab['key'] }}')" style="{{ $panelStyle($moduleTab['key']) }}" class="flex flex-col gap-4 xl:max-w-[calc(100%-23rem)]">
                <h2 class="text-lg font-semibold pt-2" x-show="view === 'all'" x-cloak>{{ $moduleTab['label'] ?? $moduleTab['key'] }}</h2>
                {!! $moduleTab['html'] !!}
            </div>
        @endforeach

        @if ($moduleSections !== [])
            <div x-show="shows('modules')" style="{{ $panelStyle('modules') }}" class="flex flex-col gap-4 xl:max-w-[calc(100%-23rem)]">
                <h2 class="text-lg font-semibold pt-2" x-show="view === 'all'" x-cloak>{{ __('Modules') }}</h2>
                @foreach ($moduleSections as $moduleSection)
                    <section class="flex flex-col gap-2" data-module-section="{{ $moduleSection['key'] }}">
                        <h3 class="font-semibold">{{ $moduleSection['label'] ?? $moduleSection['key'] }}</h3>
                        {!! $moduleSection['html'] !!}
                    </section>
                @endforeach
            </div>
        @endif
    </form>

    @if ($product->exists)
        {{-- Standalone siblings of the product form: the gear menu's buttons target
             them by id, so no <form> is ever nested inside another. --}}
        <form id="product-duplicate" method="POST" action="{{ route('admin.catalog.products.duplicate', $product) }}" class="hidden">
            @csrf
        </form>
        <form id="product-delete" method="POST" action="{{ route('admin.catalog.products.destroy', $product) }}" class="hidden">
            @csrf @method('DELETE')
        </form>
    @endif

    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                Alpine.data('productForm', (initialTab) => ({
                    tab: initialTab,
                    view: 'tabs',

                    init() {
                        try {
                            const saved = localStorage.getItem('torgash-product-form-view');
                            if (saved === 'tabs' || saved === 'all') this.view = saved;
                        } catch (e) { /* private mode: stay on tabs */ }
                    },

                    setView(view) {
                        this.view = view;
                        try { localStorage.setItem('torgash-product-form-view', view); } catch (e) {}
                    },

                    shows(panel) {
                        return this.view === 'all' || this.tab === panel;
                    },
                }));
            });
        </script>
    @endpush
@endsection
