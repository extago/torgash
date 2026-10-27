{{-- The bar over a listing: the order it answers in, how many to a page, and
     cards or rows. On a phone it also carries the button that opens the
     filter sheet.

     The SORT is form controls — radios named `sort` — and they belong to the
     filter panel's form through `form=` ($toolbarForm), not by sitting inside
     it: the grid under this bar holds every card's own buy form, and a form
     inside a form is dropped by the parser (CLAUDE.md), which is exactly what
     happened to the first card's basket button while the grid lived in the
     panel's form. Without JavaScript the panel's Apply submits the chosen
     order with everything ticked; with it, a choice edits `sort` in the
     address the page is ALREADY at — sorting changes the order of a set, not
     the set, and a published filter page has to keep its own address when it
     is sorted (index.blade.php's form points at the plain category on
     purpose). `page` goes too: page 3 of "newest first" is not page 3 of
     "cheapest first".

     Page size and layout are LINKS: they say nothing about which products
     the page holds, so they have no business in the filter form. The script
     remembers the choice in a cookie and takes the word back OUT of the
     address, so a visitor who picked 48 keeps 48 in the next category
     without every address they pass on growing `?per_page=48`; the href is
     for a browser with no script (Support\ListingView explains why the
     server never writes the cookie itself).

     Variables are named toolbar* where they are the caller's to give: an
     included view inherits the caller's whole scope. --}}
@php
    $toolbarListing = $listing ?? ['per_page' => 0, 'choices' => [], 'layout' => 'grid'];
    $toolbarFiltersCount = count($activeFilters ?? []);
    $toolbarHasFilters = (bool) ($toolbarFilters ?? false);

    // The no-script href: the address as it was TYPED, with one word swapped.
    // By hand, never fullUrlWithQuery(): that goes through http_build_query,
    // which numbers a list (`feature[8][0]=`) and spells the same filter a
    // third way (CLAUDE.md). `page` goes with a new page size.
    $toolbarHref = function (string $key, string $value) {
        $pairs = array_filter(
            explode('&', (string) request()->server('QUERY_STRING')),
            function (string $pair) use ($key) {
                $name = urldecode(explode('=', $pair, 2)[0]);

                return $pair !== '' && $name !== $key && ($key !== 'per_page' || $name !== 'page');
            },
        );
        $pairs[] = $key.'='.rawurlencode($value);

        return request()->url().'?'.implode('&', $pairs);
    };
@endphp
{{-- One line at every width: on a phone the sort's label truncates rather
     than push the layout switch onto a second row. --}}
<div class="bg-base-100 border border-base-300 rounded-box p-1.5 flex items-center gap-1">
    @if ($toolbarHasFilters)
        <button type="button" class="torgash-js-only lg:hidden btn btn-ghost btn-sm btn-touch gap-2 shrink-0" x-on:click="filtersOpen = true">
            <x-icon name="sliders-horizontal" />
            {{ __('Filters') }}
            @if ($toolbarFiltersCount > 0)
                <span class="badge badge-primary badge-sm">{{ $toolbarFiltersCount }}</span>
            @endif
        </button>
    @endif

    @if ($sort)
        @php $toolbarSortCurrent = $sort['current']; @endphp
        <x-popover id="catalog-sort-menu" class="btn btn-ghost btn-sm btn-touch gap-2 font-normal min-w-0 shrink flex-nowrap" panel-class="p-1.5 w-64">
            <ul class="flex flex-col">
                @foreach ($sort['options'] as $toolbarSortKey => $toolbarSortLabel)
                    <li>
                        <label class="flex items-center gap-3 rounded-field px-3 py-2 min-h-10 text-sm cursor-pointer hover:bg-base-200 has-checked:text-primary has-checked:font-medium">
                            <input type="radio" name="sort" value="{{ $toolbarSortKey }}" class="sr-only peer"
                                   @if ($toolbarForm ?? null) form="{{ $toolbarForm }}" @endif
                                   data-catalog-sort @checked($toolbarSortKey === $toolbarSortCurrent)>
                            <x-icon :name="$sort['icons'][$toolbarSortKey] ?? 'arrow-up-down'" size="sm" class="shrink-0 opacity-70" />
                            <span class="grow">{{ $toolbarSortLabel }}</span>
                            <x-icon name="check" size="sm" class="shrink-0 invisible peer-checked:visible" />
                        </label>
                    </li>
                @endforeach
            </ul>
            <x-slot:trigger>
                <x-icon :name="$sort['icons'][$toolbarSortCurrent] ?? 'arrow-up-down'" size="sm" class="shrink-0 opacity-70" />
                <span class="max-sm:sr-only opacity-60">{{ __('Sort by') }}:</span>
                <span class="truncate">{{ $sort['options'][$toolbarSortCurrent] ?? '' }}</span>
                <x-icon name="chevron-down" size="sm" class="shrink-0 opacity-50" />
            </x-slot:trigger>
        </x-popover>
    @endif

    <div class="ms-auto flex items-center gap-1 shrink-0">
        @if (count($toolbarListing['choices']) > 1)
            <span class="text-sm opacity-60 px-1 max-md:hidden">{{ __('Show per page') }}</span>
            <div class="flex items-center gap-1 max-sm:hidden" role="group" aria-label="{{ __('Show per page') }}">
                @foreach ($toolbarListing['choices'] as $toolbarSize)
                    @php $toolbarSizeOn = $toolbarSize === (int) $toolbarListing['per_page']; @endphp
                    <a href="{{ $toolbarHref('per_page', (string) $toolbarSize) }}" rel="nofollow"
                       data-catalog-remember="{{ \Torgash\Catalog\Support\ListingView::PER_PAGE_COOKIE }}" data-catalog-param="per_page" data-catalog-value="{{ $toolbarSize }}"
                       @class(['btn btn-ghost btn-sm btn-touch px-2.5 tabular-nums font-normal', 'bg-base-200 font-semibold! text-primary' => $toolbarSizeOn])
                       @if ($toolbarSizeOn) aria-current="true" @endif>{{ $toolbarSize }}</a>
                @endforeach
            </div>
            <span class="w-px h-6 bg-base-300 mx-1 max-sm:hidden" aria-hidden="true"></span>
        @endif

        {{-- Not on a phone: the screen is too narrow for a row to carry more
             than a card already does, so there is nothing to switch to. A browser that
             never shows the switch never writes the cookie, so a phone stays
             on the grid whatever the same visitor picked on a computer. --}}
        <div class="flex items-center gap-1 max-sm:hidden" role="group" aria-label="{{ __('Display as') }}">
            @foreach (['grid' => ['layout-grid', __('Grid')], 'list' => ['list', __('List')]] as $toolbarLayout => [$toolbarLayoutIcon, $toolbarLayoutLabel])
                @php $toolbarLayoutOn = $toolbarLayout === $toolbarListing['layout']; @endphp
                <a href="{{ $toolbarHref('view', $toolbarLayout) }}" rel="nofollow"
                   data-catalog-remember="{{ \Torgash\Catalog\Support\ListingView::LAYOUT_COOKIE }}" data-catalog-param="view" data-catalog-value="{{ $toolbarLayout }}"
                   @class(['btn btn-ghost btn-sm btn-touch btn-square', 'bg-base-200 text-primary' => $toolbarLayoutOn])
                   title="{{ $toolbarLayoutLabel }}" aria-label="{{ $toolbarLayoutLabel }}" @if ($toolbarLayoutOn) aria-current="true" @endif>
                    <x-icon :name="$toolbarLayoutIcon" />
                </a>
            @endforeach
        </div>
    </div>
</div>

{{-- One script for the bar, and a page draws the bar once. --}}
<script nonce="{{ csp_nonce() }}">
    (function () {
        // `page` goes whenever the pages themselves change — another order,
        // another size; cards or rows is the same page drawn differently.
        function go(mutate, keepPage) {
            var url = new URL(window.location.href);

            mutate(url.searchParams);

            if (! keepPage) {
                url.searchParams.delete('page');
            }

            window.location = url.toString();
        }

        @if ($sort)
        document.querySelectorAll('input[data-catalog-sort]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                go(function (params) { params.set('sort', radio.value); });
            });
        });
        @endif

        document.querySelectorAll('a[data-catalog-remember]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();

                document.cookie = link.dataset.catalogRemember + '=' + encodeURIComponent(link.dataset.catalogValue)
                    + '; path=/; max-age=31536000; SameSite=Lax';

                go(function (params) { params.delete(link.dataset.catalogParam); }, link.dataset.catalogParam === 'view');
            });
        });
    })();
</script>
