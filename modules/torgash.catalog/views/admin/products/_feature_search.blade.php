{{-- The characteristics a typed word matches, under the box. "Add" fetches
     the one as a field (products.features.field) into the form, where it
     is saved like any other; the box is emptied by the same swap. --}}
@if ($needle !== '')
    <div class="border border-base-300 rounded-box mt-2 divide-y divide-base-300 max-h-72 overflow-y-auto max-w-md">
        @forelse ($features as $feature)
            <div class="flex items-center justify-between gap-3 px-3 py-2">
                <div class="min-w-0">
                    <div class="truncate">{{ $feature->t('name', admin_content_locale()) ?: $feature->code }}</div>
                    <div class="text-xs opacity-50 font-mono">{{ $feature->code }}@if ($feature->group) · {{ $feature->group->t('name', admin_content_locale()) }}@endif</div>
                </div>
                <button type="button" class="btn btn-sm btn-primary shrink-0"
                        hx-get="{{ route('admin.catalog.products.features.field', array_filter(['feature' => $feature->id, 'product' => $productId])) }}"
                        hx-target="#product-features-added" hx-swap="beforeend"
                        x-on:htmx:after-request="$el.closest('#product-features-found').innerHTML = ''">
                    {{ __('Add') }}
                </button>
            </div>
        @empty
            <div class="px-3 py-3 text-sm opacity-50">{{ __('Nothing found.') }}</div>
        @endforelse
    </div>
@endif
