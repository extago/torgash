{{-- On the order's payment card: points spent on it and what it earns, with
     the manager's own number for the second. --}}
<div class="mt-3 flex flex-col gap-2 border-t border-base-300 pt-3 text-sm">
    <div class="text-xs font-semibold opacity-70 flex items-center gap-1.5"><x-icon name="coins" size="sm" /> {{ __('Bonus points') }}</div>
    @if ($pointsRow->spent > 0)
        <div class="flex justify-between"><span class="opacity-70">{{ __('Spent') }}</span><span>{{ number_format($pointsRow->spent, 0, '.', ' ') }} (&minus;{{ admin_price($pointsRow->spent_money, $order->currency_code) }})</span></div>
    @endif
    <div class="flex justify-between">
        <span class="opacity-70">{{ $pointsRow->credited_at ? __('Earned') : __('To earn') }}</span>
        <span>{{ number_format($pointsRow->credited_at ? $pointsRow->earned : $pointsRow->earn, 0, '.', ' ') }}</span>
    </div>
    @can('orders.manage')
        <form method="POST" action="{{ route('admin.points.orders.earn', $order) }}" class="join w-full">
            @csrf
            <input name="points" type="number" min="0" class="input input-bordered input-sm join-item w-full" value="{{ $pointsRow->credited_at ? $pointsRow->earned : $pointsRow->earn }}"
                   aria-label="{{ __('Points this order earns') }}">
            <button class="btn btn-sm btn-primary join-item">{{ __('Save') }}</button>
        </form>
    @endcan
</div>
