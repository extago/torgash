{{-- The page switcher. Every page already on screen is lit: "Show more" adds
     the next page to this one (product-grid, loadedFrom), so "2–3" can be
     what is showing.

     Laravel's own window (three pages each side, two at each end) is eleven
     buttons wide — past a phone's screen, which then scrolled sideways. So
     the window is worked out here, twice: a phone gets the first page, what
     is showing (one button, "2–3") and the last; a wider screen the same
     with two neighbours each side. Two copies of LINKS, not of controls —
     nothing here is submitted. A simple paginator (no page count) is just
     back and forward. --}}
@if ($paginator->hasPages())
    @php
        $pagerCurrent = $paginator->currentPage();
        $pagerFrom = min((int) ($loadedFrom ?? $pagerCurrent), $pagerCurrent);
        $pagerLast = method_exists($paginator, 'lastPage') ? $paginator->lastPage() : null;

        // [page, url, lit] or null for "…", around the pages on screen.
        $pagerItems = function (int $near, bool $joinLit) use ($paginator, $pagerCurrent, $pagerFrom, $pagerLast): array {
            if ($pagerLast === null) {
                return [];
            }
            $items = [];
            $previous = 0;
            for ($page = 1; $page <= $pagerLast; $page++) {
                $lit = $page >= $pagerFrom && $page <= $pagerCurrent;
                if (! ($page === 1 || $page === $pagerLast || ($page >= $pagerFrom - $near && $page <= $pagerCurrent + $near))) {
                    continue;
                }
                if ($page - $previous > 1) {
                    // One page missing is shown, never a "…" standing for it.
                    $items[] = $page - $previous === 2 ? [$page - 1, $paginator->url($page - 1), false] : null;
                }
                if ($lit && $joinLit && $page > $pagerFrom) {
                    // The lit run is one button: "2–3".
                    $items[count($items) - 1][0] = $pagerFrom.'–'.$page;
                } else {
                    $items[] = [$page, $paginator->url($page), $lit];
                }
                $previous = $page;
            }

            return $items;
        };
    @endphp
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex justify-center max-w-full">
        @foreach (['sm:hidden' => $pagerItems(0, true), 'hidden sm:flex' => $pagerItems(2, false)] as $pagerClass => $pagerList)
            <div class="join {{ $pagerClass }}">
                @if ($paginator->onFirstPage())
                    <span class="join-item btn btn-disabled" aria-hidden="true">
                        <x-icon name="chevron-left" />
                    </span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="join-item btn" aria-label="{{ __('pagination.previous') }}">
                        <x-icon name="chevron-left" />
                    </a>
                @endif

                @foreach ($pagerList as $pagerItem)
                    @if ($pagerItem === null)
                        <span class="join-item btn btn-disabled px-2">…</span>
                    @elseif ($pagerItem[2])
                        <span aria-current="page" class="join-item btn btn-active btn-primary">{{ $pagerItem[0] }}</span>
                    @else
                        <a href="{{ $pagerItem[1] }}" class="join-item btn" aria-label="{{ __('Go to page :page', ['page' => $pagerItem[0]]) }}">{{ $pagerItem[0] }}</a>
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="join-item btn" aria-label="{{ __('pagination.next') }}">
                        <x-icon name="chevron-right" />
                    </a>
                @else
                    <span class="join-item btn btn-disabled" aria-hidden="true">
                        <x-icon name="chevron-right" />
                    </span>
                @endif
            </div>
        @endforeach
    </nav>
@endif
