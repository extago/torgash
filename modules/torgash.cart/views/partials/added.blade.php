{{-- The answer to an add. Everything in it is out of band — the buy forms
     swap nothing themselves: what the page shows of the basket (`changed`),
     and what the customer is shown besides, into the one shared dialog or
     core's corner notices — which of the two is the shop's setting
     (CartController::afterAdd). The script opens the dialog when its body is
     filled. --}}
@include('torgash.cart::partials.changed')

@if ($mode === 'dialog')
    <div id="torgash-cart-dialog-body" hx-swap-oob="innerHTML">
        <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-base-300">
            <h2 class="text-lg font-semibold">{{ __('Added to your basket') }}</h2>
            <button type="button" class="btn btn-ghost btn-sm btn-circle" data-cart-dialog-close aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
        </div>
        <div class="px-5 py-4 flex items-center gap-4">
            <span class="w-16 h-16 shrink-0 rounded bg-base-200 flex items-center justify-center overflow-hidden">
                @if ($addedImage = $product->primaryImageUrl(160))
                    <img src="{{ $addedImage }}" alt="" class="object-contain w-full h-full">
                @else
                    <x-icon name="image" class="opacity-30" />
                @endif
            </span>
            <a href="{{ route('catalog.product', $product->slug) }}" class="flex-1 min-w-0 link link-hover leading-snug">{{ $product->t('name') }}</a>
            {{-- The line's own sum, what it adds to the basket — the services
                 and parts chosen with it are inside it, so the total below
                 adds up from what is shown. --}}
            @if ($line)
                <span class="flex flex-col items-end text-sm tabular-nums">
                    <span class="font-semibold whitespace-nowrap">{{ format_price($line->line_total, exact: true) }}</span>
                    @if ($line->qty > 1)
                        <span class="opacity-60 whitespace-nowrap">{{ $line->qty }} × {{ format_price($line->unit_price, exact: true) }}</span>
                    @endif
                </span>
            @endif
        </div>
        {{-- An amount never breaks inside itself: when it does not fit
             beside the words, it moves down whole. --}}
        <div class="px-5 py-3 border-t border-base-300 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm">
            <span class="opacity-60">{{ trans_choice('The basket holds :count item|The basket holds :count items', $basketCount) }}</span>
            <span class="font-semibold">{{ __('Basket total') }}: <span class="whitespace-nowrap tabular-nums">{{ format_price($basketTotal, exact: true) }}</span></span>
        </div>
        {{-- On a phone the two stack full width, the way on to paying first. --}}
        <div class="px-5 py-4 bg-base-200 flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-3 rounded-b-box [&>.btn]:max-sm:w-full">
            <button type="button" class="btn btn-outline" data-cart-dialog-close>{{ __('Continue shopping') }}</button>
            @if (\Illuminate\Support\Facades\Route::has('checkout.create'))
                <a href="{{ route('checkout.create') }}" class="btn btn-primary gap-2"><x-icon name="circle-check" /> {{ __('Go to checkout') }}</a>
            @else
                <a href="{{ route('cart.index') }}" class="btn btn-primary gap-2"><x-icon name="shopping-bag" /> {{ __('Open the basket') }}</a>
            @endif
        </div>
    </div>
@elseif ($mode === 'toast')
    {{-- The corner notice is core's, shared with the wishlist and the
         comparison, so the three never stack in two different corners. --}}
    <x-notice :title="__('Added to your basket')" :text="($qty > 1 ? $qty.' × ' : '').$product->t('name')"
              :image="$product->primaryImageUrl(160)" icon="shopping-bag"
              :href="route('cart.index')" :action="__('Open the basket')" />
@endif
