{{-- Spending points, on the cart page (`cart.rows_before_total`) and under the
     checkout's code box: the balance, how many at most, a box and Apply. Its
     own small form — the summary and the cart's totals are outside any other
     form. Named distinctively: a Blade include inherits the caller's scope. --}}
<div class="flex flex-col gap-1.5 text-sm">
    <div class="flex justify-between items-center gap-2">
        <span class="flex items-center gap-1.5"><x-icon name="coins" size="sm" class="opacity-60" /> {{ __('Bonus points') }}</span>
        <span class="opacity-70 tabular-nums">
            {{ __('you have :points', ['points' => number_format($pointsBalance, 0, '.', ' ')]) }}
            @if ($pointsInfoUrl)
                <a href="{{ $pointsInfoUrl }}" class="link link-hover ml-1" aria-label="{{ __('About bonus points') }}" title="{{ __('About bonus points') }}"><x-icon name="circle-help" size="sm" class="inline align-text-bottom" /></a>
            @endif
        </span>
    </div>

    @if ($pointsBlocked)
        <p class="text-xs opacity-60">{{ __('Points cannot be spent together with a coupon.') }}</p>
    @elseif ($pointsFrom > 0)
        <p class="text-xs opacity-60">{{ __('Points pay for an order from :amount.', ['amount' => format_price($pointsFrom)]) }}</p>
    @elseif ($pointsMax > 0 || $pointsLine)
        <form method="POST" action="{{ route('points.spend') }}" class="flex gap-2"
              hx-post="{{ route('points.spend') }}"
              @if ($in === 'checkout') hx-swap="none" @else hx-target="#cart-contents" hx-swap="innerHTML" @endif>
            @csrf
            <input type="hidden" name="in" value="{{ $in }}">
            <input type="text" inputmode="numeric" name="points" class="input input-bordered input-sm w-full"
                   value="{{ $pointsAsked ?: '' }}" placeholder="{{ __('up to :points', ['points' => number_format($pointsMax, 0, '.', ' ')]) }}"
                   aria-label="{{ __('How many points to spend') }}">
            <button class="btn btn-sm">{{ __('Apply') }}</button>
        </form>
        @if ($pointsRestricted ?? false)
            <p class="text-xs opacity-60">{{ __('Some of these goods cannot be paid with points, or only in part.') }}</p>
        @endif
        @if ($pointsLine)
            <p class="text-xs text-success">{{ __(':points points take :amount off this order.', ['points' => number_format($pointsLine['points'], 0, '.', ' '), 'amount' => format_price($pointsLine['amount'])]) }}</p>
        @endif
    @endif
</div>
