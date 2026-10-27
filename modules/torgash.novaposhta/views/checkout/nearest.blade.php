@php
    use Torgash\NovaPoshta\Shipping\Notice;

    // What each town has, in the customer's own words. `low_stock` has no
    // equivalent here: a category is a fact about the point, not a nuance.
    $kindLabels = [
        'branch' => __('branch'),
        'locker' => __('parcel locker'),
        'cargo' => __('cargo department'),
    ];
@endphp
{{-- Nova Poshta had no pickup point to offer for the town the customer named.
     Rather than vanishing from the list it says so — and says WHICH of the
     three reasons it is, because only one of them is answered with somewhere
     else to collect from. Where it is, each town is a button that moves the
     order there, through the same `choose-place` event a suggestion from the
     town box uses. --}}
<div class="border border-base-300 rounded-lg p-3 flex flex-col gap-2 text-sm">
    <div class="opacity-70">
        @if ($reason === Notice::UNKNOWN)
            {{ __('Nova Poshta did not answer about this town just now. Try again in a minute.') }}
        @elseif ($reason === Notice::TOO_HEAVY)
            {{ __('No Nova Poshta point in this town takes a :kg kg parcel.', ['kg' => $params['kg'] ?? 0]) }}
        @else
            {{ __('Nova Poshta has no branch or parcel locker in this town.') }}
        @endif

        @if ($towns !== [])
            {{ __('The nearest ones:') }}
        @endif
    </div>

    @if ($towns !== [])
        <div class="flex flex-col gap-1">
            @foreach ($towns as $town)
                <button type="button"
                        class="flex items-baseline justify-between gap-3 text-start rounded px-2 py-1.5 hover:bg-base-200"
                        x-data x-on:click="window.dispatchEvent(new CustomEvent('choose-place', { detail: @js([
                            'id' => $town['place_id'],
                            'label' => $town['name'],
                            'name' => $town['name'],
                        ]) }))">
                    <span>
                        {{ $town['name'] }}
                        @if ($town['kinds'] !== [])
                            <span class="block text-xs opacity-60">
                                {{ implode(', ', array_map(fn ($kind) => $kindLabels[$kind] ?? $kind, $town['kinds'])) }}
                            </span>
                        @endif
                    </span>
                    <span class="whitespace-nowrap opacity-70">
                        {{ __(':km km', ['km' => $town['km'] < 10 ? number_format($town['km'], 1, '.', '') : round($town['km'])]) }}
                    </span>
                </button>
            @endforeach
        </div>

        <div class="text-xs opacity-50">
            {{ __('Choosing one delivers the order to that town — you will pick the exact branch after it.') }}
        </div>
    @endif
</div>
