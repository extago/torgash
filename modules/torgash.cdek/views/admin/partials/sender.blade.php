{{-- The city parcels leave from. A numeric code is not something to type, so
     it is picked from the carrier's own city list; the block swaps itself. --}}
<div id="cdek-sender" class="flex flex-col gap-2">
    <div class="opacity-50 text-xs uppercase tracking-wide">{{ __('Sender city') }}</div>

    @if ($sender)
        <div class="flex items-center gap-2 text-sm">
            <span class="font-medium">{{ $sender->name }}</span>
            <span class="opacity-60">{{ $sender->region }}</span>
            <button type="button" class="btn btn-ghost btn-sm"
                    hx-post="{{ route('admin.cdek.sender.set') }}"
                    hx-vals='{"city_code": 0}'
                    hx-target="#cdek-sender" hx-swap="outerHTML">
                {{ __('Change') }}
            </button>
        </div>
    @else
        <input name="q" value="{{ $query }}" class="input input-bordered input-sm w-full"
               placeholder="{{ __('Where do you ship from?') }}"
               hx-get="{{ route('admin.cdek.sender.search') }}"
               hx-trigger="keyup changed delay:400ms"
               hx-target="#cdek-sender" hx-swap="outerHTML">

        @if ($matches->isNotEmpty())
            <div class="border border-base-300 rounded-lg divide-y divide-base-200 max-h-40 overflow-y-auto">
                @foreach ($matches as $match)
                    <button type="button" class="block w-full text-left px-3 py-1.5 text-sm hover:bg-base-200"
                            hx-post="{{ route('admin.cdek.sender.set') }}"
                            hx-vals='{"city_code": {{ (int) $match->code }}}'
                            hx-target="#cdek-sender" hx-swap="outerHTML">
                        {{ $match->name }}
                        <span class="opacity-60">{{ implode(', ', array_filter([$match->sub_region, $match->region])) }}</span>
                    </button>
                @endforeach
            </div>
        @elseif ($query !== '')
            <div class="text-xs opacity-60">{{ __('Nothing matches.') }}</div>
        @endif

        <div class="text-xs opacity-50">
            {{ __('Without it the carrier is never asked for a price, and its methods are priced by your own rate table.') }}
        </div>
    @endif
</div>
