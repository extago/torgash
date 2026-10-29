{{-- The products a typed word matches, under the box. "Add" hands the line
     to the page (Alpine `add`): one more of a product already on the list,
     a new line otherwise. Priced at the list price; the manager's own goes
     into the line's box. --}}
@if ($needle !== '')
    <div class="border border-base-300 rounded-box mt-2 divide-y divide-base-300 max-h-72 overflow-y-auto">
        @forelse ($products as $product)
            @php
                $line = [
                    'product_id' => $product->id,
                    'name' => $product->t('name'),
                    'sku' => $product->sku,
                    'list_price' => number_format($product->price / 100, 2, '.', ''),
                ];
            @endphp
            <div class="flex items-center justify-between gap-3 px-3 py-2">
                <div class="min-w-0">
                    <div class="truncate">{{ $product->t('name') }}</div>
                    <div class="text-xs opacity-50 font-mono">#{{ $product->id }} · {{ $product->sku }} · {{ __('Stock') }}: {{ $product->stock }}</div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <span class="text-sm opacity-70">{{ admin_price((int) $product->price) }}</span>
                    <button type="button" class="btn btn-sm btn-primary" x-on:click="add(@js($line))">{{ __('Add') }}</button>
                </div>
            </div>
        @empty
            <div class="px-3 py-3 text-sm opacity-50">{{ __('Nothing found.') }}</div>
        @endforelse
    </div>
@endif
