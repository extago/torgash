@if ($rows === [])
    <p class="text-sm opacity-60 py-4 text-center">{{ __('Nothing sold in 30 days.') }}</p>
@else
    @php $topQty = max(1, max(array_map(fn ($r) => (int) $r->qty, $rows))); @endphp
    <div class="flex flex-col gap-2">
        @foreach ($rows as $row)
            <div class="text-sm" title="{{ admin_price((int) $row->revenue) }}">
                <div class="flex items-baseline gap-2">
                    @can('catalog.manage')
                        <a href="{{ route('admin.catalog.products.edit', $row->product_id) }}" class="grow truncate link link-hover">{{ $row->name }}</a>
                    @else
                        <span class="grow truncate">{{ $row->name }}</span>
                    @endcan
                    <span class="tabular-nums text-xs opacity-70 whitespace-nowrap">{{ $row->qty }} {{ __('pcs') }}</span>
                </div>
                <div class="h-1.5 rounded-full bg-base-200 mt-1 overflow-hidden">
                    <div class="h-full rounded-full bg-primary" style="width: {{ round($row->qty / $topQty * 100) }}%"></div>
                </div>
            </div>
        @endforeach
    </div>
@endif
