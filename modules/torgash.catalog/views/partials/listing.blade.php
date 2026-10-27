{{--
    A listing of products with the catalogue's panel beside it — the
    catalogue, a category and a filter page (index.blade.php), a sale's page
    (torgash.promotions) and the search results draw the SAME one, fed by
    Torgash\Catalog\Listing\Listing::page().

    Given: $products, $filters, $activeFilters, $sort, $listing (from page()),
    $currentUrl (where "Reset" leads, and the form's address unless
    $listingAction says otherwise), $category (null off the catalogue).
    Optional: $listingKeep (name => value carried by every submit — a
    search's `q`), $categories (the category list in the panel),
    $subcategories, $filterPages / $filterPage (the catalogue's own).

    An included view inherits the caller's whole scope (docs/LESSONS.md):
    these names are the catalogue's own, so a page including this passes
    every one of them explicitly.
--}}
{{-- On a phone the panel is a full-screen sheet opened by "Filters" beside
     the sort, and the category list stands down — the menu drawer and the
     subcategory strip already lead there. The SAME aside in both, never a
     second copy of the controls (the HTML rule in CLAUDE.md). Only where a
     script runs (`.torgash-js`, app.css): without one the panel stays in
     the page's flow, list and all. --}}
{{-- The FORM holds the panel and nothing else. The grid beside it is full
     of forms of its own — every card's basket and heart — and a form inside
     a form is dropped by the parser with its inputs kept: the first card's
     basket button submitted the FILTERS (measured: `button.form` was this
     form). The sort in the bar over the grid joins this form through
     `form=` instead. --}}
<div class="flex flex-col lg:flex-row gap-3 items-start"
     x-data="{ filtersOpen: false }"
     x-effect="document.documentElement.classList.toggle('overflow-hidden', filtersOpen)"
     x-on:keydown.escape.window="filtersOpen = false">
<form id="catalog-filters" method="GET" action="{{ $listingAction ?? $currentUrl }}" class="relative w-full lg:w-64 shrink-0"
      data-catalog-filters>
    {{-- What the page itself is made of (a search's words), carried by
         every submit and kept by every chip. --}}
    @foreach ($listingKeep ?? [] as $listingKeepName => $listingKeepValue)
        <input type="hidden" name="{{ $listingKeepName }}" value="{{ $listingKeepValue }}">
    @endforeach
    <aside class="torgash-sheet w-full flex flex-col gap-3"
           x-bind:class="filtersOpen && 'is-open'" aria-label="{{ __('Filters') }}">
        <div class="torgash-js-only lg:hidden sticky top-0 z-10 -mx-3 px-3 h-14 shrink-0 flex items-center justify-between bg-base-100 border-b border-base-300">
            <span class="text-lg font-semibold">{{ __('Filters') }}</span>
            <button type="button" class="btn btn-ghost btn-circle btn-touch" x-on:click="filtersOpen = false" aria-label="{{ __('Close') }}"><x-icon name="x" size="lg" /></button>
        </div>
        @if (($categories ?? []) !== [])
            @include('torgash.catalog::partials.catalog-category-nav', ['navRows' => $categories])
        @endif

        {{-- A set that is not a category (a sale, a search): the categories
             its products stand in, one picked at a time — links, not boxes,
             and the picked one rides the form as `in` (Listing::scopeCategories). --}}
        @if (count($filters['in'] ?? []) > 1 || collect($filters['in'] ?? [])->contains('current', true))
            @php $listingIn = collect($filters['in'])->firstWhere('current', true); @endphp
            @if ($listingIn)
                <input type="hidden" name="in" value="{{ $listingIn['slug'] }}">
            @endif
            <nav class="bg-base-100 border border-base-300 rounded-box p-3" aria-label="{{ __('Category') }}">
                <div class="font-semibold px-2 pb-1">{{ __('Category') }}</div>
                <ul class="flex flex-col text-sm">
                    @if ($listingIn)
                        <li>
                            <a href="{{ $filters['in_clear'] ?? $currentUrl }}"
                               class="flex items-center gap-2 min-h-9 py-1.5 px-2 rounded-field hover:bg-base-200 text-base-content/70 hover:text-base-content">
                                <x-icon name="arrow-left" size="sm" class="shrink-0 opacity-60" />
                                <span class="grow">{{ __('All categories') }}</span>
                            </a>
                        </li>
                    @endif
                    @foreach ($filters['in'] as $listingInRow)
                        <li>
                            <a href="{{ $listingInRow['url'] }}" @if ($listingInRow['current']) aria-current="true" @endif
                               @class(['group flex items-center gap-2 min-h-9 py-1.5 px-2 rounded-field hover:bg-base-200',
                                       'font-bold' => $listingInRow['current']])>
                                <span class="grow min-w-0 leading-snug">{{ $listingInRow['name'] }}</span>
                                <span @class(['shrink-0 min-w-6 px-1.5 rounded-full text-xs text-center tabular-nums leading-5',
                                              'bg-base-200 text-base-content/60 group-hover:bg-base-100' => ! $listingInRow['current'],
                                              'font-bold' => $listingInRow['current']])>{{ $listingInRow['count'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif

        @include('torgash.catalog::partials.catalog-filters', ['filters' => $filters, 'category' => $category, 'currentUrl' => $currentUrl])
    </aside>
</form>

    <div class="grow min-w-0 w-full flex flex-col gap-3">
        {{-- What a module has to say about the whole listing before it
             starts — "stock is shown for the shop X" (torgash.warehouses).
             Drawn by the module; the catalogue only gives it the place. --}}
        {!! hook_filter('catalog.listing.notice', '', ['category' => $category]) !!}

        @include('torgash.catalog::partials.catalog-subcategories', ['subcategories' => $subcategories ?? collect()])

        @include('torgash.catalog::partials.catalog-filter-pages', ['filterPages' => $filterPages ?? ['filters' => collect(), 'places' => collect()], 'filterPage' => $filterPage ?? null])

        @include('torgash.catalog::partials.catalog-active-filters', ['activeFilters' => $activeFilters, 'currentUrl' => $currentUrl])

        @include('torgash.catalog::partials.catalog-toolbar', [
            'sort' => $sort,
            'listing' => $listing,
            'toolbarForm' => 'catalog-filters',
            'toolbarFilters' => true,
            'activeFilters' => $activeFilters,
        ])

        {{-- What other modules stand between the products — a banner after
             the sixth card (torgash.banners). Asked here, for this page of
             this listing in this layout; the grid only puts each answer
             in its place. --}}
        @php
            $listingInserts = (array) hook_filter('catalog.listing.inserts', [], [
                'category' => $category,
                'filter_page' => $filterPage ?? null,
                'layout' => $listing['layout'],
                'page' => method_exists($products, 'currentPage') ? $products->currentPage() : 1,
                'count' => $products->count(),
            ]);
        @endphp
        @include('torgash.catalog::partials.product-grid', ['products' => $products, 'gridLayout' => $listing['layout'], 'gridCols' => (int) theme_setting('catalog_columns', 4), 'gridInserts' => $listingInserts])
    </div>
</div>

@once
{{-- The address the form goes to, built from what is actually ticked.

     Two things a plain GET submit gets wrong and this puts right: it sends
     every box it holds, so an untouched price field rides along as
     `?price_min=`; and it can only say `feature[8][]=22`, while a
     characteristic with a code is worth spelling out — `?material=steel,wood`.
     A comma, because repeating the key would leave PHP holding only the
     last value.

     With no JavaScript the form submits itself the ordinary way and
     everything still works: the id form is read for ever (Filters\FilterQuery). --}}
<script nonce="{{ csp_nonce() }}">
    // ONE builder, used by the submit and by the floating button's count
    // (catalog-filters.blade.php), so the number on the button is the
    // number for the address the button then goes to.
    window.torgashCatalogAddress = function (form) {
        var query = new URLSearchParams();
        var spoken = new Map();

        // `elements`, not a query inside the form: the sort lives in the
        // bar over the grid and joins through `form=`.
        Array.prototype.forEach.call(form.elements, function (input) {
            if (! input.name || input.disabled) {
                return;
            }

            if ((input.type === 'checkbox' || input.type === 'radio') ? ! input.checked : input.value === '') {
                return;
            }

            // A word that only means something beside others — the
            // currency of a price — goes only when one of them does.
            if (input.dataset.filterWith && ! input.dataset.filterWith.split(' ').some(function (name) {
                return form.elements[name] && form.elements[name].value !== '';
            })) {
                return;
            }

            if (input.dataset.param) {
                if (! spoken.has(input.dataset.param)) {
                    spoken.set(input.dataset.param, []);
                }

                spoken.get(input.dataset.param).push(input.dataset.value);

                return;
            }

            query.append(input.name, input.value);
        });

        spoken.forEach(function (values, name) {
            query.set(name, values.join(','));
        });

        var string = query.toString();

        return form.getAttribute('action') + (string ? '?' + string : '');
    };

    document.querySelectorAll('form[data-catalog-filters]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            window.location = window.torgashCatalogAddress(form);
        });
    });
</script>
@endonce
