{{-- One batch of the window's products: the rows, and — when there are more
     and the shop loads them — a marker the script watches; scrolled into view,
     it is replaced by the next batch, which carries its own marker. The first
     batch arrives inside results.blade.php, the rest on their own. --}}
@php
    $shownPrice = app(\Torgash\Catalog\Pricing\ShownPrice::class);
    $canBuy = $show['buy'] && \Illuminate\Support\Facades\Route::has('cart.add');
@endphp
@foreach ($products as $product)
    @php
        $orderable = $product->isOrderable();
        // A product that must be CHOSEN first (a variation, a question)
        // cannot be dropped into the basket from here: the basket's choice
        // window opens instead (torgash.cart's ListBuy), or — with no such
        // module — its page does.
        $needsChoice = class_exists(\Torgash\Cart\ListBuy::class)
            ? app(\Torgash\Cart\ListBuy::class)->mustChoose($product)
            : $shownPrice->isFrom($product);
        $name = $product->t('name');
    @endphp
    <li class="flex items-center gap-3 px-3 py-1.5 hover:bg-base-200">
        <a href="{{ route('catalog.product', $product->slug) }}" role="option" class="flex items-center gap-3 flex-1 min-w-0">
            @if ($show['image'])
                <span class="w-12 h-12 shrink-0 rounded bg-base-200 flex items-center justify-center overflow-hidden">
                    @if ($image = $product->primaryImageUrl(96))
                        <img src="{{ $image }}" alt="" class="object-contain w-full h-full" loading="lazy">
                    @else
                        <x-icon name="image" class="opacity-30" />
                    @endif
                </span>
            @endif
            <span class="flex flex-col min-w-0">
                <span class="leading-snug line-clamp-2">{{ \Torgash\Search\Highlight::html($name, $marked) }}</span>
                @if ($show['sku'] && $product->sku)
                    <span class="text-xs opacity-50 font-mono">{{ \Torgash\Search\Highlight::html($product->sku, $marked) }}</span>
                @endif
                @if ($show['price'] || $show['stock'])
                    <span class="text-xs flex items-center gap-2">
                        @if ($show['price'])
                            <span class="font-semibold torgash-price">{{ $shownPrice->format($product) }}</span>
                        @endif
                        @if ($show['stock'])
                            <span class="torgash-availability-{{ $product->effectiveAvailabilityKey() }} {{ $orderable ? 'torgash-in-stock' : 'torgash-out-of-stock' }}">{{ $product->stockLabel() }}</span>
                        @endif
                    </span>
                @endif
            </span>
        </a>
        @if ($canBuy && $orderable && ! $needsChoice)
            {{-- Both states are drawn and one is hidden: the script flips them
                 when the add succeeds, so the button says what just happened
                 without a second round trip. A refusal (HX-Retarget) flips
                 nothing. --}}
            @php $already = isset($inCart[$product->id]); @endphp
            <div class="shrink-0" data-live-search-buy>
                <form hx-post="{{ route('cart.add', $product->slug) }}" hx-swap="none"
                      data-live-search-add @class(['hidden' => $already])>
                    <input type="hidden" name="qty" value="1">
                    @if ($show['after_add'] ?? null)
                        <input type="hidden" name="after_add" value="{{ $show['after_add'] }}">
                    @endif
                    <button class="btn btn-primary btn-sm btn-square" title="{{ __('Add to cart') }}" aria-label="{{ __('Add to cart') }}: {{ $name }}">
                        <x-icon name="shopping-cart" size="sm" />
                    </button>
                </form>
                <a href="{{ route('cart.index') }}" data-live-search-in-cart tabindex="-1"
                   @class(['btn btn-success btn-sm btn-square', 'hidden' => ! $already])
                   title="{{ __('In cart — open the cart') }}" aria-label="{{ __('In cart — open the cart') }}">
                    <x-icon name="check" size="sm" />
                </a>
            </div>
        @elseif ($canBuy && $orderable)
            <a href="{{ route('catalog.product', $product->slug) }}"
               @if (\Illuminate\Support\Facades\Route::has('cart.choose')) hx-get="{{ route('cart.choose', $product->slug) }}" hx-target="#torgash-cart-dialog-body" hx-swap="innerHTML" @endif
               class="btn btn-primary btn-sm btn-square relative shrink-0" tabindex="-1"
               title="{{ __('Choose and add to cart') }}" aria-label="{{ __('Choose and add to cart') }}: {{ $name }}">
                <x-icon name="shopping-cart" size="sm" />
                <span class="absolute -top-1.5 -right-1.5 rounded-full bg-base-100 text-base-content shadow-sm p-0.5 leading-none"><x-icon name="settings" size="sm" /></span>
            </a>
        @endif
    </li>
@endforeach
@if ($more)
    <li data-live-search-more="{{ $more }}" class="flex justify-center py-2" aria-hidden="true">
        <span class="loading loading-dots loading-sm opacity-40"></span>
    </li>
@endif
