{{-- The cards of one page of a feed, and "show more" after them — which asks
     for the next page and is replaced by its cards and its own "show more".
     With no script it is an ordinary link to the next page. --}}
@foreach ($posts as $feedPost)
    @include('torgash.blog::partials.card', [
        'cardPost' => $feedPost,
        // The newest article of the first page leads, wide — the feed's cover.
        'cardWide' => ! ($more ?? false) && $posts->onFirstPage() && $loop->first,
    ])
@endforeach

@if ($posts->hasMorePages())
    {{-- While the next page is on its way the button gives its place to the
         shape of what is coming (hx-indicator → htmx-request on this box). --}}
    <div id="blog-more" class="col-span-full flex flex-col items-center pt-2 group">
        <a href="{{ $posts->nextPageUrl() }}"
           hx-get="{{ $posts->nextPageUrl() }}&more=1" hx-target="#blog-more" hx-swap="outerHTML" hx-indicator="#blog-more"
           class="btn btn-wide gap-2 group-[.htmx-request]:hidden">
            <x-icon name="chevrons-down" size="sm" /> {{ __('Show more') }}
        </a>
        <div class="hidden group-[.htmx-request]:grid w-full gap-5 grid-cols-1 md:grid-cols-2 xl:grid-cols-3" aria-hidden="true">
            @for ($blogSkeleton = 0; $blogSkeleton < min(3, $posts->perPage()); $blogSkeleton++)
                <div class="flex flex-col gap-3 bg-base-100 border border-base-300 rounded-box overflow-hidden pb-4">
                    <div class="skeleton aspect-[16/9] w-full rounded-none"></div>
                    <div class="skeleton h-4 w-1/3 mx-4"></div>
                    <div class="skeleton h-5 w-5/6 mx-4"></div>
                    <div class="skeleton h-3 w-2/3 mx-4"></div>
                </div>
            @endfor
        </div>
    </div>
@endif
