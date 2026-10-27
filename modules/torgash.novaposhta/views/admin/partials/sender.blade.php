{{-- The town parcels leave from. A UUID is not something to type, so it is
     picked from the carrier's own city list; the whole block swaps itself. --}}
<div id="np-sender" class="flex flex-col gap-2">
    <div class="opacity-50 text-xs uppercase tracking-wide">{{ __('Sender city') }}</div>

    @if ($sender)
        <div class="flex items-center gap-2 text-sm">
            <span class="font-medium">{{ $sender->name_uk }}</span>
            <span class="opacity-60">{{ $sender->area }}</span>
            <button type="button" class="btn btn-ghost btn-sm"
                    hx-post="{{ route('admin.nova-poshta.sender.set') }}"
                    hx-vals='{"settlement_ref": ""}'
                    hx-target="#np-sender" hx-swap="outerHTML">
                {{ __('Change') }}
            </button>
        </div>
    @else
        <input name="q" value="{{ $query }}" class="input input-bordered input-sm w-full"
               placeholder="{{ __('Where do you ship from?') }}"
               hx-get="{{ route('admin.nova-poshta.sender.search') }}"
               hx-trigger="keyup changed delay:400ms"
               hx-target="#np-sender" hx-swap="outerHTML">

        @if ($matches->isNotEmpty())
            <div class="border border-base-300 rounded-lg divide-y divide-base-200 max-h-40 overflow-y-auto">
                @foreach ($matches as $match)
                    <button type="button" class="block w-full text-left px-3 py-1.5 text-sm hover:bg-base-200"
                            hx-post="{{ route('admin.nova-poshta.sender.set') }}"
                            hx-vals='{"settlement_ref": "{{ $match->ref }}"}'
                            hx-target="#np-sender" hx-swap="outerHTML">
                        {{ $match->name_uk }}
                        <span class="opacity-60">{{ $match->area }}</span>
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
