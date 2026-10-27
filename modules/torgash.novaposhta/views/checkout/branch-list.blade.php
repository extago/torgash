{{-- One pageful of branches. Rendered into the picker by the quote and swapped
     back into it by every search, so the markup is the same both times.

     The buttons live inside the picker's Alpine component, which is why they
     can set `chosen` even though the server put them there: Alpine initialises
     nodes HTMX swaps in, within the scope they land in.

     Each button carries its branch's hours and weight limit, so choosing one
     shows them with no second request: the details are three short strings on
     a row we are already rendering, and a page of them is cheaper than one
     round trip. --}}
@forelse ($warehouses as $warehouse)
    @php
        // The carrier gives every branch a Ukrainian and a Russian name. A
        // Russian-language storefront gets the Russian one; everyone else gets
        // the Ukrainian one, because that is the official address on the
        // parcel — not because the shop happens to be browsing in English.
        $russian = app()->getLocale() === 'ru';
        $name = $russian ? ($warehouse->name_ru ?: $warehouse->name_uk) : $warehouse->name_uk;
        $short = $russian ? ($warehouse->short_ru ?: $warehouse->short_uk) : $warehouse->short_uk;
        $hours = $warehouse->openingHours();
        $limit = $warehouse->weightLimitKg();
    @endphp
    <button type="button" class="block w-full text-left px-3 py-2 text-sm hover:bg-base-200"
            @click="choose(@js($warehouse->ref), @js($name), @js($hours), @js($limit ? __('up to :kg kg', ['kg' => $limit]) : ''))">
        <span class="font-medium">{{ $name }}</span>
        <span class="block text-xs opacity-60">
            {{-- The kind matters when the list mixes branches and lockers — one
                 method can offer both, since a locker is a kind of pickup
                 point, not a separate delivery. --}}
            <span class="opacity-80">{{ $warehouse->kindLabel() }}</span> ·
            {{ $short }}
            {{-- Today's hours: the thing that actually decides between two
                 branches on the same street. As of the last sync — schedules
                 change, and pretending otherwise would be worse than saying
                 nothing only if we never refreshed. --}}
            @if ($warehouse->hoursToday())
                <span class="opacity-80">· {{ __('today') }} {{ $warehouse->hoursToday() }}</span>
            @endif
        </span>
    </button>
@empty
    <div class="px-3 py-2 text-sm opacity-60">
        {{ $query === '' ? __('No branches here.') : __('Nothing matches — try the branch number.') }}
    </div>
@endforelse
