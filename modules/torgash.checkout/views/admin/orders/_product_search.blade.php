{{-- Results of the add-a-product box. Replaced on every keystroke burst, so it
     carries no state of its own. --}}
@if ($needle !== '')
    <div class="border border-base-300 rounded-box mt-2 divide-y divide-base-300 max-h-72 overflow-y-auto">
        @forelse ($products as $product)
            @php $already = in_array($product->id, $onOrder); @endphp
            <div class="flex items-center justify-between gap-3 px-3 py-2">
                <div class="min-w-0">
                    <div class="truncate">{{ $product->t('name') }}</div>
                    <div class="text-xs opacity-50 font-mono">
                        #{{ $product->id }} · {{ $product->sku }}
                        @if ($already) · {{ __('already on this order') }} @endif
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <span class="text-sm opacity-70">{{ admin_price($product->price, $order->currency_code) }}</span>
                    <button class="btn btn-sm btn-primary"
                            hx-post="{{ route('admin.orders.items.store', $order) }}"
                            hx-vals='@json(['product_id' => $product->id])'
                            hx-target="#order-editor" hx-swap="outerHTML">
                        {{ $already ? __('+1') : __('Add') }}
                    </button>
                </div>
            </div>
        @empty
            <div class="px-3 py-3 text-sm opacity-50">{{ __('Nothing found.') }}</div>
        @endforelse
    </div>
@endif
