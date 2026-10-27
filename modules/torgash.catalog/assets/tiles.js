/*
 * Category tiles in a row that scrolls (the "Category tiles" block): Alpine's
 * `torgashTiles`. The scrolling itself is the browser's — touch, trackpad and
 * the keyboard need nothing from here. This adds what a mouse lacks and what
 * the eye wants: the thin indicator under the row (its thumb is the share of
 * the row in view, moved to where the view is), arrows that turn a page,
 * dragging the row with the mouse — a drag that moved is not a click, so
 * letting go over a tile does not open it — and the mouse WHEEL turning the
 * row, until it reaches an end: there the wheel scrolls the page again, so
 * a row never traps someone scrolling past it.
 *
 * Loaded as a plain script at the end of the body, before Alpine (deferred)
 * starts — which is what lets it register on `alpine:init`.
 */
(() => {
    if (window.torgashTilesScript) {
        return;
    }
    window.torgashTilesScript = true;

    document.addEventListener('alpine:init', () => {
        window.Alpine.data('torgashTiles', () => ({
            thumb: 100,
            offset: 0,
            canPrev: false,
            canNext: false,

            init() {
                const track = this.$refs.track;

                this.measure();
                this.observer = new ResizeObserver(() => this.measure());
                this.observer.observe(track);

                let startX = 0;
                let startLeft = 0;
                let moved = false;

                // One glide for the wheel and for a drag let go: the row
                // eases towards `target` a fraction of the way each frame,
                // so wheel notches run into one movement instead of 100px
                // jumps. Snapping is off meanwhile — it would pull each
                // frame back to a tile's edge.
                let target = 0;
                let gliding = false;
                const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                const max = () => track.scrollWidth - track.clientWidth;
                const glide = () => {
                    if (!gliding) {
                        return; // a drag took the row over
                    }
                    const left = track.scrollLeft;
                    const step = (target - left) * 0.18;
                    if (Math.abs(target - left) < 0.5 || (Math.abs(step) < 0.5 && Math.round(left) === Math.round(target))) {
                        track.scrollLeft = target;
                        gliding = false;
                        track.classList.remove('is-wheeling');
                        return;
                    }
                    track.scrollLeft = left + (Math.abs(step) < 1 ? Math.sign(step) : step);
                    requestAnimationFrame(glide);
                };
                const glideTo = (left) => {
                    target = Math.max(0, Math.min(max(), left));
                    if (reduced) {
                        track.scrollLeft = target;
                        return;
                    }
                    if (!gliding) {
                        gliding = true;
                        track.classList.add('is-wheeling');
                        requestAnimationFrame(glide);
                    }
                };

                track.addEventListener('pointerdown', (event) => {
                    if (event.pointerType !== 'mouse' || event.button !== 0) {
                        return;
                    }
                    gliding = false;
                    track.classList.remove('is-wheeling');
                    startX = event.clientX;
                    startLeft = track.scrollLeft;
                    moved = false;
                    // The last moments of the drag, for the speed it is let go at.
                    let trail = [];

                    const move = (e) => {
                        const distance = e.clientX - startX;
                        if (!moved && Math.abs(distance) > 5) {
                            moved = true;
                            track.classList.add('is-dragging');
                        }
                        if (moved) {
                            track.scrollLeft = startLeft - distance;
                            trail.push([e.timeStamp, e.clientX]);
                            trail = trail.filter(([t]) => e.timeStamp - t < 100);
                        }
                    };
                    const up = () => {
                        window.removeEventListener('pointermove', move);
                        window.removeEventListener('pointerup', up);
                        track.classList.remove('is-dragging');
                        // Thrown, not dropped: it rolls on about as far as
                        // it would go in a quarter of a second at that speed.
                        if (moved && trail.length > 1) {
                            const [t0, x0] = trail[0];
                            const [t1, x1] = trail[trail.length - 1];
                            const speed = t1 > t0 ? (x1 - x0) / (t1 - t0) : 0;
                            if (Math.abs(speed) > 0.2) {
                                glideTo(track.scrollLeft - speed * 250);
                            }
                        }
                    };

                    window.addEventListener('pointermove', move);
                    window.addEventListener('pointerup', up);
                });

                // A wheel turns the row; a trackpad's sideways swipe (or
                // shift + wheel) is the browser's own already. Snapping is
                // off while it turns — it would pull each small step back.
                track.addEventListener('wheel', (event) => {
                    if (event.ctrlKey || event.shiftKey || Math.abs(event.deltaX) >= Math.abs(event.deltaY)) {
                        return;
                    }
                    // Where the row is heading, not where it is: a notch
                    // given mid-glide adds to the movement already under way.
                    const from = gliding ? target : track.scrollLeft;
                    if (max() <= 1 || (event.deltaY < 0 && from <= 0) || (event.deltaY > 0 && from >= max() - 1)) {
                        return;
                    }
                    event.preventDefault();
                    glideTo(from + event.deltaY * (event.deltaMode === 1 ? 40 : event.deltaMode === 2 ? track.clientWidth : 1) * 1.5);
                }, { passive: false });

                // Capture: stopped before the link sees it.
                track.addEventListener('click', (event) => {
                    if (moved) {
                        event.preventDefault();
                        event.stopPropagation();
                        moved = false;
                    }
                }, true);
            },

            destroy() {
                this.observer?.disconnect();
            },

            measure() {
                const track = this.$refs.track;
                const width = track.clientWidth;
                const total = track.scrollWidth;

                this.thumb = total > width + 1 ? (width / total) * 100 : 100;
                this.offset = width ? (track.scrollLeft / width) * 100 : 0;
                this.canPrev = track.scrollLeft > 1;
                this.canNext = track.scrollLeft + width < total - 1;
            },

            page(direction) {
                const track = this.$refs.track;
                track.scrollBy({ left: direction * track.clientWidth * 0.8, behavior: 'smooth' });
            },
        }));
    });
})();
