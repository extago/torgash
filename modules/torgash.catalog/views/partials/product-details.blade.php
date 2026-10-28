{{--
    Everything under the gallery, in one of two layouts.

    TABS is the compact one: one box, a strip of words, one panel visible. It
    fits a page whose sections are short and it is what the shop gets by
    default.

    SECTIONS is the long one: every section in its own box, under its own
    HEADING, all of them open, with a row of jump links above. The reason a shop
    wants it is the heading — a tab holds a word, a heading holds "Відгуки про
    Прожектор TIGER PRO-300 300W 6400K", which is a line a reader scanning the
    page understands out of context and a line a search engine can read. Nothing
    is hidden here, so there is no first section and no Alpine.

    The list itself is the same either way (ProductSections), which is what
    keeps the two layouts from drifting into two different pages.
--}}
@if (! empty($detailTabs))
    @if ($detailsAsSections)
        <div data-sections class="order-4 lg:order-none flex flex-col gap-4">
            @if (count($detailTabs) > 1)
                {{-- Ordinary anchors, not a tab strip: in this layout everything
                     is already on the page, so the row is a way of getting
                     there rather than a control that changes what is shown.

                     Once it scrolls under the header it STAYS there, across the
                     whole screen, until the sections end (the owner's call) —
                     the one bar, made fixed by the script below while its slot
                     keeps its height, never a second copy of the links. The
                     section being read is marked with aria-current. Without a
                     script it is simply a row of links at the top. --}}
                <div data-sections-slot>
                    <nav data-sections-nav aria-label="{{ __('On this page') }}"
                         class="group/secnav bg-base-100 border border-base-300 rounded-box data-[stuck]:fixed data-[stuck]:inset-x-0 data-[stuck]:top-(--torgash-header-h) data-[stuck]:z-20 data-[stuck]:rounded-none data-[stuck]:border-x-0 data-[stuck]:border-t-0 data-[stuck]:shadow-md">
                        <div class="relative flex gap-x-6 overflow-x-auto whitespace-nowrap px-5 text-sm [scrollbar-width:none] group-data-[stuck]/secnav:container group-data-[stuck]/secnav:mx-auto group-data-[stuck]/secnav:max-w-(--torgash-container) group-data-[stuck]/secnav:px-(--torgash-gutter)">
                            @foreach ($detailTabs as $detailTab)
                                <a href="#{{ $detailTab['key'] }}" data-sections-link
                                   class="py-3 border-b-2 border-transparent opacity-70 hover:opacity-100 aria-[current=true]:opacity-100 aria-[current=true]:border-primary aria-[current=true]:font-medium">{{ $detailTab['label'] }}@if (! empty($detailTab['count']))<span class="opacity-60 ms-1">{{ $detailTab['count'] }}</span>@endif</a>
                            @endforeach
                        </div>
                    </nav>
                </div>
            @endif

            @foreach ($detailTabs as $detailTab)
                {{-- scroll-mt: the header and the bar above both stay on
                     screen, so a section scrolled to by its anchor would
                     otherwise start underneath them. --}}
                <section id="{{ $detailTab['key'] }}" data-sections-target class="bg-base-100 border border-base-300 rounded-box p-5 scroll-mt-[calc(var(--torgash-header-h)+4rem)]">
                    <h2 class="text-lg lg:text-xl font-semibold mb-4 leading-snug">{{ $detailTab['heading'] }}</h2>
                    @include('torgash.catalog::partials.product-section-body')
                </section>
            @endforeach
        </div>

        @once
            @push('scripts')
                <script nonce="{{ csp_nonce() }}">
                    // The section bar: fixed under the header while the
                    // sections are being read, the current one marked. One
                    // passive scroll listener, work batched to a frame.
                    (() => {
                        const box = document.querySelector('[data-sections]');
                        const slot = box?.querySelector('[data-sections-slot]');
                        const nav = slot?.querySelector('[data-sections-nav]');

                        if (!nav) return;

                        const links = [...nav.querySelectorAll('[data-sections-link]')];
                        const targets = links.map((a) => document.getElementById(a.hash.slice(1)));
                        const row = nav.firstElementChild;
                        const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
                        let queued = false;

                        const update = () => {
                            queued = false;
                            // What stays of the header, in px — the layout's own number.
                            const offset = (parseFloat(getComputedStyle(document.body).getPropertyValue('--torgash-header-h')) || 0) * rem;
                            const height = nav.offsetHeight;
                            const stick = slot.getBoundingClientRect().top < offset && box.getBoundingClientRect().bottom > offset + height * 2;

                            if (stick && nav.dataset.stuck === undefined) {
                                slot.style.height = height + 'px';
                                nav.dataset.stuck = '';
                            } else if (!stick && nav.dataset.stuck !== undefined) {
                                delete nav.dataset.stuck;
                                slot.style.height = '';
                            }

                            // The section being read: the last one whose top has
                            // come a fifth of the way down the screen below the
                            // bar — a heading just under the bar is already what
                            // the eye is on. At the very bottom, the last one.
                            const line = offset + height + innerHeight * 0.2;
                            let current = 0;
                            targets.forEach((section, i) => { if (section && section.getBoundingClientRect().top <= line) current = i; });
                            if (innerHeight + scrollY >= document.documentElement.scrollHeight - 2) current = targets.length - 1;
                            links.forEach((a, i) => a.setAttribute('aria-current', i === current ? 'true' : 'false'));

                            // Keep the marked word in view in a row that scrolls
                            // sideways (a phone) — the ROW, never scrollIntoView,
                            // which would move the page itself.
                            const link = links[current];
                            if (link && (link.offsetLeft < row.scrollLeft || link.offsetLeft + link.offsetWidth > row.scrollLeft + row.clientWidth)) {
                                row.scrollLeft = link.offsetLeft - 16;
                            }
                        };

                        addEventListener('scroll', () => { if (!queued) { queued = true; requestAnimationFrame(update); } }, { passive: true });
                        addEventListener('resize', update);
                        update();
                    })();
                </script>
            @endpush
        @endonce
    @else
        {{-- A link to one of the sections (`#reviews` beside the price) opens
             its tab and brings the box into view — the anchor itself has
             nothing to scroll to while the panel is hidden. --}}
        <div x-data="{
                 tab: '{{ $firstTabKey }}',
                 keys: {{ \Illuminate\Support\Js::from(array_column($detailTabs, 'key')) }},
                 reveal(key) {
                     if (! this.keys.includes(key)) return;
                     this.tab = key;
                     this.$nextTick(() => this.$root.scrollIntoView({ behavior: 'smooth', block: 'start' }));
                 },
             }"
             x-init="location.hash && reveal(location.hash.slice(1))"
             @hashchange.window="reveal(location.hash.slice(1))"
             class="order-4 lg:order-none bg-base-100 border border-base-300 rounded-box scroll-mt-[calc(var(--torgash-header-h)+1rem)]">
            @if (count($detailTabs) > 1)
                <div role="tablist" class="tabs tabs-bordered px-5 pt-2">
                    @foreach ($detailTabs as $detailTab)
                        <a role="tab" class="tab" :class="tab === '{{ $detailTab['key'] }}' ? 'tab-active font-semibold' : ''"
                           @click.prevent="tab = '{{ $detailTab['key'] }}'" href="#">{{ $detailTab['label'] }}@if (! empty($detailTab['count']))<span class="opacity-60 ms-1">{{ $detailTab['count'] }}</span>@endif</a>
                    @endforeach
                </div>
            @endif

            @foreach ($detailTabs as $detailTab)
                <div x-show="tab === '{{ $detailTab['key'] }}'" @if ($firstTabKey !== $detailTab['key']) x-cloak @endif class="p-5">
                    {{-- With one section there is no strip, so the section needs
                         to say what it is. Its TAB word, not its heading: this
                         layout is the compact one. --}}
                    @if (count($detailTabs) === 1)
                        <h2 class="text-lg font-semibold mb-3">{{ $detailTab['label'] }}</h2>
                    @endif
                    @include('torgash.catalog::partials.product-section-body')
                </div>
            @endforeach
        </div>
    @endif
@endif
