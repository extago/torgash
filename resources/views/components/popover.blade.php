{{--
    A small panel that opens from a button — the browser's own Popover API,
    placed against its button by CSS Anchor Positioning (Baseline since
    January 2026), with no library. The top layer means no z-index fights;
    a click outside or Esc closes it; it flips above the button when there
    is no room below.

        x-popover (class = the trigger's, panel-class = the panel's)
            …what the panel holds…
            x-slot:trigger — what the button shows

    The trigger is a SLOT written AFTER the content on purpose: a named slot
    before a component's default content leaves an output buffer open
    (CLAUDE.md, Blade gotchas).

    `hover` opens it on hover and focus as well — for a hint, never for a
    panel of buttons. It is the ONLY hint on the storefront: no daisyUI
    `tooltip`, no hand-made panel, and `title` only as the spoken name of an
    icon button (a phone never shows a title). With no panel-class a hint
    takes `.torgash-hint` (app.css), so every hint reads the same. The browser's own hover trigger (`interestfor`) is
    Chrome-only for now; popover-anchor.js stands in until it is not.

    A browser without anchor positioning (iOS 18 and older) still opens it;
    /assets/js/popover-anchor.js only moves it next to its button there, and
    does nothing where the browser can.

    Inside a dark bar (`.torgash-bar`) the panel takes the page's palette back
    (`torgash-bar-panel`), like every dropdown hanging off a bar.
--}}
@props([
    'id' => null,
    'placement' => 'bottom',
    'panelClass' => '',
    'label' => null,
    // Opens on hover and focus too (a hint — a payment mark's name), at
    // once; a touch still opens it with a tap. A panel with BUTTONS
    // stays click-only: one opened by hover closes on the way to them.
    'hover' => false,
    // `start` / `end`: hang the panel from the button's own edge rather than
    // centring it on the button (app.css, `data-align`) — for a button at the
    // edge of what it belongs to, the heading's hint.
    'align' => null,
])
@php
    $popoverId = $id ?: 'popover-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10));
@endphp
<button type="button" popovertarget="{{ $popoverId }}" style="anchor-name: --{{ $popoverId }}"
        @if ($label) aria-label="{{ $label }}" @endif
        @if ($hover) data-popover-hover @endif
        {{ $attributes->merge(['class' => 'cursor-pointer text-left']) }}>{{ $trigger }}</button>
<div id="{{ $popoverId }}" popover style="position-anchor: --{{ $popoverId }}"
     data-placement="{{ $placement === 'top' ? 'top' : 'bottom' }}"
     @if (in_array($align, ['start', 'end'], true)) data-align="{{ $align }}" @endif
     @class([
         'torgash-popover torgash-bar-panel bg-base-100 text-base-content border border-base-300',
         // Both are centred on their button (app.css); a hint is just
         // smaller and lighter — and, unless its owner says otherwise, the
         // storefront's ONE look of a hint: a few lines of small text.
         'rounded-md shadow-md' => $hover,
         'rounded-box shadow-lg' => ! $hover,
         'torgash-hint' => $hover && $panelClass === '',
         $panelClass,
     ])>
    {{ $slot }}
</div>

{{-- Keyed 'popover-anchor': the storefront layout prints the script itself
     unless something on the page already pushed it — one tag a page.
     Versioned by the file's own date, so a changed script is never served from cache. --}}
@pushOnce('scripts', 'popover-anchor')
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/popover-anchor.js') }}" defer></script>
@endPushOnce
