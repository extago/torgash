{{--
    The product's photographs (PLAN §38, the gallery): a strip that SCROLLS —
    snap points, so a thumb swipes it on a phone and nothing else is needed —
    with the thumbnails down the left (arrows at both ends) and a zoom button
    in the corner on a computer, a "1/5" on a phone, and a click opening the
    full-screen viewer (on a phone the owner's design: back, zoom, the count, the heart, thumbnails
    beneath; on a computer the product's name, a cross, arrows at the sides
    over a dimmed page — see torgash.media's lightbox.mjs), which the page
    follows when it is closed on another photo.

    Three sizes per photo: 120 for the thumbnails, 800 for the page, the
    original for the viewer — with its real dimensions, so the viewer opens
    without first downloading every original to measure it.

    Without a script the strip still scrolls and every photo is on the page;
    only the viewer and the arrows need one.
--}}
@php
    $galleryCount = count($galleryItems);
    $galleryBase = url('/modules/torgash.media/assets');
    // The script needs to know which items are photos and which are videos,
    // never a video's player: that stays in the page, inert in a <template>.
    $galleryScriptItems = array_map(fn ($galleryItem) => array_diff_key($galleryItem, ['player' => true]), $galleryItems);
@endphp
<div class="relative flex gap-3" x-data="torgashGallery({{ \Illuminate\Support\Js::from($galleryScriptItems) }}, @js($galleryBase), @js((string) $product->t('name')))">
    @if ($galleryCount > 1)
        {{-- The thumbnails' column, with an arrow at each end (the owner's
             reference design): the arrows step to the previous / next photo,
             and the column scrolls to keep the lit thumbnail in view. --}}
        <div class="hidden lg:flex flex-col gap-1.5 shrink-0 w-[68px] max-h-[520px]">
            <button type="button" x-on:click="go(active - 1)" :disabled="active === 0" aria-label="{{ __('Previous photo') }}"
                    class="torgash-js-only btn btn-ghost btn-xs w-full h-6 min-h-0 bg-base-200 hover:bg-base-300 disabled:bg-base-200 disabled:opacity-40">
                <x-icon name="chevron-up" size="sm" />
            </button>
            <div x-ref="thumbs" class="relative min-h-0 flex flex-col gap-2 overflow-y-auto p-0.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                @foreach ($galleryItems as $galleryIndex => $galleryItem)
                    @php
                        // A played item that FILLS its slide (a 3D model on
                        // the page's own background) is drawn like a photo;
                        // a video keeps its dark 16:9 frame.
                        $galleryPlayed = ! empty($galleryItem['video']);
                        $galleryDark = $galleryPlayed && empty($galleryItem['fill']);
                    @endphp
                    <button type="button" x-on:click="go({{ $galleryIndex }})"
                            aria-label="{{ $galleryPlayed ? ($galleryItem['play_label'] ?? __('Video: :title', ['title' => $galleryItem['alt']])) : __('Photo :n of :count', ['n' => $galleryIndex + 1, 'count' => $galleryCount]) }}"
                            @class(['relative h-16 w-16 rounded-lg overflow-hidden border-2 shrink-0', 'bg-base-100' => ! $galleryDark, 'bg-neutral text-neutral-content' => $galleryDark])
                            :class="active === {{ $galleryIndex }} ? 'border-primary' : 'border-base-300 opacity-60 hover:opacity-100'">
                        @if (! empty($galleryItem['thumb']))
                            <img src="{{ $galleryItem['thumb'] }}" alt="" @class(['h-full w-full', 'object-contain' => ! $galleryDark, 'object-cover' => $galleryDark]) loading="lazy" decoding="async">
                        @endif
                        @if ($galleryPlayed)
                            {{-- A video (or a model) among the photos says so before it is opened. --}}
                            <span @class(['absolute inset-0 flex items-center justify-center', 'bg-black/25 text-white' => $galleryDark, 'text-base-content' => ! $galleryDark])>
                                <x-icon :name="$galleryItem['play_icon'] ?? 'circle-play'" size="lg" @class(['drop-shadow' => $galleryDark, 'bg-base-100/80 rounded-full p-0.5' => ! $galleryDark]) />
                            </span>
                        @endif
                    </button>
                @endforeach
            </div>
            <button type="button" x-on:click="go(active + 1)" :disabled="active >= count - 1" aria-label="{{ __('Next photo') }}"
                    class="torgash-js-only btn btn-ghost btn-xs w-full h-6 min-h-0 bg-base-200 hover:bg-base-300 disabled:bg-base-200 disabled:opacity-40">
                <x-icon name="chevron-down" size="sm" />
            </button>
        </div>
    @endif

    {{-- The stage spans the card (the photo is centred inside it), so the
         labels stand at the card's own left edge — beside the thumbnails
         when there are some — rather than floating over the photo. --}}
    <div class="relative grow min-w-0 group">
        @include('torgash.catalog::partials.label-badges', ['labels' => $productLabels, 'position' => 'detail', 'product' => $product])

        {{-- data-gallery-stage: what the variation picker shows another
             member's picture in while it is pointed at (variation-picker). --}}
        <div x-ref="track" data-gallery-stage x-on:scroll.debounce.50ms="sync()" x-on:touchstart.passive="letGo()" x-on:wheel.passive="letGo()" x-on:pointerdown="letGo()"
             class="flex overflow-x-auto snap-x snap-mandatory overscroll-x-contain [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @foreach ($galleryItems as $galleryIndex => $galleryItem)
                @if (! empty($galleryItem['video']))
                    {{-- A video: its poster and a play button, and the player
                         beside them in a <template> — nothing is asked of
                         the platform until play is pressed, and the player
                         is taken away again when the strip moves on.
                         Any module's item may ride the same contract
                         (`video => true` + `player`): `fill` makes it take
                         the whole slide on the page's own background rather
                         than a dark 16:9 frame, `play_icon` / `play_label`
                         say what pressing it does (a 3D model turns). --}}
                    @php $galleryFill = ! empty($galleryItem['fill']); @endphp
                    <div class="snap-center shrink-0 w-full h-[340px] sm:h-[420px] lg:h-[520px] flex items-center justify-center">
                        <div @class(['relative w-full rounded-lg overflow-hidden', 'h-full' => $galleryFill, 'max-h-full aspect-video bg-neutral text-neutral-content' => ! $galleryFill])>
                            <button type="button" x-on:click="play({{ $galleryIndex }})" class="absolute inset-0 flex flex-col items-center justify-center gap-2 cursor-pointer group/play"
                                    aria-label="{{ $galleryItem['play_label'] ?? __('Play the video: :title', ['title' => $galleryItem['alt']]) }}">
                                @if (! empty($galleryItem['stage']))
                                    <img src="{{ $galleryItem['stage'] }}" alt="{{ $galleryItem['alt'] }}" @class(['absolute inset-0 h-full w-full', 'object-contain' => $galleryFill, 'object-cover' => ! $galleryFill]) loading="lazy" decoding="async">
                                    @unless ($galleryFill)
                                        <span class="absolute inset-0 bg-black/20 group-hover/play:bg-black/10 transition-colors"></span>
                                    @endunless
                                @endif
                                <span class="relative flex items-center justify-center w-16 h-16 rounded-full bg-black/60 text-white group-hover/play:bg-primary transition-colors">
                                    @if (! empty($galleryItem['play_icon']))
                                        <x-icon :name="$galleryItem['play_icon']" size="xl" />
                                    @else
                                        <x-icon name="play" size="xl" class="fill-current translate-x-0.5" />
                                    @endif
                                </span>
                                @if (($galleryItem['platform'] ?? '') !== '')
                                    <span class="relative rounded bg-black/60 px-2 py-0.5 text-xs text-white">{{ __('The video loads from :platform', ['platform' => $galleryItem['platform']]) }}</span>
                                @endif
                            </button>
                            <div data-video-stage class="contents"></div>
                            <template data-video-player>{!! $galleryItem['player'] ?? '' !!}</template>
                        </div>
                    </div>
                    @continue
                @endif
                <button type="button" x-on:click="open({{ $galleryIndex }})"
                        class="snap-center shrink-0 w-full h-[340px] sm:h-[420px] lg:h-[520px] flex items-center justify-center cursor-zoom-in"
                        aria-label="{{ __('Open photo :n of :count', ['n' => $galleryIndex + 1, 'count' => $galleryCount]) }}">
                    <img src="{{ $galleryItem['stage'] }}" alt="{{ $galleryItem['alt'] }}" class="object-contain h-full max-w-full"
                         @if ($galleryItem['width'] && $galleryItem['height']) width="{{ $galleryItem['width'] }}" height="{{ $galleryItem['height'] }}" @endif
                         loading="{{ $galleryIndex === 0 ? 'eager' : 'lazy' }}" decoding="async" @if ($galleryIndex === 0) fetchpriority="high" @endif>
                </button>
            @endforeach
        </div>

        @if ($galleryCount > 1)
            {{-- On a phone only: a computer has the thumbnails' column, which says the same. --}}
            <div class="lg:hidden absolute left-2 bottom-2 flex items-center gap-1.5 rounded-lg bg-base-content/60 text-base-100 px-2 py-1 text-xs tabular-nums pointer-events-none">
                <x-icon name="image" size="sm" />
                <span x-text="(active + 1) + '/' + count">1/{{ $galleryCount }}</span>
            </div>
        @endif
    </div>

    {{-- "Open bigger" in the gallery's top right corner, on a computer — a
         button, so it is also how a keyboard opens the viewer. --}}
    <button type="button" x-on:click="open(active)" x-show="!items[active]?.video" aria-label="{{ __('Zoom') }}" title="{{ __('Zoom') }}"
            class="torgash-js-only hidden lg:flex absolute right-0 top-0 btn btn-sm btn-square btn-ghost border-base-300 bg-base-100 opacity-70 hover:opacity-100">
        <x-icon name="zoom-in" />
    </button>

    {{-- The viewer's icons and words, drawn by the page (lightbox.mjs writes none). --}}
    <template data-gallery-icon="arrow-left" data-label="{{ __('Back') }}"><x-icon name="arrow-left" size="lg" /></template>
    <template data-gallery-icon="heart" data-label="{{ __('Wishlist') }}"><x-icon name="heart" size="lg" /></template>
    <template data-gallery-icon="image"><x-icon name="image" size="sm" /></template>
    <template data-gallery-icon="zoom" data-label="{{ __('Zoom') }}"></template>
    <template data-gallery-icon="x" data-label="{{ __('Close') }}"><x-icon name="x" size="lg" /></template>
    <template data-gallery-icon="chevron-left" data-label="{{ __('Previous photo') }}"><x-icon name="chevron-left" size="xl" /></template>
    <template data-gallery-icon="chevron-right" data-label="{{ __('Next photo') }}"><x-icon name="chevron-right" size="xl" /></template>
</div>

@once
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                Alpine.data('torgashGallery', (items, base, title) => ({
                    items,
                    active: 0,
                    count: items.length,
                    playing: null,
                    heading: null,

                    // The viewer's files, fetched while the browser is idle —
                    // fetched on the first click they cost seconds (lightbox.mjs,
                    // preloadLightbox()). Only where there is a photo to open.
                    init() {
                        if (!this.items.some((item) => !item.video)) return;
                        let warmed = false;
                        const warm = () => {
                            if (warmed) return;
                            warmed = true;
                            import(base + '/lightbox.mjs').then((m) => m.preloadLightbox(base));
                        };
                        // A pointer heading for the photo is the surest sign; idle
                        // time covers a phone, which has no pointer to hover.
                        this.$el.addEventListener('pointerenter', warm, { once: true });
                        'requestIdleCallback' in window ? requestIdleCallback(warm, { timeout: 1500 }) : setTimeout(warm, 1000);
                    },

                    // The strip is scrolled, not transformed: a swipe, the
                    // arrows and a thumbnail all move the same thing, and
                    // `active` is read back from where it came to rest.
                    go(i) {
                        const track = this.$refs.track;
                        i = Math.max(0, Math.min(this.count - 1, i));
                        // Where the strip is heading: a smooth scroll past
                        // several slides pauses on its way on a slow phone,
                        // and those stops are not the visitor moving on.
                        this.heading = Math.abs(track.scrollLeft - i * track.clientWidth) < 2 ? null : i;
                        track.scrollTo({ left: i * track.clientWidth, behavior: 'smooth' });
                        this.active = i;
                        this.reveal();
                    },

                    sync() {
                        const track = this.$refs.track;
                        const at = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
                        if (this.heading !== null) {
                            if (at !== this.heading) return;
                            this.heading = null;
                        }
                        this.active = at;
                        this.reveal();
                        if (this.playing !== null && this.playing !== this.active) this.stop();
                    },

                    // A hand on the strip takes it over from go().
                    letGo() {
                        this.heading = null;
                    },

                    // A video's player is put on the page by a press of play
                    // and nothing else — until then the platform is not asked
                    // for so much as a script.
                    play(i) {
                        this.stop();
                        const slide = this.$refs.track.children[i];
                        const player = slide?.querySelector('template[data-video-player]');
                        const stage = slide?.querySelector('[data-video-stage]');
                        if (!player || !stage) return;
                        stage.replaceChildren(player.content.cloneNode(true));
                        // A browser's own player (the shop's file): the press
                        // of play was the gesture, so start it — `autoplay` on
                        // an element cloned from a template is not honoured
                        // everywhere. A platform's iframe starts by its address.
                        stage.querySelector('video')?.play()?.catch(() => {});
                        // Told to whoever drew the player: a 3D model's
                        // module loads its viewer only now (torgash.model3d).
                        stage.dispatchEvent(new CustomEvent('torgash:gallery-play', { bubbles: true }));
                        this.playing = i;
                        this.go(i);
                    },

                    // Moving on stops it: the player is taken away, the poster is back.
                    stop() {
                        if (this.playing === null) return;
                        this.$refs.track.children[this.playing]?.querySelector('[data-video-stage]')?.replaceChildren();
                        this.playing = null;
                    },

                    // Keep the lit thumbnail inside its column — scrolling the
                    // column itself, never scrollIntoView, which moves the page.
                    reveal() {
                        const column = this.$refs.thumbs;
                        const thumb = column?.children[this.active];

                        if (!thumb) return;

                        if (thumb.offsetTop < column.scrollTop) {
                            column.scrollTo({ top: thumb.offsetTop - 2, behavior: 'smooth' });
                        } else if (thumb.offsetTop + thumb.offsetHeight > column.scrollTop + column.clientHeight) {
                            column.scrollTo({ top: thumb.offsetTop + thumb.offsetHeight - column.clientHeight + 2, behavior: 'smooth' });
                        }
                    },

                    // The viewer is for photographs: a video plays where it
                    // stands, so the viewer is handed the photos alone and its
                    // index is translated both ways.
                    async open(i) {
                        const photos = this.items.map((item, at) => item.video ? null : at).filter((at) => at !== null);
                        if (!photos.includes(i)) return;
                        const { openLightbox } = await import(base + '/lightbox.mjs');
                        openLightbox({
                            items: photos.map((at) => this.items[at]).map((item) => ({ url: item.full, thumb: item.thumb, width: item.width, height: item.height, alt: item.alt })),
                            index: photos.indexOf(i),
                            base,
                            storefront: true,
                            title,
                            onChange: (j) => this.go(photos[j]),
                        });
                    },
                }));
            });
        </script>
    @endpush
@endonce
