{{-- A product's variation axes as buttons — the choice window's and quick
     view's alike. A variation is a sibling PRODUCT: its button redraws the
     window it is in for that product ($axesRoute: cart.choose / cart.quick),
     and is a plain link to its page without script. --}}
    @foreach ($variations as $chooseAxis)
        <div>
            <div class="text-sm font-medium mb-1.5">{{ $chooseAxis['feature']->t('name') }}:</div>
            <div class="flex flex-wrap gap-2">
                @foreach ($chooseAxis['options'] as $chooseOption)
                    @php $chooseSibling = $chooseOption['product']; @endphp
                    <a href="{{ route('catalog.product', $chooseSibling->slug) }}"
                       hx-get="{{ route($axesRoute, $chooseSibling->slug) }}" hx-target="#torgash-cart-dialog-body" hx-swap="innerHTML"
                       @class([
                           'relative inline-flex items-center justify-center min-w-12 px-3 py-2 rounded-lg border text-sm',
                           'border-primary bg-primary/5 text-primary font-semibold' => $chooseOption['active'],
                           'border-base-300 hover:border-primary/50' => ! $chooseOption['active'] && $chooseSibling->isOrderable(),
                           'border-base-300 opacity-40 line-through' => ! $chooseOption['active'] && ! $chooseSibling->isOrderable(),
                       ])
                       @if ($chooseOption['active']) aria-current="true" @endif>
                        @if ($chooseOption['swatch'])
                            <span class="h-3.5 w-3.5 rounded-full border border-black/15 me-1.5" style="background-color: {{ $chooseOption['swatch'] }}"></span>
                        @endif
                        {{ $chooseOption['label'] }}
                        @if ($chooseOption['active'])
                            <x-icon name="circle-check" size="sm" class="absolute -top-2 -right-2 bg-base-100 rounded-full" />
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
