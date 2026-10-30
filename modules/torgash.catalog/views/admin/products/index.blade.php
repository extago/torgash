@extends('admin.layout')

@section('title', __('Products'))
@section('title_hint', __('Find products by any mix of the filters, tick them — or everything found — and change them together: prices, stock, availability, categories, labels.'))

@php
    use Torgash\Catalog\Models\Product;

    $statusTabs = [
        '' => __('All'),
        Product::STATUS_ACTIVE => __('Active'),
        Product::STATUS_HIDDEN => __('Hidden'),
        Product::STATUS_DISABLED => __('Disabled'),
    ];
    $totalCount = $statusCounts->sum();

    // Column header links keep the current search/status and flip direction
    // when you click the column already being sorted by.
    $sortLink = function (string $column) use ($filters) {
        $dir = $filters['sort'] === $column && $filters['dir'] === 'asc' ? 'desc' : 'asc';

        return request()->fullUrlWithQuery(['sort' => $column, 'dir' => $dir, 'page' => null]);
    };

    // What narrows the list (as against how it is ordered) — carried into the
    // bulk form when the action is meant for everything found.
    $narrowing = collect($filters)->only(\Torgash\Catalog\Http\Admin\ProductsController::NARROWING)
        ->reject(fn ($v) => $v === null || $v === '');
    $moreFilters = $narrowing->except(['q', 'status'])->isNotEmpty();
    $foundCount = $products->total();
    $pageIds = $products->pluck('id')->map(fn ($id) => (string) $id);
@endphp

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    {{-- The states as one row of tabs over the card (app.css, `admin-tabs`):
         LINKS carrying the search and the other filters, so a tab is a
         question, never a reset. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Products') }}">
            @foreach ($statusTabs as $value => $label)
                @php $count = $value === '' ? $totalCount : ($statusCounts[$value] ?? 0); @endphp
                <a href="{{ route('admin.catalog.products.index', array_filter(['status' => $value] + request()->except(['status', 'page']), fn ($v) => $v !== null && $v !== '')) }}"
                   @class(['admin-tab', 'is-active' => (string) ($filters['status'] ?? '') === (string) $value])>
                    {{ $label }} <span class="admin-tab-count">{{ $count }}</span>
                </a>
            @endforeach
        </nav>
        {{-- The screen's actions, level with the tabs and as tall. --}}
        {{-- A reader (catalog.view alone) sees the list and the cards; every
             control that changes a product is the editor's (access.md). --}}
        @can('catalog.manage')
        <div class="admin-tabs-actions">
            @if ($trashedCount)
                <a href="{{ route('admin.catalog.products.trash') }}" class="btn btn-ghost gap-1.5">
                    <x-icon name="trash-2" />
                    {{ __('Trash') }} <span class="badge badge-sm">{{ $trashedCount }}</span>
                </a>
            @endif
            <a href="{{ route('admin.catalog.products.create') }}" class="btn btn-primary">+ {{ __('New product') }}</a>
        </div>
        @endcan
    </div>

    {{-- One Alpine root over the whole screen: the bulk bar and every row
         checkbox share this `selected` array. Rows are swapped by HTMX after a
         quick edit, and Alpine re-binds the new checkbox to this same scope.
         `found` widens the selection past this page to everything the filters
         found — the server re-reads the same filters, never a list of ids. --}}
    <div class="card bg-base-100 border border-base-300"
         x-data="{ selected: [], found: false, pageIds: @js($pageIds) }"
         x-init="$watch('selected', v => { if (v.length < pageIds.length) found = false })">
        <div class="card-body gap-4">
            {{-- Search + filters. A plain GET form, so every state this screen
                 can be in is a shareable, back-button-able URL. The screen's
                 actions stand in the tabs' row above; what the screen is for
                 is the hint beside the heading. --}}
            <form method="GET" class="flex flex-col gap-2" x-data="{ more: @js($moreFilters) }">
                <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
                <input type="hidden" name="dir" value="{{ $filters['dir'] }}">
                {{-- The state the tabs above chose rides along with every search
                     and every change of a filter (they are links now, so a
                     hidden field no longer outvotes anything). --}}
                @if ($filters['status'] !== null && $filters['status'] !== '')
                    <input type="hidden" name="status" value="{{ $filters['status'] }}">
                @endif
                <div class="flex flex-wrap items-center gap-2">
                    {{-- The search takes the width the row has to give. --}}
                    <label class="input input-sm grow min-w-48 gap-2">
                        <x-icon name="search" size="sm" class="opacity-50" />
                        <input type="search" name="q" value="{{ $filters['q'] }}" class="grow"
                               placeholder="{{ __('Search by SKU or name…') }}">
                    </label>

                    <button type="button" class="btn btn-ghost btn-sm gap-1.5" x-on:click="more = !more" :aria-expanded="more">
                        <x-icon name="sliders-horizontal" size="sm" /> {{ __('More filters') }}
                        @if ($moreFilters)
                            <span class="badge badge-primary badge-sm">{{ $narrowing->except(['q', 'status'])->count() }}</span>
                        @endif
                    </button>

                    @if ($narrowing->isNotEmpty())
                        <a href="{{ route('admin.catalog.products.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                    @endif

                    {{-- How many rows a page shows; a change asks the list again. --}}
                    <select name="per" class="select select-sm w-auto shrink-0" data-submit-on-change aria-label="{{ __('Rows per page') }}">
                        @foreach (\Torgash\Catalog\Http\Admin\ProductsController::PER_PAGE as $perOption)
                            <option value="{{ $perOption }}" @selected($filters['per'] === $perOption)>{{ __(':n per page', ['n' => $perOption]) }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="more" x-cloak class="flex flex-wrap items-end gap-2 bg-base-200/60 rounded-box p-3">
                    <fieldset class="fieldset p-0 w-full sm:w-64"><legend class="fieldset-legend text-xs pb-1">{{ __('Category') }}</legend>
                        <select name="category" class="select select-sm w-full">
                            <option value="">{{ __('Any') }}</option>
                            @foreach ($categoryOptions as $categoryId => $categoryLabel)
                                <option value="{{ $categoryId }}" @selected($filters['category'] === $categoryId)>{{ $categoryLabel }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                    <fieldset class="fieldset p-0 w-full sm:w-48"><legend class="fieldset-legend text-xs pb-1">{{ __('Availability') }}</legend>
                        <select name="availability" class="select select-sm w-full">
                            <option value="">{{ __('Any') }}</option>
                            @foreach ($availabilityOptions as $availabilityKey => $availabilityLabel)
                                <option value="{{ $availabilityKey }}" @selected($filters['availability'] === $availabilityKey)>{{ $availabilityLabel }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                    <fieldset class="fieldset p-0 w-full sm:w-40"><legend class="fieldset-legend text-xs pb-1">{{ __('Stock') }}</legend>
                        <select name="stock" class="select select-sm w-full">
                            <option value="">{{ __('Any') }}</option>
                            <option value="in" @selected($filters['stock'] === 'in')>{{ __('More than zero') }}</option>
                            <option value="out" @selected($filters['stock'] === 'out')>{{ __('Zero or less') }}</option>
                        </select>
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend text-xs pb-1">{{ __('Price, :currency', ['currency' => $shopCurrency]) }}</legend>
                        <div class="join">
                            <input type="number" step="any" min="0" name="price_min" value="{{ $filters['price_min'] }}" class="input input-sm join-item w-24" placeholder="{{ __('from') }}">
                            <input type="number" step="any" min="0" name="price_max" value="{{ $filters['price_max'] }}" class="input input-sm join-item w-24" placeholder="{{ __('to') }}">
                        </div>
                    </fieldset>
                    <button class="btn btn-sm btn-primary">{{ __('Find') }}</button>
                </div>
            </form>

            {{--
                One toolbar strip that always occupies the same 48px, showing
                either the hint or the bulk actions. Ticking the first checkbox
                used to *insert* the bulk bar, which shoved the entire table
                64px down under the cursor — the row you were aiming at moved
                out from under the pointer. Reserving the space means the
                content swaps in place and nothing below it ever moves.
            --}}
            <div class="min-h-12 max-md:min-h-0 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Found: :n.', ['n' => $foundCount]) }}
                    @can('catalog.manage'){{ __('Tip: click anywhere on a row to edit its SKU, price and stock inline.') }}@endcan
                </p>

                @can('catalog.manage')
                <form method="POST" action="{{ route('admin.catalog.products.bulk') }}" id="products-bulk"
                      x-show="selected.length" x-cloak
                      class="admin-bulk-bar w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    {{-- First in the form, so Enter inside the dialog applies the
                         dialog rather than pressing the first status button. --}}
                    <button name="action" value="edit" class="sr-only" tabindex="-1" aria-hidden="true"></button>

                    <input type="hidden" name="scope" :value="found ? 'found' : 'ids'">
                    <template x-if="!found">
                        <div class="contents">
                            <template x-for="id in selected" :key="id">
                                <input type="hidden" name="ids[]" :value="id">
                            </template>
                        </div>
                    </template>
                    <template x-if="found">
                        <div class="contents">
                            @foreach ($narrowing as $filterKey => $filterValue)
                                <input type="hidden" name="{{ $filterKey }}" value="{{ $filterValue }}">
                            @endforeach
                        </div>
                    </template>

                    <span class="text-sm px-1">
                        <span x-show="!found"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                        <span x-show="found" class="font-medium">{{ __('All :n found are selected', ['n' => $foundCount]) }}</span>
                    </span>
                    @if ($foundCount > $products->count())
                        <button type="button" class="btn btn-link btn-sm px-1"
                                x-show="!found && selected.length === pageIds.length"
                                x-on:click="found = true">{{ __('Select all :n found', ['n' => $foundCount]) }}</button>
                    @endif

                    <button type="button" class="btn btn-sm btn-primary gap-1.5" x-on:click="document.getElementById('products-bulk-edit').showModal()">
                        <x-icon name="pencil" size="sm" /> {{ __('Change…') }}
                    </button>
                    <div class="join">
                        <button name="action" value="active" class="btn btn-sm join-item">{{ __('Active') }}</button>
                        <button name="action" value="hidden" class="btn btn-sm join-item">{{ __('Hidden') }}</button>
                        <button name="action" value="disabled" class="btn btn-sm join-item">{{ __('Disabled') }}</button>
                    </div>
                    <button name="action" value="duplicate" class="btn btn-sm btn-ghost gap-1.5" title="{{ __('The copies are created disabled.') }}">
                        <x-icon name="copy" size="sm" /> {{ __('Duplicate') }}
                    </button>
                    <button name="action" value="trash" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Move the selected products to trash?') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Move to trash') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []; found = false">
                        {{ __('Clear selection') }}
                    </button>

                    @include('torgash.catalog::admin.products._bulk-edit', ['foundCount' => $foundCount])
                </form>
                @endcan
            </div>

            @if ($products->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="package-search" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $narrowing->isNotEmpty() ? __('No products match this search.') : __('No products yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    {{--
                        table-fixed + an explicit colgroup: with auto layout the
                        browser re-measures every column the moment a cell's
                        text becomes an <input>, so opening one row for editing
                        nudged the name column 16px narrower and the price
                        column 16px wider — the whole grid twitched. Fixed
                        layout makes column widths independent of cell content.
                    --}}
                    {{-- On a phone the same table is a list of cards (app.css,
                         `admin-product-cards`; the cells are named in _row). --}}
                    <table class="table table-fixed min-w-3xl admin-cards admin-product-cards">
                        <colgroup>
                            <col class="w-12">
                            <col class="w-16">
                            <col>
                            <col class="w-36">
                            <col class="w-28">
                            <col class="w-36">
                            <col class="w-32">
                        </colgroup>
                        <thead>
                            <tr>
                                @can('catalog.manage')
                                <th data-cell="check">
                                    {{-- Select-all covers this page; "Select all N found"
                                         in the bar above is what widens it past the page. --}}
                                    <label class="flex items-center gap-3">
                                        <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                               x-on:change="selected = $event.target.checked ? [...pageIds] : []"
                                               :checked="selected.length === pageIds.length">
                                        <span class="md:hidden font-normal normal-case">{{ __('Select all on this page') }}</span>
                                    </label>
                                </th>
                                @endcan
                                <th></th>
                                <th><a class="link link-hover" href="{{ $sortLink('sku') }}">{{ __('Name') }} / SKU</a></th>
                                <th><a class="link link-hover" href="{{ $sortLink('price') }}">{{ __('Price') }}</a></th>
                                <th><a class="link link-hover" href="{{ $sortLink('stock') }}">{{ __('Stock') }}</a></th>
                                <th><a class="link link-hover" href="{{ $sortLink('status') }}">{{ __('Status') }}</a></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- Each row fires per-product hooks (stock_locked and
                                 whatever a module adds next). This tells listeners the
                                 whole set up front so they can answer from one query
                                 instead of one per row — see StockAllocator::prime(). --}}
                            @php hook_action('catalog.products.listed', ['products' => collect($products->items())]) @endphp
                            @foreach ($products as $product)
                                @include('torgash.catalog::admin.products._row', ['product' => $product])
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $products->links() }}
            @endif
        </div>
    </div>

    {{-- Trash forms live outside the table: a <form> nested inside a row would
         have its tag dropped by the parser while its inputs stayed behind. --}}
    @can('catalog.manage')
    @foreach ($products as $product)
        <form id="trash-product-{{ $product->id }}" method="POST"
              action="{{ route('admin.catalog.products.destroy', $product) }}" class="hidden"
              data-confirm="{{ __('Move this product to trash?') }}">
            @csrf @method('DELETE')
        </form>
    @endforeach
    @endcan
@endsection
