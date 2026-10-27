{{-- Which CDEK point the parcel goes to. Rendered under the selected option by
     the checkout form; the value is read back by CdekProvider::snapshot() and
     frozen onto the order.

     A searchable list rather than a bare <select>: a big city has hundreds of
     points, and a code like "MSK123" means nothing to a customer who knows the
     street. The page carries the first pageful and the typing is answered by
     the server. --}}
@php
    $endpoint = route('cdek.points', array_filter([
        'city' => $city,
        'types' => $types ? implode(',', $types) : null,
        'weight' => $weight ?: null,
        'volume' => $volume ?: null,
    ]));

    // Rendered with a point already chosen when the form came back carrying
    // one: this whole block is replaced on every re-quote.
    $limit = $chosen?->weightLimitKg();
    $picked = $chosen ? [
        'code' => $chosen->code,
        'name' => $chosen->address ?: $chosen->name,
        'hours' => $chosen->openingHours(),
        'limit' => $limit ? __('up to :kg kg', ['kg' => rtrim(rtrim(number_format($limit, 1, '.', ''), '0'), '.')]) : '',
        'note' => (string) $chosen->address_comment,
    ] : ['code' => '', 'name' => '', 'hours' => (object) [], 'limit' => '', 'note' => ''];
@endphp

<div x-data="{
        chosen: @js($picked['code']), chosenName: @js($picked['name']),
        chosenHours: @js($picked['hours']), chosenLimit: @js($picked['limit']), chosenNote: @js($picked['note']),
        choose(code, name, hours, limit, note) {
            this.chosen = code; this.chosenName = name;
            this.chosenHours = hours; this.chosenLimit = limit; this.chosenNote = note;
        },
        clear() { this.chosen = ''; this.chosenName = ''; this.chosenHours = {}; this.chosenLimit = ''; this.chosenNote = ''; },
     }" class="flex flex-col gap-2">
    {{-- Server-rendered value as well as the binding: the form is submittable
         before Alpine has run. --}}
    <input type="hidden" name="shipping_fields[point_code]" value="{{ $picked['code'] }}" :value="chosen">

    <div class="flex items-baseline justify-between gap-2">
        <span class="text-xs font-semibold uppercase tracking-wide opacity-50">{{ __('Pickup point') }}</span>
        <span class="text-xs opacity-50">{{ __(':count in this town', ['count' => $total]) }}</span>
    </div>

    {{-- Why the list is shorter than the town: points state a weight limit,
         lockers a cell size. --}}
    @if (($hidden_by_size ?? 0) > 0)
        <div class="text-xs opacity-60">
            {{ __('Showing the points that take this parcel — :hidden more cannot (weight or size).', ['hidden' => $hidden_by_size]) }}
        </div>
    @endif

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

        {{-- "Entrance from the yard, second floor" — the carrier's own
             directions, which is what actually gets a customer to the door. --}}
        <div class="text-xs opacity-60" x-show="chosenNote" x-text="chosenNote" x-cloak></div>
    </div>

    <div class="flex flex-col gap-2" x-show="! chosen">
        {{-- `@change.stop`: the checkout form re-quotes on any `change` that
             bubbles out of the shipping block, and a search string has nothing
             to do with the price. --}}
        <input name="{{ \Torgash\Cdek\Http\PointsController::QUERY }}"
               class="input input-bordered {{ $fieldSize ?? 'input-sm' }} w-full" autocomplete="off"
               @change.stop
               placeholder="{{ __('Street or metro station') }}"
               hx-get="{{ $endpoint }}"
               hx-trigger="keyup changed delay:300ms"
               hx-target="#cdek-points" hx-swap="innerHTML">

        <div id="cdek-points" class="max-h-56 overflow-y-auto border border-base-300 rounded-lg divide-y divide-base-200">
            @include('torgash.cdek::checkout.point-list', [
                'points' => $points,
                'query' => '',
            ])
        </div>
    </div>
</div>
