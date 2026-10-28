@php $cart = app(\Torgash\Cart\CartManager::class); $cartCount = $cart->count(); @endphp
{{-- `title` names it: a tooltip here, and the word a light header prints
     under the icon (.torgash-header-icons), which hides the summary beside
     it. The count sits ON the icon's corner and is there at zero too, so
     nothing in the header moves when the first thing is added. The sum is
     the one the basket page totals to (`cart.total`), and is only worked out
     when there is something to add up. --}}
<a href="{{ route('cart.index') }}" id="cart-badge" class="btn btn-ghost btn-sm gap-5 px-3" title="{{ __('Basket') }}"
   @if ($cartBadgeOob ?? false) hx-swap-oob="true" @endif
   aria-label="{{ __('Basket') }}{{ $cartCount > 0 ? ' ('.$cartCount.')' : '' }}">
    <span class="torgash-counter">
        <x-icon name="shopping-bag" size="lg" />
        <span @class(['badge badge-sm', 'badge-primary' => $cartCount > 0, 'torgash-counter-zero' => $cartCount === 0])>{{ $cartCount > 9 ? '9+' : $cartCount }}</span>
    </span>
    <span class="torgash-cart-summary hidden xl:flex flex-col items-start text-left leading-tight font-normal">
        <span class="font-semibold">{{ __('Basket') }}</span>
        @if ($cartCount > 0)
            <span class="text-primary tabular-nums whitespace-nowrap">{{ format_price(hook_filter('cart.total', $cart->total(), ['cart' => $cart]), exact: true) }}</span>
        @else
            <span class="opacity-60">{{ __('is empty') }}</span>
        @endif
    </span>
</a>
