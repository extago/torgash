{{-- The strip under the header (theme setting "A strip of top categories"):
     the menu chosen in "The strip under the header shows", or the shop's top
     categories when none is. The first entry is set off as a pill — the
     menu's own order decides which.

     With the "Catalogue" button switched off this IS the catalogue's menu
     (tac-defense's third row), so an entry with subcategories opens them on
     hover or focus: its children, each with its own children listed under it
     as a column. What does not fit in one row is not squeezed or scrolled —
     it goes on to the next PAGE of the strip, and a "› 1/2" button at the end
     turns it. Without a script the row simply scrolls sideways, every entry
     reachable. --}}
@php
    $stripItems = ($menuFromSetting('strip_menu', '') ?? $categoryNodes(0, 3))
        ->filter(fn ($node) => $node->href !== null)->take(60)->values();
@endphp
@if ($stripItems->isNotEmpty())
    <div @class(['bg-base-100 border-b border-base-300 relative z-20', 'sticky top-(--torgash-strip-top)' => $stripSticks ?? false])
         x-data="torgashStrip">
        <nav x-ref="nav" class="torgash-category-strip container mx-auto max-w-(--torgash-container) px-4 h-12 flex items-center gap-6 overflow-x-auto whitespace-nowrap text-sm font-semibold"
             :class="ready && 'overflow-x-visible!'" aria-label="{{ __('Categories') }}">
            @foreach ($stripItems as $stripItem)
                @php $stripChildren = collect($stripItem->children ?? [])->filter(fn ($node) => $node->href !== null)->values(); @endphp
                <div data-strip-item class="group/strip relative h-full flex items-center shrink-0">
                    <a href="{{ $stripItem->href }}" @if ($stripItem->newTab) target="_blank" rel="noopener" @endif
                       @if ($stripItem->active) aria-current="page" @endif
                       @if ($stripChildren->isNotEmpty()) aria-haspopup="true" @endif
                       @class([
                           'inline-flex items-center gap-1.5',
                           $stripItem->cssClass,
                           'hover:text-primary' => ! $loop->first,
                           'text-primary' => ! $loop->first && ($stripItem->highlight || $stripItem->active || $stripItem->trail),
                           // The pill keeps its own text colour on hover: the
                           // strip's hover colour IS the pill's background.
                           // `text-[length:inherit]`: a badge sets its own,
                           // smaller type, and the first entry read a size
                           // below the rest of the row.
                           'badge badge-primary text-[length:inherit] font-semibold px-3 h-7 hover:brightness-95' => $loop->first,
                       ])>
                        @if (($stripItem->image ?? null) && ! $loop->first)
                            <img src="{{ $stripItem->image }}" alt="" class="w-4 h-4 object-contain" loading="lazy">
                        @elseif ($stripItem->icon && ! $loop->first)
                            <x-icon :name="$stripItem->icon" size="sm" class="opacity-70" />
                        @endif
                        {{ $stripItem->label }}
                        @if ($stripItem->badge)
                            <span class="badge badge-{{ $stripItem->badgeStyle }} badge-xs font-semibold">{{ $stripItem->badge }}</span>
                        @endif
                        @if ($stripChildren->isNotEmpty())
                            <x-icon name="chevron-down" size="sm" class="opacity-50 transition-transform group-hover/strip:rotate-180" />
                        @endif
                    </a>

                    @if ($stripChildren->isNotEmpty())
                        {{-- The drop-down: shown by CSS on hover and on focus
                             inside, so a keyboard gets it too. `data-align`
                             (set by the script) opens it leftwards for an
                             entry in the right half of the row; its arrow
                             points at the entry's middle, like a popover's. --}}
                        <div class="torgash-js-only hidden group-hover/strip:block group-focus-within/strip:block absolute top-full left-0 group-data-[align=right]/strip:left-auto group-data-[align=right]/strip:right-0 pt-2 z-40 whitespace-normal">
                            <div class="torgash-strip-panel bg-base-100 border border-base-300 rounded-box shadow-xl p-4 flex gap-8 w-max max-w-[min(56rem,90vw)] text-base font-normal">
                                @php $stripHasColumns = $stripChildren->contains(fn ($node) => ! empty($node->children)); @endphp
                                @if ($stripHasColumns)
                                    <div class="grid grid-flow-row gap-x-8 gap-y-4" style="grid-template-columns: repeat({{ min(4, $stripChildren->count()) }}, minmax(10rem, 1fr))">
                                        @foreach ($stripChildren as $stripChild)
                                            <div class="flex flex-col gap-1.5">
                                                <a href="{{ $stripChild->href }}" class="font-semibold hover:text-primary">{{ $stripChild->label }}</a>
                                                @foreach (collect($stripChild->children ?? [])->filter(fn ($node) => $node->href !== null)->take(8) as $stripGrandchild)
                                                    <a href="{{ $stripGrandchild->href }}" class="text-sm opacity-75 hover:opacity-100 hover:text-primary">{{ $stripGrandchild->label }}</a>
                                                @endforeach
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <ul class="flex flex-col gap-1.5 min-w-48">
                                        @foreach ($stripChildren as $stripChild)
                                            <li><a href="{{ $stripChild->href }}" class="hover:text-primary">{{ $stripChild->label }}</a></li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach

            <button type="button" x-cloak x-show="pages > 1" @click="turn()"
                    class="ms-auto shrink-0 btn btn-ghost btn-sm gap-1 tabular-nums"
                    :aria-label="(page + 1) + ' / ' + pages">
                <x-icon name="chevron-right" size="sm" />
                <span x-text="(page + 1) + '/' + pages"></span>
            </button>
        </nav>
    </div>

    @once
        @push('scripts')
            <script nonce="{{ csp_nonce() }}">
                document.addEventListener('alpine:init', () => {
                    // Splits the strip's entries into pages that each fit the
                    // row, measured — widths depend on the words, the font and
                    // the screen, so nothing about them is known in advance.
                    Alpine.data('torgashStrip', () => ({
                        ready: false,
                        page: 0,
                        pages: 1,
                        pageOf: [],

                        init() {
                            const again = () => this.layout();
                            (document.fonts?.ready ?? Promise.resolve()).then(again);
                            let timer;
                            window.addEventListener('resize', () => { clearTimeout(timer); timer = setTimeout(again, 150); });
                        },

                        items() {
                            return [...this.$refs.nav.querySelectorAll(':scope > [data-strip-item]')];
                        },

                        layout() {
                            const nav = this.$refs.nav;
                            const items = this.items();
                            items.forEach((el) => { el.hidden = false; });

                            const style = getComputedStyle(nav);
                            const gap = parseFloat(style.columnGap) || 0;
                            const room = nav.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
                            const widths = items.map((el) => el.offsetWidth);
                            const total = widths.reduce((sum, w) => sum + w, 0) + gap * Math.max(0, items.length - 1);

                            // Room for the "› 1/2" button once there is more than a page.
                            const fits = total <= room;
                            const limit = fits ? room : room - 80 - gap;
                            let page = 0;
                            let used = 0;
                            this.pageOf = widths.map((w) => {
                                const needed = used === 0 ? w : used + gap + w;
                                if (needed > limit && used > 0) {
                                    page++;
                                    used = w;
                                } else {
                                    used = needed;
                                }

                                return page;
                            });

                            this.pages = page + 1;
                            this.page = Math.min(this.page, page);
                            this.ready = true;
                            this.show();
                        },

                        show() {
                            // offsetLeft counts from the strip's own box (it is relative).
                            const width = this.$root.clientWidth;
                            this.items().forEach((el, i) => {
                                el.hidden = this.pageOf[i] !== this.page;
                            });
                            // An entry in the right half opens its drop-down
                            // leftwards; the arrow sits half the entry's width
                            // in from whichever edge the drop-down hangs from.
                            this.items().forEach((el) => {
                                el.dataset.align = el.offsetLeft + el.offsetWidth / 2 > width / 2 ? 'right' : 'left';
                                el.style.setProperty('--strip-arrow', (el.offsetWidth / 2) + 'px');
                            });
                        },

                        turn() {
                            this.page = (this.page + 1) % this.pages;
                            this.show();
                        },
                    }));
                });
            </script>
        @endpush
    @endonce
@endif
