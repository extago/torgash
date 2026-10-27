{{--
    One 3D model in the gallery, as the slide's player: kept inert in the
    gallery's <template> until the visitor presses the slide, then cloned in
    (product-gallery.blade.php, play()). The gallery announces that with
    `torgash:gallery-play`, which is when the viewer's script is fetched —
    a page whose model nobody opens never downloads it.

    The controls stand at the right edge, away from the gallery's "1/5" in
    the bottom left corner and the product's labels in the top left: the ✕
    gives the strip back (inside the viewer a sideways swipe turns the model,
    so on a phone, with no thumbnails, it is the way out); below it the
    turning can be stopped, the view zoomed or put back as it opened, and the
    frame taken to the whole screen. Where a page cannot ask for the whole
    screen (an iPhone grants it to videos alone) the frame covers the window
    instead, which looks the same.

    Variables are named distinctively on purpose: an included view inherits
    the caller's whole scope (docs/LESSONS.md).
--}}
@php $viewerRotates = (bool) setting('torgash.model3d', 'auto_rotate'); @endphp
<div x-data="torgashModel3d(@js($viewerRotates))" x-on:keydown.escape.window="covering && leave()"
     :class="covering ? 'fixed inset-0 z-[100]' : 'absolute inset-0'"
     class="torgash-model3d-frame bg-base-100">
    <model-viewer x-ref="viewer" src="{{ $viewerModel->url() }}" alt="{{ $viewerTitle }}"
                  @if ($viewerPoster) poster="{{ $viewerPoster }}" @endif
                  camera-controls touch-action="pan-y" interaction-prompt="auto" shadow-intensity="1" exposure="1"
                  @if ($viewerRotates) auto-rotate auto-rotate-delay="0" @endif rotation-per-second="20deg"
                  @if (setting('torgash.model3d', 'ar')) ar ar-modes="webxr scene-viewer quick-look" @endif
                  class="torgash-model3d h-full w-full">
        @if (setting('torgash.model3d', 'ar'))
            {{-- Centred at the bottom: the gallery's count is in the left corner.
                 The viewer hides it by itself where the device cannot. --}}
            <button slot="ar-button" type="button" class="btn btn-sm absolute left-1/2 -translate-x-1/2 bottom-3 gap-1.5 shadow whitespace-nowrap">
                <x-icon name="scan-eye" size="sm" /> {{ __('View in your room') }}
            </button>
        @endif
    </model-viewer>

    <button type="button" x-on:click.stop="close()" class="btn btn-sm btn-square absolute right-2 top-2 z-10 bg-base-100/80 shadow-sm"
            aria-label="{{ __('Close the 3D view') }}" title="{{ __('Close the 3D view') }}">
        <x-icon name="x" />
    </button>

    <div class="absolute right-2 bottom-2 z-10 flex flex-col rounded-field bg-base-100/80 shadow-sm overflow-hidden" role="toolbar" aria-label="{{ __('3D view') }}">
        <button type="button" x-on:click.stop="toggleTurning()" class="btn btn-sm btn-square btn-ghost rounded-none"
                :aria-label="turning ? @js(__('Stop turning')) : @js(__('Turn by itself'))" :title="turning ? @js(__('Stop turning')) : @js(__('Turn by itself'))">
            <x-icon name="pause" x-show="turning" />
            <x-icon name="play" x-show="!turning" x-cloak />
        </button>
        <button type="button" x-on:click.stop="zoom(1)" class="btn btn-sm btn-square btn-ghost rounded-none" aria-label="{{ __('Zoom in') }}" title="{{ __('Zoom in') }}">
            <x-icon name="zoom-in" />
        </button>
        <button type="button" x-on:click.stop="zoom(-1)" class="btn btn-sm btn-square btn-ghost rounded-none" aria-label="{{ __('Zoom out') }}" title="{{ __('Zoom out') }}">
            <x-icon name="zoom-out" />
        </button>
        <button type="button" x-on:click.stop="reset()" class="btn btn-sm btn-square btn-ghost rounded-none" aria-label="{{ __('Back to the first view') }}" title="{{ __('Back to the first view') }}">
            <x-icon name="rotate-ccw" />
        </button>
        <button type="button" x-on:click.stop="toggleFull()" class="btn btn-sm btn-square btn-ghost rounded-none"
                :aria-label="full ? @js(__('Exit full screen')) : @js(__('Full screen'))" :title="full ? @js(__('Exit full screen')) : @js(__('Full screen'))">
            <x-icon name="maximize" x-show="!full" />
            <x-icon name="minimize" x-show="full" x-cloak />
        </button>
    </div>
</div>

@once
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            // The viewer (model-viewer, Apache-2.0, vendored in this module)
            // is fetched the first time a model is opened, and never before.
            document.addEventListener('torgash:gallery-play', (event) => {
                if (!event.target.querySelector('model-viewer') || customElements.get('model-viewer')) return;
                import(@js(module_asset('torgash.model3d', 'vendor/model-viewer/model-viewer.min.js')));
            });

            document.addEventListener('alpine:init', () => {
                Alpine.data('torgashModel3d', (rotates) => ({
                    turning: rotates,
                    // `full`: the frame has the whole screen, by the browser
                    // (`document.fullscreenElement`) or, where a page may not
                    // ask for that, by covering the window (`covering`).
                    full: false,
                    covering: false,

                    init() {
                        this.onFullscreen = () => { this.full = document.fullscreenElement === this.$root || this.covering; };
                        document.addEventListener('fullscreenchange', this.onFullscreen);
                    },

                    destroy() {
                        document.removeEventListener('fullscreenchange', this.onFullscreen);
                        if (this.covering) document.documentElement.style.overflow = '';
                    },

                    toggleTurning() {
                        this.turning = !this.turning;
                        this.$refs.viewer.autoRotate = this.turning;
                    },

                    // A quarter nearer or farther a press — the viewer's own
                    // zoom() takes a wheel's notch, too small to see. Its
                    // limits still hold: an orbit past them is clamped.
                    zoom(direction) {
                        const viewer = this.$refs.viewer;
                        const orbit = viewer.getCameraOrbit?.();
                        if (!orbit) return;
                        const radius = orbit.radius * (direction > 0 ? 0.75 : 1 / 0.75);
                        viewer.cameraOrbit = `${orbit.theta}rad ${orbit.phi}rad ${radius}m`;
                    },

                    // An orbit equal to the one set is no change to the
                    // element, so the first view is asked for with a hair's
                    // difference each time it is pressed. A user's drag
                    // never writes the attribute; only zoom() above does.
                    reset() {
                        const viewer = this.$refs.viewer;
                        this.flip = !this.flip;
                        viewer.cameraOrbit = this.flip ? '0deg 75deg 105%' : '0deg 75deg 105.001%';
                        viewer.cameraTarget = 'auto auto auto';
                        viewer.fieldOfView = 'auto';
                        viewer.resetTurntableRotation?.(0);
                    },

                    toggleFull() {
                        this.full ? this.leave() : this.enter();
                    },

                    // $root, never $el: called from a button's x-on:click,
                    // $el is that BUTTON — the browser gave a 36px button the
                    // whole screen and drew it black around nothing.
                    enter() {
                        if (document.fullscreenEnabled && this.$root.requestFullscreen) {
                            this.$root.requestFullscreen().catch(() => this.cover(true));
                        } else {
                            this.cover(true);
                        }
                    },

                    leave() {
                        if (document.fullscreenElement === this.$root) document.exitFullscreen();
                        this.cover(false);
                    },

                    cover(on) {
                        this.covering = on;
                        this.full = on || document.fullscreenElement === this.$root;
                        document.documentElement.style.overflow = on ? 'hidden' : '';
                    },

                    close() {
                        this.leave();
                        this.stop();
                    },
                }));
            });
        </script>
    @endpush
@endonce
