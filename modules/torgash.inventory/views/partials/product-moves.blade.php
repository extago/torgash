{{-- The latest movements of this product, under its stock on the product
     form (PLAN §42) — "why is it 3 when it was 10" answered where it is
     asked. --}}
@php
    $latestMoves = \Torgash\Inventory\Models\Move::where('product_id', $product->id)->orderByDesc('id')->limit(5)->get();
@endphp
@if ($latestMoves->isNotEmpty())
    <div class="mt-2 text-xs">
        <div class="opacity-60 mb-1">{{ __('Latest movements') }}</div>
        @foreach ($latestMoves as $move)
            @php
                $moveLine = \Torgash\Inventory\Models\Move::typeLabel($move->type).($move->user_name ? ' · '.$move->user_name : '');
            @endphp
            <div class="flex gap-2">
                <span class="opacity-50 whitespace-nowrap">{{ $move->created_at?->format('d.m H:i') }}</span>
                <span class="flex-1 truncate">{{ $moveLine }}</span>
                <span class="tabular-nums {{ $move->delta > 0 ? 'text-success' : 'text-error' }}">{{ $move->delta > 0 ? '+' : '−' }}{{ abs($move->delta) }}</span>
            </div>
        @endforeach
        <a href="{{ route('admin.inventory.moves', ['product' => $product->id]) }}" class="link link-primary">{{ __('The whole journal') }} &rarr;</a>
    </div>
@endif
