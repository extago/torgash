{{-- One pageful of CDEK points. Rendered into the picker by the quote and
     swapped back into it by every search, so the markup is the same both
     times. The buttons live inside the picker's Alpine component, which is why
     they can set `chosen` even though the server put them there. --}}
@forelse ($points as $point)
    @php
        $hours = $point->openingHours();
        $limit = $point->weightLimitKg();
        $limitText = $limit ? __('up to :kg kg', ['kg' => rtrim(rtrim(number_format($limit, 1, '.', ''), '0'), '.')]) : '';
    @endphp
    <button type="button" class="block w-full text-left px-3 py-2 text-sm hover:bg-base-200"
            @click="choose(@js($point->code), @js($point->address ?: $point->name), @js($hours), @js($limitText), @js((string) $point->address_comment))">
        <span class="font-medium">{{ $point->address ?: $point->name }}</span>
        <span class="block text-xs opacity-60">
            <span class="opacity-80">{{ $point->kindLabel() }}</span>
            @if ($point->nearest_metro)
                · {{ $point->nearest_metro }}
            @endif
            @if ($point->hoursToday())
                <span class="opacity-80">· {{ __('today') }} {{ $point->hoursToday() }}</span>
            @endif
        </span>
    </button>
@empty
    <div class="px-3 py-2 text-sm opacity-60">
        {{ $query === '' ? __('No pickup points here.') : __('Nothing matches — try the street or the metro station.') }}
    </div>
@endforelse
