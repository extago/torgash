{{-- The product's own tab: LINKS, like the options module's. A kit with its
     slots is a form of its own, and a second place to edit it would be a second
     place for it to be wrong. --}}
<div class="flex flex-col gap-4">
    @unless ($product->exists)
        <div class="alert text-sm">
            <span>{{ __('Save the product first — a kit has to lead with something.') }}</span>
        </div>
    @else
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <div class="flex items-center gap-2">
                    <h3 class="font-semibold grow">{{ __('Kits this product leads') }}</h3>
                    <a href="{{ route('admin.kits.create', ['product' => $product->id]) }}"
                       class="btn btn-ghost btn-sm">{{ __('Add a kit') }}</a>
                </div>

                @forelse ($own as $kit)
                    <div class="flex items-center gap-2 text-sm">
                        <a href="{{ route('admin.kits.edit', $kit) }}" class="link link-hover grow">
                            {{ $kit->t('name', admin_content_locale()) ?: $kit->admin_name }}
                        </a>
                        <span class="badge badge-ghost badge-sm">{{ trans_choice('{1} :count item|[2,*] :count items', $kit->slots->count(), ['count' => $kit->slots->count()]) }}</span>
                        @unless ($kit->isCurrent())
                            <span class="badge badge-ghost badge-sm">{{ __('Off') }}</span>
                        @endunless
                    </div>
                @empty
                    <p class="text-xs opacity-50">{{ __('This product leads no kit of its own.') }}</p>
                @endforelse
            </div>
        </div>

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <div>
                    <h3 class="font-semibold">{{ __('Shared kits that reach this product') }}</h3>
                    <p class="text-xs opacity-50">
                        {{ __('Placed by their own conditions — this product becomes the main one, and what goes with it is picked from each item’s set, narrowed to what fits.') }}
                    </p>
                </div>

                @forelse ($shared as $kit)
                    <div class="flex items-center gap-2 text-sm">
                        <a href="{{ route('admin.kits.edit', $kit) }}" class="link link-hover grow">
                            {{ $kit->t('name', admin_content_locale()) ?: $kit->admin_name }}
                        </a>
                        <span class="badge badge-ghost badge-sm">{{ trans_choice('{1} :count item|[2,*] :count items', $kit->slots->count(), ['count' => $kit->slots->count()]) }}</span>
                    </div>
                @empty
                    <p class="text-xs opacity-50">{{ __('No shared kit reaches this product.') }}</p>
                @endforelse
            </div>
        </div>
    @endunless
</div>
