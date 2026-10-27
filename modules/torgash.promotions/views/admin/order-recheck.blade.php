{{-- "Recheck the promotions" in the order editor (PLAN §40.6/4). First press
     shows what today's promotions would make of this order; the second
     applies it. Buttons with hx-post, never a nested form — this sits inside
     the editor. --}}
<div id="order-promotions-recheck" class="flex flex-col gap-1.5">
    <div class="text-sm font-medium">{{ __('Promotions') }}</div>
    @isset($recheck)
        @if ($recheck['was'] === $recheck['becomes'])
            <p class="text-sm opacity-70">{{ __('Today\'s promotions give this order the same :amount — nothing to change.', ['amount' => $order->adminMoney(abs($recheck['becomes']))]) }}</p>
        @else
            <div class="text-sm">
                {{ __('Was :was, becomes :becomes.', ['was' => '−'.$order->adminMoney(abs($recheck['was'])), 'becomes' => '−'.$order->adminMoney(abs($recheck['becomes']))]) }}
            </div>
            @foreach ($recheck['lines'] as $recheckLine)
                <div class="text-xs opacity-70">{{ $recheckLine['label'] }}: −{{ $order->adminMoney(abs((int) $recheckLine['amount'])) }}</div>
            @endforeach
            <div class="flex gap-2">
                <button type="button" class="btn btn-sm btn-primary"
                        hx-post="{{ route('admin.promotions.orders.reapply', $order) }}" hx-target="#order-editor" hx-swap="outerHTML">{{ __('Apply') }}</button>
                <button type="button" class="btn btn-sm btn-ghost"
                        hx-post="{{ route('admin.promotions.orders.recheck', $order) }}" hx-target="#order-promotions-recheck" hx-swap="outerHTML">{{ __('Check again') }}</button>
            </div>
        @endif
    @else
        <button type="button" class="btn btn-sm w-fit"
                hx-post="{{ route('admin.promotions.orders.recheck', $order) }}" hx-target="#order-promotions-recheck" hx-swap="outerHTML">
            {{ __('Recheck the promotions') }}
        </button>
        <p class="text-xs opacity-60">{{ __('Runs today\'s promotions over what the order holds and shows the difference before anything changes.') }}</p>
    @endisset
</div>
