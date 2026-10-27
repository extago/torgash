{{-- Products matching what is being typed in the quick-entry box; a click
     adds one. Replaced on every pause in typing, so it carries no state. --}}
@if ($needle !== '')
    <div class="border border-base-300 rounded-box divide-y divide-base-300 max-h-72 overflow-y-auto">
        @forelse ($products as $product)
            <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                <div class="min-w-0">
                    <div class="truncate">{{ $product->t('name') }}</div>
                    <div class="text-xs opacity-50 font-mono">{{ $product->sku }} · {{ __('in stock') }}: {{ $product->stock }}</div>
                </div>
                <button type="button" class="btn btn-sm"
                        hx-post="{{ route('admin.inventory.documents.lines.add', $document) }}"
                        hx-vals='@json(['product_id' => $product->id, 'qty' => 1])'
                        hx-target="#document-lines" hx-swap="outerHTML">{{ __('Add') }}</button>
            </div>
        @empty
            <div class="px-3 py-3 text-sm opacity-50">{{ __('Nothing found.') }}</div>
        @endforelse
    </div>
@endif
