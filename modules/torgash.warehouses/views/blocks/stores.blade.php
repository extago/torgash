{{--
    "Our stores" (the footer, the owner's design): every place a customer may
    be told about (`Warehouse::public()`), grouped by town — "Stores in
    Moscow" — with the address and the opening hours. Read from the
    warehouses themselves, so a shop opened on the warehouses screen appears
    here without anybody editing the footer.

    The town's name comes in the case the heading needs from whoever keeps
    towns (`locations.place.forms`); without a towns module the groups simply
    have no heading.
--}}
@php
    $storesAll = \Torgash\Warehouses\Models\Warehouse::query()->public()->orderBy('sort')->orderBy('id')->get();
    $storesHeading = trim((string) ($settings['heading'] ?? ''));
    $storesByTown = $storesAll->groupBy(fn ($warehouse) => (int) $warehouse->place_id);
    $storesTownIds = $storesByTown->keys()->filter()->values()->all();
    $storesTowns = $storesTownIds === [] ? [] : (array) hook_filter('locations.place.forms', [], [
        'ids' => $storesTownIds,
        'case' => app()->getLocale() === 'en' ? 1 : 6,
    ]);
@endphp
@if ($storesAll->isNotEmpty())
    <div class="torgash-block torgash-block-stores @container">
        {{-- Cells as wide as an address needs, as many as fit: six fixed columns
             cut every address into three lines and left the row's right half
             empty with only two stores. --}}
        <div class="grid gap-x-8 gap-y-8 grid-cols-[repeat(auto-fill,minmax(15rem,1fr))]">
            @foreach ($storesByTown as $storesTownId => $storesInTown)
                @foreach ($storesInTown as $storesIndex => $store)
                    <div class="flex flex-col gap-2">
                        {{-- The town's heading over its first store; the row
                             keeps its height under the others so they line up. --}}
                        <div class="text-xs opacity-60 min-h-4">
                            @if ($storesIndex === 0 && isset($storesTowns[$storesTownId]))
                                {{ $storesHeading !== '' ? str_replace(':city', $storesTowns[$storesTownId], $storesHeading) : $storesTowns[$storesTownId] }}
                            @endif
                        </div>
                        {{-- To the map: the address is what a visitor wants to find. --}}
                        @php $storesMap = $store->address ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($store->address) : null; @endphp
                        <{{ $storesMap ? 'a' : 'div' }} @if ($storesMap) href="{{ $storesMap }}" target="_blank" rel="noopener" @endif class="flex gap-3 group">
                            <span class="w-8 h-8 shrink-0 rounded-lg bg-current/10 grid place-items-center group-hover:text-primary"><x-icon name="store" size="sm" class="opacity-70" /></span>
                            <div class="flex flex-col gap-1 text-sm min-w-0">
                                <span class="font-semibold group-hover:text-primary">{{ $store->t('name') }}</span>
                                @if ($store->address)
                                    <span class="opacity-90">{{ $store->address }}</span>
                                @endif
                                @foreach ($store->hoursSummary() as $storesHours)
                                    <span class="opacity-60">{{ $storesHours['days'] }}: {{ $storesHours['time'] }}</span>
                                @endforeach
                            </div>
                        </{{ $storesMap ? 'a' : 'div' }}>
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>
@endif
