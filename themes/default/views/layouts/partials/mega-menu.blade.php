{{--
    Catalog mega menu — full-width bar, panel bounded by the page container:
    the departments down the left with their icons, the hovered one's contents
    on the right.

    Items are the menu chosen in the theme setting "The Catalogue button
    opens" (torgash.menus), or the category tree when there is none — either
    way the layout hands over the same shape: {id, label, href, icon, badge,
    badgeStyle, highlight, newTab, cssClass, count, children[]} (PLAN §39).
    On the right, a child with children of its own becomes a COLUMN — its name
    as the heading, its children listed under it — which is the shape every
    reference shop's catalogue menu has; a child without them is a plain link.

    Positioned against the header's main row (relative or sticky), not the button, so it spans
    the bar. State (mega, megaActive) lives in the header's own x-data.

    torgash-bar-panel puts it back on the page palette — the header remaps the
    base tokens to its dark bar, and this panel is visually part of the page.
--}}

{{-- Backdrop. Sits at z-20, below the header's own z-30, so the bar and this
     panel stay lit while the page behind them dims — the shape the reference
     storefronts use. Needs its own @click: it lives inside <header>, so the
     header's @click.outside would never fire for it. --}}
<div x-cloak x-show="mega" @click="mega = false"
     x-transition:enter="transition-opacity ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition-opacity ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     class="fixed inset-0 z-20 bg-black/40" aria-hidden="true"></div>

{{-- Opens the way the shop's windows do (a short fade and a slide), with the
     classes written out: the `.opacity.duration` modifier shorthand once left
     the panel at opacity 0 after display flipped — open but invisible.
     The full-width wrapper lets clicks THROUGH (pointer-events-none): only the
     panel itself is a target, so a click in the dimmed space beside it lands
     on the backdrop and closes the menu, left and right as well as below. --}}
<div x-cloak x-show="mega" class="absolute inset-x-0 top-full z-30 pointer-events-none"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2">
    <div class="container mx-auto max-w-(--torgash-container) px-(--torgash-gutter)">
    {{-- A panel of its own right under the bar — rounded all round (no gap:
         one shows the dimmed strip under the header as a grey band), with
         the popovers' arrow pointing at the button that opened it (its
         middle measured on opening and on resize: the button's place depends
         on the logo, the words and the screen). --}}
    <div class="torgash-bar-panel torgash-mega-panel pointer-events-auto bg-base-100 rounded-box border border-base-300 shadow-xl py-4 px-4"
         x-effect="if (mega) $nextTick(() => megaArrow($el))" @resize.window="mega && megaArrow($el)"
         x-data="{ megaArrow(panel) {
             const button = [...(panel.closest('header') ?? document).querySelectorAll('[data-mega-toggle]')].find((el) => el.offsetParent !== null);
             if (! button) return panel.style.removeProperty('--mega-arrow');
             const b = button.getBoundingClientRect(), p = panel.getBoundingClientRect();
             panel.style.setProperty('--mega-arrow', Math.min(Math.max(24, b.left + b.width / 2 - p.left), p.width - 24) + 'px');
         } }">
        <div class="grid md:grid-cols-[minmax(0,280px)_minmax(0,1fr)] gap-4">

            {{-- Departments --}}
            <ul class="flex flex-col gap-0.5 md:border-r md:border-base-300 md:pr-4 max-h-[70vh] overflow-y-auto">
                @foreach ($megaItems as $megaItem)
                    <li>
                        <a href="{{ $megaItem->href }}"
                           @if ($megaItem->newTab) target="_blank" rel="noopener" @endif
                           @mouseenter="megaActive = {{ \Illuminate\Support\Js::from($megaItem->id) }}"
                           @focus="megaActive = {{ \Illuminate\Support\Js::from($megaItem->id) }}"
                           @class(['flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium hover:bg-base-200', $megaItem->cssClass, 'text-primary' => $megaItem->highlight])
                           :class="megaActive === {{ \Illuminate\Support\Js::from($megaItem->id) }} ? 'bg-base-200' : ''">
                            @if ($megaItem->image ?? null)
                                <img src="{{ $megaItem->image }}" alt="" class="w-5 h-5 shrink-0 object-contain" loading="lazy">
                            @elseif ($megaItem->icon)
                                <x-icon :name="$megaItem->icon" class="w-5 h-5 shrink-0 opacity-70" />
                            @endif
                            <span class="flex-1 min-w-0 truncate">{{ $megaItem->label }}</span>
                            @if ($megaItem->count !== null)
                                <span class="text-xs opacity-50 tabular-nums">{{ $megaItem->count }}</span>
                            @endif
                            @if ($megaItem->badge)
                                <span class="badge badge-{{ $megaItem->badgeStyle }} badge-xs font-semibold">{{ $megaItem->badge }}</span>
                            @endif
                            @if ($megaItem->children !== [])
                                <x-icon name="chevron-right" size="sm" class="opacity-40" />
                            @endif
                        </a>
                    </li>
                @endforeach
                <li class="mt-1 pt-1 border-t border-base-300">
                    <a href="{{ route('catalog.index') }}"
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-primary hover:bg-base-200">
                        <x-icon name="layout-grid" class="w-5 h-5 shrink-0" />
                        {{ __('All products') }}
                    </a>
                </li>
            </ul>

            {{-- The active department --}}
            @php $megaColumnLimit = max(0, (int) theme_setting('catalog_menu_column_limit', 5)); @endphp
            <div class="min-h-[12rem] max-h-[70vh] overflow-y-auto">
                @foreach ($megaItems as $megaItem)
                    @php
                        $megaColumns = collect($megaItem->children)->filter(fn ($megaChild) => $megaChild->children !== []);
                        $megaLinks = collect($megaItem->children)->filter(fn ($megaChild) => $megaChild->children === [] && $megaChild->kind !== 'divider');
                    @endphp
                    <div x-cloak x-show="megaActive === {{ \Illuminate\Support\Js::from($megaItem->id) }}" class="flex flex-col gap-4">
                        <a href="{{ $megaItem->href }}" class="text-lg font-semibold hover:text-primary inline-flex items-center gap-2 self-start">
                            {{ $megaItem->label }} <x-icon name="chevron-right" size="sm" class="opacity-50" />
                        </a>
                        @if ($megaColumns->isNotEmpty())
                            <div class="columns-2 lg:columns-3 xl:columns-4 gap-8">
                                @foreach ($megaColumns as $megaColumn)
                                    @php
                                        $megaColumnLinks = collect($megaColumn->children)->reject(fn ($megaGrandchild) => $megaGrandchild->kind === 'divider')->values();
                                        $megaColumnFolds = $megaColumnLimit > 0 && $megaColumnLinks->count() > $megaColumnLimit;
                                    @endphp
                                    <div class="break-inside-avoid mb-5" @if ($megaColumnFolds) x-data="{ more: false }" @endif>
                                        @include('layouts.partials.mega-menu-link', ['megaLink' => $megaColumn, 'megaLinkClass' => 'font-semibold'])
                                        <ul class="mt-1 flex flex-col">
                                            @foreach ($megaColumnLinks as $megaGrandchildIndex => $megaGrandchild)
                                                <li @if ($megaColumnFolds && $megaGrandchildIndex >= $megaColumnLimit) x-show="more" x-cloak @endif>@include('layouts.partials.mega-menu-link', ['megaLink' => $megaGrandchild, 'megaLinkClass' => 'opacity-80'])</li>
                                            @endforeach
                                        </ul>
                                        {{-- The rest of a long column, in place: a heading's first
                                             few are what a glance takes in, and a column of twenty
                                             pushes every other department off the panel. --}}
                                        @if ($megaColumnFolds)
                                            <button type="button" x-on:click="more = ! more"
                                                    class="inline-flex items-center gap-1 py-1 text-sm opacity-70 hover:opacity-100 hover:text-primary underline decoration-dotted underline-offset-4">
                                                <span x-text="more ? @js(__('Less')) : @js(__('More'))"></span>
                                                <span :class="more && 'rotate-180'" class="transition-transform"><x-icon name="chevron-down" size="sm" /></span>
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @if ($megaLinks->isNotEmpty())
                            <div class="columns-2 lg:columns-3 xl:columns-4 gap-8">
                                @foreach ($megaLinks as $megaLink)
                                    <div class="break-inside-avoid">@include('layouts.partials.mega-menu-link', ['megaLink' => $megaLink, 'megaLinkClass' => ''])</div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    </div>
</div>
