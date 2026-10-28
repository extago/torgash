{{-- A long block of the product page folded (PLAN §38): the first
     `$foldHeight` px, a fade and a button. Shared by the description and by
     whatever other module stands in its place (a landing), so they fold alike.

     Measured in the browser, so a short block never gets a button — nor one
     that would hide a line or two (the 15% slack) — and measured again
     whenever it changes size: a landing is mostly pictures, and before they
     arrive it is short. With no script the whole block is simply there
     (the fold is only ever applied by Alpine).

     Opening keeps the block's top where it was: left alone, the browser's
     scroll anchoring holds the BUTTON in view, which threw the reader to the
     end of a six-thousand-pixel landing — so anchoring is off for the moment
     of the switch. Folding back from far below brings the top
     into view, or the reader is left a screenful of somewhere else.

     Something inside that is about to show a part below the fold — a
     landing's "#…" link — sends `torgash:unfold` (a DOM event that bubbles),
     and a folded block opens.

     `$foldHtml` (printed raw — clean it first), `$foldHeight`, `$foldMore`, `$foldLess`. --}}
<div x-data="{
        open: false,
        long: false,
        measure() { this.long = this.$refs.foldBody.scrollHeight > {{ (int) round($foldHeight * 1.15) }} },
        toggle() {
            const page = document.documentElement;
            page.style.overflowAnchor = 'none';
            this.open = ! this.open;
            this.$nextTick(() => {
                // Instant, under the sticky header: the page scrolls smoothly,
                // and a smooth trip back up a landing is a long one.
                const top = this.$root.getBoundingClientRect().top;
                if (! this.open && top < 0) {
                    window.scrollTo({ top: window.scrollY + top - 120, behavior: 'instant' });
                }
                requestAnimationFrame(() => requestAnimationFrame(() => page.style.overflowAnchor = ''));
            });
        },
     }"
     x-init="measure(); new ResizeObserver(() => measure()).observe($refs.foldBody); $el.addEventListener('torgash:unfold', () => { if (long && ! open) toggle() })">
    <div class="relative" :class="long && ! open && 'overflow-hidden'" :style="long && ! open ? 'max-height: {{ (int) $foldHeight }}px' : ''">
        <div x-ref="foldBody">{!! $foldHtml !!}</div>
        <div x-show="long && ! open" x-cloak class="absolute inset-x-0 bottom-0 h-24 bg-gradient-to-t from-base-100 to-transparent pointer-events-none"></div>
    </div>
    <button type="button" x-show="long" x-cloak x-on:click="toggle()"
            class="btn btn-outline btn-sm btn-touch w-full mt-3 gap-1.5">
        <span x-text="open ? @js($foldLess) : @js($foldMore)"></span>
        <span :class="open && 'rotate-180'" class="transition-transform"><x-icon name="chevron-down" size="sm" /></span>
    </button>
</div>
