{{-- The coupon at checkout: the cart's box, but answering the CHECKOUT —
     `in=checkout` makes the controller announce `coupon-changed` instead of
     drawing the cart, and the delivery step re-quotes the whole summary. The
     result of the last try (flashed) is read here, on that re-quote. --}}
@php $couponResult = session('coupon_result'); @endphp
@if ($couponResult && ! $couponResult['ok'])
    <div class="text-sm text-error mb-2">{{ $couponResult['message'] }}</div>
@endif

@if ($coupon)
    <form method="POST" action="{{ route('cart.coupon.remove') }}" hx-delete="{{ route('cart.coupon.remove') }}" hx-swap="none"
          class="flex items-center justify-between gap-2 text-sm">
        @csrf
        @method('DELETE')
        <input type="hidden" name="in" value="checkout">
        <span class="opacity-70">{{ __('Coupon') }} <span class="badge badge-success badge-sm font-mono">{{ $coupon->code }}</span></span>
        <button class="link text-xs opacity-60 hover:opacity-100">{{ __('Remove') }}</button>
    </form>
@endif
@if (! $coupon || \Torgash\Checkout\CouponManager::takesOtherCodes())
    <form method="POST" action="{{ route('cart.coupon.apply') }}" hx-post="{{ route('cart.coupon.apply') }}" hx-swap="none" class="relative">
        @csrf
        <input type="hidden" name="in" value="checkout">
        <input type="text" name="code" placeholder="{{ \Torgash\Checkout\CouponManager::placeholder() }}" aria-label="{{ __('Coupon code') }}" autocomplete="off"
               class="input input-bordered w-full pr-12 [&:not(:placeholder-shown)]:uppercase">
        <button type="submit" class="absolute right-1 top-1/2 -translate-y-1/2 btn btn-ghost btn-sm btn-square" title="{{ __('Apply') }}" aria-label="{{ __('Apply') }}">
            <x-icon name="arrow-right" size="sm" />
        </button>
    </form>
@endif
{{-- What else the box could have been filled with — a signed-in customer's
     own gift cards, one click each — drawn by whoever issues such codes. --}}
{!! hook_filter('checkout.code.after', '', ['in' => 'checkout']) !!}
