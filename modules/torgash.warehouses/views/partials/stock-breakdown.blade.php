{{--
    Rendered under the (now read-only) Stock field via catalog.product.stock_extra
    — only reached when the product is split, see catalog.product.stock_locked.
--}}
@php
    $rows = \Illuminate\Support\Facades\DB::table('m_warehouse_product_stock as s')
        ->join('m_warehouses as w', 'w.id', '=', 's.warehouse_id')
        ->where('s.product_id', $product->id)
        ->orderBy('w.sort')
        ->get(['w.id', 'w.name', 's.qty']);
    $locale = admin_content_locale();
@endphp
<div class="rounded-box bg-base-200/60 border border-base-300 p-3 flex flex-col gap-1.5 -mt-1">
    <div class="text-xs opacity-60">{{ __('Split across warehouses — edit quantities there:') }}</div>
    <div class="flex flex-col divide-y divide-base-300">
        @foreach ($rows as $row)
            <div class="flex justify-between gap-4 py-1 text-sm">
                <span>{{ pick_translation($row->name, $locale) ?? '—' }}</span>
                <span class="font-medium">{{ $row->qty }}</span>
            </div>
        @endforeach
    </div>
    <a href="{{ route('admin.warehouses.index') }}" class="link link-hover text-xs self-start mt-1">
        {{ __('Manage warehouses') }}
    </a>
</div>
