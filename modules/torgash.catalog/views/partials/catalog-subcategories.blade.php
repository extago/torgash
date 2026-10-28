{{-- What is under this category: tiles with a picture, buttons, or nothing —
     the category's own word, else the theme's (Category::subcategoriesView(),
     the theme's "Category page" settings).

     The grid below already holds everything in the subtree, so this is not a
     way to reach products that are otherwise hidden — it is how a visitor
     NARROWS, and how a crawler learns that the second level of the catalogue
     exists at all. Drawn isolated by the listing (Fragment): a page can do
     without it.

     Tiles are the "Category tiles" block's own drawing, so the home page and
     a category page look alike; a row that scrolls runs to the card's edges.
     Buttons keep to two rows and the rest wait behind "Show N more" — a
     branch of 26 used to be a page of links. Clamped only where the script
     runs to count what it hides; without it every button is there. --}}
@php
    $subcatsView = $subcategories->isNotEmpty() ? $category->subcategoriesView() : 'none';
    $subcatsCounts = (bool) theme_setting('subcategories_counts', true);
    // The theme names the frame and the air inside it; the block draws a
    // picture with padding around it, so the picture is what the frame leaves
    // (never under the block's 24px).
    $subcatsFrame = max(48, min(240, (int) theme_setting('subcategories_frame_size', 104)));
    $subcatsPadding = max(0, min((int) theme_setting('subcategories_frame_padding', 16), intdiv($subcatsFrame - 24, 2)));
@endphp
@if ($subcatsView === 'tiles')
    <div class="torgash-subcategories bg-base-100 border border-base-300 rounded-box">
        @include('torgash.catalog::blocks.category-tiles', [
            'inTabs' => false,
            'settings' => [
                'bare' => true,
                'categories' => $subcategories->pluck('id')->all(),
                'layout' => theme_setting('subcategories_layout', 'strip'),
                'look' => 'under',
                'shape' => theme_setting('subcategories_shape', 'circle'),
                'picture' => 'auto',
                'photos' => (bool) theme_setting('subcategories_photos', true),
                'counts' => $subcatsCounts,
                'picture_size' => $subcatsFrame - 2 * $subcatsPadding,
                'padding' => (string) $subcatsPadding,
                'gap_size' => theme_setting('subcategories_gap', 16),
                'arrows' => true,
                'indicator' => true,
            ],
        ])
    </div>
@elseif ($subcatsView === 'buttons')
    @php $subcatsNumbers = $subcatsCounts ? app(\Torgash\Catalog\Support\CategoryCounts::class)->of($subcategories) : null; @endphp
    <div class="torgash-subcategories bg-base-100 border border-base-300 rounded-box p-3"
         x-data="{ all: false, more: 0,
                   count() { const chips = [...$refs.list.children]; if (! chips.length) { return; }
                             const top = chips[0].offsetTop, step = chips[0].offsetHeight + 8;
                             this.more = chips.filter(c => c.offsetTop - top >= step * 2 - 1).length; } }"
         x-init="$nextTick(() => count())" x-on:resize.window.debounce.150ms="count()">
        <div x-ref="list" class="torgash-subcategory-chips flex flex-wrap gap-2" x-bind:class="all && 'is-all'"
             x-on:focusin="$event.target.matches(':focus-visible') && (all = true)">
            @foreach ($subcategories as $child)
                <a href="{{ route('catalog.category', $child->slug) }}" class="btn btn-sm bg-base-200/60 border-base-300/60 font-normal gap-1.5">
                    {{ $child->t('name') }}
                    @if ($subcatsNumbers !== null)
                        <span class="opacity-50 tabular-nums">{{ $subcatsNumbers[$child->id] ?? 0 }}</span>
                    @endif
                </a>
            @endforeach
        </div>
        <button type="button" x-cloak x-show="more > 0" x-on:click="all = ! all" x-bind:aria-expanded="all ? 'true' : 'false'"
                class="btn btn-ghost btn-sm mt-2 gap-1.5 text-primary">
            <span x-text="all ? @js(__('Show fewer')) : @js(__('Show :count more')).replace(':count', more)"></span>
            <span class="inline-flex transition-transform" x-bind:class="all && 'rotate-180'"><x-icon name="chevron-down" size="sm" /></span>
        </button>
    </div>
@endif
