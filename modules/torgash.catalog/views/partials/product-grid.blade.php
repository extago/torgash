@if ($products->isEmpty())
    <div class="bg-base-100 border border-base-300 rounded-box p-6 text-center opacity-60">{{ __('No products yet.') }}</div>
@else
    @php
        $gridCards = \Torgash\Catalog\Support\ProductCards::prime($products);
        // How many a product block shows before "Show more" (0 = all). Named
        // distinctively: @include hands this partial every variable its caller
        // has, and a plain $reveal from somewhere up the page would take over.
        $gridRevealStep = max(0, (int) ($gridReveal ?? 0));
        $gridRevealing = $gridRevealStep > 0 && $gridCards['products']->count() > $gridRevealStep;
        // Something another module stands between the products, by how many
        // products come before it: [6 => '<html>'] goes after the sixth.
        // Named distinctively, like everything an @include may inherit.
        $gridInsertsAt = array_filter((array) ($gridInserts ?? []), fn ($html, $after) => is_int($after) && $after > 0 && is_string($html) && trim($html) !== '', ARRAY_FILTER_USE_BOTH);
    @endphp
    {{-- The dividers are each cell's TOP and LEFT border, dropped on the first
         row and column (.torgash-card-grid in app.css, whose breakpoints mirror
         the grid-cols classes below). Not the old right/bottom borders tucked
         under an overflow-hidden wrapper: a hovered card opens a panel past its
         own bottom edge, and on the last row that clipping cut it off. --}}
    @if (($gridLayout ?? 'grid') === 'list')
        {{-- Rows instead of cards — the listing's "list" view
             (Support\ListingView). Same primed set, same hooks. --}}
        <div class="bg-base-100 border border-base-300 rounded-box divide-y divide-base-300 overflow-hidden" data-grid-cards>
            @foreach ($products as $product)
                @if ($loop->index > 0 && isset($gridInsertsAt[$loop->index]))
                    <div class="torgash-listing-insert p-3">{!! $gridInsertsAt[$loop->index] !!}</div>
                @endif
                @include('torgash.catalog::partials.product-row', [
                    'product' => $product,
                    'cardShortDescription' => $gridCards['short'][$product->id] ?? '',
                ])
            @endforeach
        </div>
        @include('torgash.catalog::partials.listing-skeleton', ['skeletonRows' => true])
    @else
    <div @if ($gridRevealing) x-data="{ shown: {{ $gridRevealStep }} }" @endif>
    <div class="bg-base-100 border border-base-300 rounded-box">
        {{-- $gridCols: a product block's "in a row on a computer" (3–6);
             the classes are written out whole so the stylesheet has them. --}}
        @php
            $gridColsXl = in_array((int) ($gridCols ?? 4), [3, 4, 5, 6], true) ? (int) ($gridCols ?? 4) : 4;
            $gridColsClass = [3 => 'xl:grid-cols-3', 4 => 'xl:grid-cols-4', 5 => 'xl:grid-cols-5', 6 => 'xl:grid-cols-6'][$gridColsXl];
        @endphp
        <div class="torgash-card-grid grid grid-cols-2 md:grid-cols-3 {{ $gridColsClass }}" @if ($gridColsXl !== 4) data-cols="{{ $gridColsXl }}" @endif data-grid-cards>
        @foreach ($products as $product)
            @if ($loop->index > 0 && isset($gridInsertsAt[$loop->index]))
                {{-- A cell of its own, the size of a card: the grid's dividers
                     count it like one. --}}
                <div class="torgash-listing-insert p-2">{!! $gridInsertsAt[$loop->index] !!}</div>
            @endif
            @include('torgash.catalog::partials.product-card', [
                'product' => $product,
                'cardLabels' => $gridCards['labels'][$product->id] ?? [],
                'cardShortDescription' => $gridCards['short'][$product->id] ?? '',
                'cardClass' => '',
                // Cards past the step wait for the button. Hidden on the page
                // rather than fetched later: the set is a block's, bounded and
                // already loaded, and a second request would have to rebuild the
                // zone's context (the product a block is placed beside) to be
                // sure it was answering the same question. Their images are
                // lazy, so a hidden card costs no download.
                'cardAttributes' => $gridRevealing && $loop->index >= $gridRevealStep
                    ? 'x-show="shown > '.$loop->index.'" x-cloak'
                    : '',
            ])
        @endforeach
        </div>
        @include('torgash.catalog::partials.listing-skeleton', ['skeletonRows' => false, 'skeletonColsClass' => $gridColsClass, 'skeletonCols' => $gridColsXl])
    </div>
    @if ($gridRevealing)
        <div class="text-center mt-4" x-show="shown < {{ $gridCards['products']->count() }}" x-cloak>
            <button type="button" class="btn btn-outline btn-sm" x-on:click="shown += {{ $gridRevealStep }}">
                {{ __('Show more') }}
            </button>
        </div>
    @endif
    </div>
    @endif

    @if ($products instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
        {{-- "Show N more" AND the pages, both at once (the owner's call): the
             button fetches the next page and ADDS its cards to these — the
             whole page is rendered by the server as for a link, htmx keeps
             only the cards (hx-select) and swaps this pager for the new one
             (hx-select-oob). The address moves on to that page, so a reload
             or a shared link lands where the visitor got to; the pager
             lights every page already on screen ("from" rides a header, so it
             never ends up in an address). Without a script the button is not
             drawn and the pages are plain links. --}}
        @php
            $gridLoadedFrom = max(1, min($products->currentPage(), (int) request()->header('X-Listing-From', $products->currentPage())));
            $gridMore = $products->hasMorePages() ? min($products->perPage(), $products->total() - $products->lastItem()) : 0;
        @endphp
        <div id="listing-pager" class="flex flex-col items-center gap-3 mt-4">
            @if ($gridMore > 0)
                <button type="button" class="torgash-js-only btn btn-outline w-full sm:w-auto sm:min-w-64"
                        hx-get="{{ $products->nextPageUrl() }}" hx-select="[data-grid-cards] > *" hx-target="[data-grid-cards]" hx-swap="beforeend"
                        hx-select-oob="#listing-pager" hx-push-url="{{ $products->nextPageUrl() }}"
                        hx-headers='{"X-Listing-From": "{{ $gridLoadedFrom }}"}' hx-disabled-elt="this"
                        hx-indicator="#listing-skeleton">
                    {{ __('Show :count more', ['count' => $gridMore]) }}
                </button>
            @endif
            {{ $products->links(null, ['loadedFrom' => $gridLoadedFrom]) }}
            @if ($products->total() > 0 && $products->hasPages())
                <div class="text-xs opacity-60 tabular-nums">
                    {{ __('Shown :from–:to of :total', ['from' => ($gridLoadedFrom - 1) * $products->perPage() + 1, 'to' => $products->lastItem(), 'total' => $products->total()]) }}
                </div>
            @endif
        </div>
    @elseif ($products instanceof \Illuminate\Contracts\Pagination\Paginator)
        <div>{{ $products->links() }}</div>
    @endif
@endif
