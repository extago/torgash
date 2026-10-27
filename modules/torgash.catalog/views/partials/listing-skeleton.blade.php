{{-- The next page's shape while "Show N more" fetches it (product-grid's
     button names this as its hx-indicator): hidden, and shown by htmx's
     `htmx-request` class for exactly as long as the request lasts — no
     script of ours. Only where there IS a next page; as many as are coming,
     up to two rows. Variables named distinctively: an @include inherits the
     caller's scope. --}}
@if ($products instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $products->hasMorePages())
    @php $skeletonCount = min($products->perPage(), $products->total() - $products->lastItem(), 2 * ($skeletonCols ?? 4)); @endphp
    @if ($skeletonRows)
        {{-- The list's rows sit in a panel of their own and this comes after
             it, on the page's grey — so it is a panel too (the grid's cards
             have it inside theirs). --}}
        <div id="listing-skeleton" class="torgash-skeleton-when-busy mt-3 bg-base-100 border border-base-300 rounded-box overflow-hidden divide-y divide-base-300" aria-hidden="true">
            @for ($skeletonI = 0; $skeletonI < min($skeletonCount, 4); $skeletonI++)
                <x-skeleton-card row />
            @endfor
        </div>
    @else
        <div id="listing-skeleton" class="torgash-skeleton-when-busy torgash-card-grid grid grid-cols-2 md:grid-cols-3 {{ $skeletonColsClass }} border-t border-base-300" aria-hidden="true"
             @if (($skeletonCols ?? 4) !== 4) data-cols="{{ $skeletonCols }}" @endif>
            @for ($skeletonI = 0; $skeletonI < $skeletonCount; $skeletonI++)
                <x-skeleton-card />
            @endfor
        </div>
    @endif
@endif
