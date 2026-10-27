{{--
    Rendered via catalog.product.availability, right after the stock pill on
    the product page — only reached for a split product (see isSplit() in
    WarehousesServiceProvider). Unlike the admin stock-breakdown partial,
    this deliberately filters out disabled warehouses and empty rows — a
    customer has no use for "0 in a warehouse that isn't even open".
--}}
@php
    $rows = \Illuminate\Support\Facades\DB::table('m_warehouse_product_stock as s')
        ->join('m_warehouses as w', 'w.id', '=', 's.warehouse_id')
        ->where('s.product_id', $product->id)
        ->where('w.enabled', true)
        ->where('s.qty', '>', 0)
        ->orderBy('w.sort')
        ->get(['w.name', 's.qty']);
    $locale = app()->getLocale();
@endphp
{{-- The chosen point first and on its own line: the by-warehouse list answers
     "where is it", but a customer who has picked a shop is asking one question,
     about one shelf, and making them find it in a comma-separated list is the
     same as not answering. --}}
@if ($point)
    @if ($here > 0)
        <div class="text-sm mt-2 text-success flex items-center gap-1.5"><x-icon name="circle-check" size="sm" /> {{ __('In stock at :point', ['point' => $point->t('name')]) }}</div>
    @elseif ($bringBy ?? null)
        {{-- Not on that shelf, but another warehouse can send it there
             (PLAN §38): a date a customer can plan by, not a shrug. --}}
        <div class="text-sm mt-2 text-primary flex items-center gap-1.5">
            <x-icon name="truck" size="sm" />
            {{ __('We will bring it to :point by :date', ['point' => $point->t('name'), 'date' => $bringBy->translatedFormat('j F')]) }}
        </div>
    @else
        <div class="text-sm mt-2 opacity-60">{{ __('Not at :point — available to order', ['point' => $point->t('name')]) }}</div>
    @endif
@endif

@if ($rows->isNotEmpty())
    <div class="text-xs opacity-60 mt-2">
        {{ __('Availability by warehouse') }}:
        {{ $rows->map(function ($row) use ($locale) {
            return (pick_translation($row->name, $locale) ?? '—').': '.$row->qty;
        })->implode(', ') }}
    </div>
@endif
