{{-- A number with − and + beside it: how many of an answer. One drawing for
     the "how many power supplies" list and for a row you add, so the two
     cannot come to look different.

     stepUp()/stepDown() keep to the input's own min/max, so the bounds live
     in one place — the attributes the server already checks against — and
     the button fires the `change` the price recalculation listens for. Plain
     JavaScript and not Alpine: this view is swapped in by htmx inside the
     buy box's own x-data, and needs no state of its own.

     Variables are named distinctively on purpose: an included view inherits the
     caller's whole scope (docs/LESSONS.md). --}}
<div class="inline-flex items-center shrink-0 rounded-field border border-base-300 bg-base-100">
    <button type="button" class="btn btn-sm btn-touch btn-square btn-ghost border-0 shadow-none"
            aria-label="{{ __('Decrease quantity') }}"
            x-data x-on:click="const i = $el.parentElement.querySelector('input'); i.stepDown(); i.dispatchEvent(new Event('change', { bubbles: true }));">
        <x-icon name="minus" size="sm" />
    </button>
    <input type="number" name="{{ $stepperName }}" value="{{ $stepperValue }}"
           min="{{ $stepperMin }}" max="{{ $stepperMax }}" step="1"
           class="torgash-qty w-10 bg-transparent border-0 p-0 text-center text-sm tabular-nums focus:outline-none"
           aria-label="{{ __('Quantity') }}">
    <button type="button" class="btn btn-sm btn-touch btn-square btn-ghost border-0 shadow-none"
            aria-label="{{ __('Increase quantity') }}"
            x-data x-on:click="const i = $el.parentElement.querySelector('input'); i.stepUp(); i.dispatchEvent(new Event('change', { bubbles: true }));">
        <x-icon name="plus" size="sm" />
    </button>
</div>
