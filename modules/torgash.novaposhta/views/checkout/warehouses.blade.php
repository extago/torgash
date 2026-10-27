{{-- Which branch the parcel is sent to. Rendered under the selected option by
     the checkout form; the value is read back by NovaPoshtaProvider::snapshot()
     and frozen onto the order.

     A searchable list rather than a bare <select>: Kyiv has over a thousand
     branches, and "№ 145" means nothing to a customer who knows the street.
     The page carries the first pageful and the typing is answered by the
     server — shipping a whole city's branches into every quote would be a few
     hundred kilobytes per keystroke in the town field. --}}
@php
    $endpoint = route('np.branches', array_filter([
        'settlement' => $settlement,
        'types' => $types ? implode(',', $types) : null,
        'weight' => $weight ?: null,
    ]));

    // Rendered with a branch already chosen when the form came back carrying
    // one: this whole block is replaced on every re-quote, so state that lived
    // only in the browser would un-select itself in front of the customer.
    $russian = app()->getLocale() === 'ru';
    $limit = $chosen?->weightLimitKg();
    $picked = $chosen ? [
        'ref' => $chosen->ref,
        'name' => $russian ? ($chosen->name_ru ?: $chosen->name_uk) : $chosen->name_uk,
        'hours' => $chosen->openingHours(),
        'limit' => $limit ? __('up to :kg kg', ['kg' => $limit]) : '',
    ] : ['ref' => '', 'name' => '', 'hours' => (object) [], 'limit' => ''];
@endphp

<div x-data="{
        chosen: @js($picked['ref']), chosenName: @js($picked['name']),
        chosenHours: @js($picked['hours']), chosenLimit: @js($picked['limit']),
        choose(ref, name, hours, limit) {
            this.chosen = ref; this.chosenName = name;
            this.chosenHours = hours; this.chosenLimit = limit;
        },
        clear() { this.chosen = ''; this.chosenName = ''; this.chosenHours = {}; this.chosenLimit = ''; },
     }" class="flex flex-col gap-2">
    {{-- Server-rendered value as well as the binding: the form is submittable
         before Alpine has run. --}}
    <input type="hidden" name="shipping_fields[warehouse_ref]" value="{{ $picked['ref'] }}" :value="chosen">

    <div class="flex items-baseline justify-between gap-2">
        <span class="text-xs font-semibold uppercase tracking-wide opacity-50">{{ __('Branch') }}</span>
        <span class="text-xs opacity-50">{{ __(':count in this town', ['count' => $total]) }}</span>
    </div>

    {{-- Why the list is shorter than the town: the carrier states a weight
         limit per point, and every parcel locker's is 20 kg. Without this line
         a heavy basket looks like a shop with one branch in the city. --}}
    @if (($hidden_by_weight ?? 0) > 0)
        <div class="text-xs opacity-60">
            {{ __('Showing the points that take a :kg kg parcel — :hidden more cannot.', [
                'kg' => $parcel_kg ?? 0,
                'hidden' => $hidden_by_weight,
            ]) }}
        </div>
    @endif

    {{-- What was picked, once something is — with the two things a customer
         checks afterwards: when it is open and how heavy a parcel it takes.
         Both come from the button that was clicked, so this costs no request. --}}
    <div class="border border-primary rounded-lg px-3 py-2 text-sm flex flex-col gap-2" x-show="chosen" x-cloak>
        <div class="flex items-center justify-between gap-3">
            <span x-text="chosenName"></span>
            <button type="button" class="btn btn-ghost btn-sm" @click="clear()">{{ __('Change') }}</button>
        </div>

        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-1 text-xs"
             x-show="Object.keys(chosenHours).length || chosenLimit" x-cloak>
            <div x-show="Object.keys(chosenHours).length">
                <span class="opacity-50">{{ __('Opening hours') }}</span>
                <template x-for="(time, days) in chosenHours" :key="days">
                    <span class="block"><span class="opacity-60" x-text="days"></span> <span x-text="time"></span></span>
                </template>
            </div>
            <div x-show="chosenLimit" class="text-right">
                <span class="opacity-50 block">{{ __('Weight') }}</span>
                <span x-text="chosenLimit"></span>
            </div>
        </div>
    </div>

    <div class="flex flex-col gap-2" x-show="! chosen">
        {{-- The name comes from the endpoint that reads it: HTMX sends this
             box's value under its own name, so a mismatch means typing does
             nothing at all and the unfiltered page comes back looking fine. --}}
        {{-- `@change.stop` because the checkout form re-quotes on any `change`
             bubbling out of the shipping block. Clicking a branch blurs this
             box, the browser fires `change`, and without this the quote runs
             again for a search string that has nothing to do with the price —
             a carrier round trip, and a re-render, every time somebody picks. --}}
        <input name="{{ \Torgash\NovaPoshta\Http\BranchesController::QUERY }}"
               class="input input-bordered {{ $fieldSize ?? 'input-sm' }} w-full" autocomplete="off"
               @change.stop
               placeholder="{{ __('Number or street') }}"
               hx-get="{{ $endpoint }}"
               hx-trigger="keyup changed delay:300ms"
               hx-target="#np-branches" hx-swap="innerHTML">

        <div id="np-branches" class="max-h-56 overflow-y-auto border border-base-300 rounded-lg divide-y divide-base-200">
            @include('torgash.novaposhta::checkout.branch-list', [
                'warehouses' => $warehouses,
                'query' => '',
            ])
        </div>
    </div>
</div>
