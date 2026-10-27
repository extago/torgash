{{-- "Your shop", in the header beside the search — where every shop with
     branches puts it, because the answer changes what the whole catalogue
     means.

     Grouped by town, and the visitor's own town first: they picked it in the
     cart or by choosing a shop in it, and scrolling past four other cities to
     find their own is the thing that makes people ignore this control.

     Each point is its own POST form rather than a link: choosing changes what
     the next page shows, and a link that changes state is a link a crawler
     follows. --}}
@php
    $grouped = $points->groupBy('place_id');

    // The visitor's town leads; the rest keep the owner's own order.
    $order = $grouped->keys()
        ->sortBy(fn ($placeId) => [$placeId && (int) $placeId === (int) $here ? 0 : 1, $towns[$placeId] ?? ''])
        ->values();
@endphp

{{-- A popover (core's component), like every small menu off a button; it
     takes the page palette back inside the dark bar by itself. --}}
<div class="shrink-0">
    <x-popover class="btn btn-ghost btn-sm gap-1.5 normal-case max-w-[12rem]" panel-class="w-80 max-h-96 overflow-y-auto p-2" :label="__('Choose a shop')">
        <div class="px-2 py-1 text-xs opacity-60">
            {{ __('Pick where you will collect your orders — the catalogue then says what is on that shelf.') }}
        </div>

        @foreach ($order as $placeId)
            @if ($placeId && ! empty($towns[$placeId]))
                <div class="px-2 pt-2 text-xs font-semibold uppercase tracking-wide opacity-50">{{ $towns[$placeId] }}</div>
            @endif

            @foreach ($grouped[$placeId] as $point)
                <form method="POST" action="{{ route('warehouses.point.choose') }}">
                    @csrf
                    <input type="hidden" name="point_id" value="{{ $point->id }}">
                    <button type="submit" @class([
                        'block w-full text-left px-2 py-2 rounded-btn hover:bg-base-200 text-sm',
                        'bg-base-200' => $chosen?->id === $point->id,
                    ])>
                        <span class="font-medium block">{{ $point->t('name') }}</span>
                        @if ($point->address)
                            <span class="opacity-60 text-xs block">{{ $point->address }}</span>
                        @endif
                        @foreach ($point->hoursSummary() as $line)
                            <span class="opacity-50 text-xs mr-3">{{ $line['days'] }} {{ $line['time'] }}</span>
                        @endforeach
                    </button>
                </form>
            @endforeach
        @endforeach

        @if ($chosen)
            <form method="POST" action="{{ route('warehouses.point.forget') }}" class="border-t border-base-300 mt-2 pt-2">
                @csrf @method('DELETE')
                <button type="submit" class="block w-full text-left px-2 py-1 text-xs opacity-60 hover:opacity-100">
                    {{ __('Show the whole catalogue again') }}
                </button>
            </form>
        @endif

        <x-slot:trigger>
            <x-icon name="store" />
            <span class="hidden lg:inline truncate">
                {{ $chosen ? $chosen->t('name') : __('Choose a shop') }}
            </span>
            <x-icon name="chevron-down" size="sm" class="opacity-50" />
        </x-slot:trigger>
    </x-popover>
</div>
