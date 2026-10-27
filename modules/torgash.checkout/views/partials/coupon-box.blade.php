@if ($result && ! $result['ok'])
    <div class="text-sm text-error">{{ $result['message'] }}</div>
@endif

@if ($coupon)
    <div class="flex justify-between items-center text-sm">
        <span class="flex items-center gap-2">
            {{ __('Coupon') }} <span class="badge badge-success badge-sm font-mono">{{ $coupon->code }}</span>
            <button hx-delete="{{ route('cart.coupon.remove') }}" hx-target="#cart-contents" hx-swap="innerHTML"
                    class="link text-xs opacity-60 hover:opacity-100">{{ __('Remove') }}</button>
        </span>
        <span class="text-success">&minus;{{ format_price($coupon->discountFor(app(\Torgash\Cart\CartManager::class)->total()), exact: true) }}</span>
    </div>
@endif
@if (! $coupon || \Torgash\Checkout\CouponManager::takesOtherCodes())
    {{-- One box with its own button inside it: a coupon is a thing most
         customers do not have, so it asks quietly. --}}
    <form hx-post="{{ route('cart.coupon.apply') }}" hx-target="#cart-contents" hx-swap="innerHTML"
          class="relative">
        @csrf
        <input type="text" name="code" placeholder="{{ \Torgash\Checkout\CouponManager::placeholder() }}" aria-label="{{ __('Coupon code') }}" autocomplete="off"
               class="input input-bordered w-full pr-12 [&:not(:placeholder-shown)]:uppercase">
        <button type="submit" class="absolute right-1 top-1/2 -translate-y-1/2 btn btn-ghost btn-sm btn-square" title="{{ __('Apply') }}" aria-label="{{ __('Apply') }}">
            <x-icon name="arrow-right" size="sm" />
        </button>
    </form>
@endif
{{-- What else the box could have been filled with — a signed-in customer's
     own gift cards, one click each — drawn by whoever issues such codes. --}}
{!! hook_filter('checkout.code.after', '', ['in' => 'cart']) !!}
