/*
 * The banners' slider (PLAN §51): Alpine's `torgashSlider`, which hands the
 * strip the server printed ({slides} in a frame template) to Embla Carousel
 * (MIT, vendored beside this file) and keeps the frame's pieces in step with
 * it — the dots ({dots}, one per stop: with three cards in view there are
 * fewer stops than slides), {counter} and {progress}.
 *
 * Embla is fetched only by a page that has a slider, and only the plugins the
 * slider asks for. Autoplay is never started for a visitor who asked their
 * system for less motion, waits under the pointer and the keyboard's focus,
 * and stops while the tab is hidden. A slide out of view is `inert`, so Tab
 * never walks into a banner nobody can see.
 *
 * A looping strip too short to loop smoothly is lengthened with COPIES of its
 * slides before Embla takes it: Embla loops by moving slides from one end to
 * the other, and with six cards and four in view (or three big slides) the
 * one it moves is still on screen — the card that should come next popped in
 * late, or the strip jerked at the seam. The copies are aria-hidden; the
 * dots, the counter and the progress count the ORIGINALS, and a dot goes to
 * the nearest copy of its slide.
 *
 * Loaded as a plain script at the end of the body, before Alpine (deferred)
 * starts — which is what lets it register on `alpine:init`.
 */
(() => {
    if (window.torgashSliderScript) {
        return;
    }
    window.torgashSliderScript = true;

    const base = new URL('embla/', document.currentScript.src);
    const loading = {};

    const load = (name) => loading[name] ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = new URL(name + '.umd.js', base).href;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
    });

    document.addEventListener('alpine:init', () => {
        Alpine.data('torgashSlider', (options) => ({
            ready: false,
            selected: 0,
            snaps: 1,
            progress: 0,
            canPrev: true,
            canNext: true,
            api: null,
            count: 1,

            async init() {
                const viewport = this.$refs.viewport;
                if (!viewport) {
                    return;
                }

                const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                const autoplay = options.autoplay > 0 && !calm;

                try {
                    await load('embla-carousel');
                    await Promise.all([
                        autoplay ? load('embla-carousel-autoplay') : null,
                        options.effect === 'fade' ? load('embla-carousel-fade') : null,
                    ]);
                } catch (e) {
                    return; // The strip stays a row that scrolls by touch.
                }

                const plugins = [];

                if (autoplay) {
                    plugins.push(window.EmblaCarouselAutoplay({
                        delay: options.autoplay * 1000,
                        stopOnInteraction: false,
                        stopOnMouseEnter: true,
                        stopOnFocusIn: true,
                    }));
                }

                // Fading is for one slide at a time; several in view slide.
                if (options.effect === 'fade' && options.per_view === 1) {
                    plugins.push(window.EmblaCarouselFade());
                }

                // Where a finger may have scrolled the fallback row to.
                viewport.scrollLeft = 0;
                this.count = this.lengthen(viewport, options);
                this.api = window.EmblaCarousel(viewport, {
                    loop: options.loop,
                    align: 'start',
                    containScroll: 'trimSnaps',
                    // A throw: one slide, a glide that settles on a slide, or free.
                    skipSnaps: options.drag === 'glide',
                    dragFree: options.drag === 'free',
                    // An arrow moves one slide, or everything in view.
                    slidesToScroll: options.step === 'view' ? 'auto' : 1,
                    // Embla's own scale (its default is 25): a longer settle
                    // eases out like Splide's 400 ms rather than braking.
                    duration: { fast: 20, normal: 32, slow: 45 }[options.speed] ?? 32,
                }, plugins);

                // One at a time: the selected slide is THE slide (its entrance
                // plays once it is chosen, not while it slides past). Several
                // in view: every one in view is.
                const single = options.per_view === 1 && options.per_view_tablet === 1 && options.per_view_phone === 1;

                const copied = this.api.slideNodes().length > this.count;
                const sync = () => {
                    const raw = this.api.selectedScrollSnap();
                    // With copies, a stop is its original's: three slides, three dots.
                    this.snaps = copied ? this.count : this.api.scrollSnapList().length;
                    this.selected = copied ? raw % this.count : raw;
                    // Without a loop the arrow at an end has nowhere to go.
                    this.canPrev = this.api.canScrollPrev();
                    this.canNext = this.api.canScrollNext();
                    const inView = this.api.slidesInView();
                    this.api.slideNodes().forEach((slide, i) => {
                        const shown = single || !inView.length ? i === raw : inView.includes(i);
                        slide.classList.toggle('is-selected', shown);
                        slide.inert = !shown;
                        // A video behind a slide plays only while it is the one shown.
                        slide.querySelectorAll('video[data-autoplay]').forEach((video) => {
                            shown && !calm ? video.play().catch(() => {}) : video.pause();
                        });
                    });
                };

                // While the strip moves nothing in it answers the pointer:
                // cards gliding under a still cursor each caught its hover
                // in turn (a picture leaning in, then out) — a twitch per
                // card that read as the strip itself stuttering. The drag
                // still works: Embla listens on the viewport, not the slides.
                // From the first movement, not the press — a press that never
                // moves is a click on a link, and must reach it.
                this.api.on('scroll', () => this.$root.classList.add('is-moving'));
                this.api.on('settle', () => this.$root.classList.remove('is-moving'));

                // Not on `slidesInView`: that fires while the strip moves, and
                // switching `inert` and a class on every slide (eighteen, with
                // the copies) mid-movement made the browser restyle the whole
                // strip between frames — Embla animates on the main thread,
                // so that work showed as small stutters. Once a stop is chosen
                // and once the strip comes to rest is enough.
                this.api.on('select', sync);
                this.api.on('settle', sync);
                this.api.on('reInit', sync);
                this.api.on('scroll', () => {
                    this.progress = copied
                        ? Math.round((this.selected + 1) / this.count * 100)
                        : Math.round(Math.max(0, Math.min(1, this.api.scrollProgress())) * 100);
                });
                sync();

                this.$root.classList.add('is-ready');
                this.ready = true;

                if (autoplay) {
                    this.onVisibility = () => {
                        const player = this.api?.plugins()?.autoplay;
                        if (player) {
                            document.hidden ? player.stop() : player.play();
                        }
                    };
                    document.addEventListener('visibilitychange', this.onVisibility);
                }
            },

            prev() {
                this.api?.scrollPrev();
            },

            next() {
                this.api?.scrollNext();
            },

            // A dot is its original slide; with copies, the nearest one of them.
            go(i) {
                if (!this.api) {
                    return;
                }
                const total = this.api.slideNodes().length;
                const now = this.api.selectedScrollSnap();
                let best = i;
                for (let j = i; j < total; j += this.count) {
                    const way = Math.min(Math.abs(j - now), total - Math.abs(j - now));
                    const bestWay = Math.min(Math.abs(best - now), total - Math.abs(best - now));
                    if (way < bestWay) {
                        best = j;
                    }
                }
                this.api.scrollTo(best);
            },

            /**
             * Copies of the slides appended until the strip is at least four
             * views long (three big slides are only three), so the slide
             * Embla moves across the seam is never one in view. Only for a
             * loop with more than one slide; at most five rounds of copies.
             * Answers how many ORIGINAL slides there are.
             */
            lengthen(viewport, options) {
                const track = viewport.firstElementChild;
                const originals = track ? [...track.children] : [];

                if (!options.loop || originals.length < 2) {
                    return originals.length;
                }

                for (let round = 0; round < 5 && track.scrollWidth < viewport.clientWidth * 4; round++) {
                    originals.forEach((slide) => {
                        const copy = slide.cloneNode(true);
                        copy.setAttribute('aria-hidden', 'true');
                        copy.removeAttribute('aria-label');
                        copy.querySelectorAll('[id]').forEach((node) => node.removeAttribute('id'));
                        track.appendChild(copy);
                    });
                }

                return originals.length;
            },

            destroy() {
                if (this.onVisibility) {
                    document.removeEventListener('visibilitychange', this.onVisibility);
                }
                this.api?.destroy();
            },
        }));
    });
})();
