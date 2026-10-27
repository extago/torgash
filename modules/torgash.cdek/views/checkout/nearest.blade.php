@php
    use Torgash\Cdek\PointType;
    use Torgash\Cdek\Shipping\Notice;

    $kindLabels = [
        PointType::OFFICE => __('pickup point'),
        PointType::LOCKER => __('parcel terminal'),
    ];
@endphp
{{-- CDEK had no point to offer for the town the customer named. Rather than
     vanishing from the list it says so — and WHICH of the three reasons it is,
     because only one of them is answered with somewhere else to collect from.
     Each town is a button that moves the order there, through the same
     `choose-place` event a suggestion from the town box uses. --}}
<div class="border border-base-300 rounded-lg p-3 flex flex-col gap-2 text-sm">
    <div class="opacity-70">
        @if ($reason === Notice::UNKNOWN)
            {{ __('CDEK did not answer about this town just now. Try again in a minute.') }}
        @elseif ($reason === Notice::TOO_HEAVY)
            {{ __('No CDEK point in this town takes this parcel (:kg kg).', ['kg' => $params['kg'] ?? 0]) }}
        @else
            {{ __('CDEK has no pickup point in this town.') }}
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
            {{ __('Choosing one delivers the order to that town — you will pick the exact point after it.') }}
        </div>
    @endif
</div>
