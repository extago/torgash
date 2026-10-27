{{-- Beside a cart line whose product has a price in points: buy it outright
     for them, or no longer. --}}
<button type="button" hx-post="{{ route('points.line', $pointsLine->key) }}" hx-target="#cart-contents" hx-swap="innerHTML"
        @class(['btn btn-sm btn-touch gap-1', 'btn-warning' => $pointsOn, 'btn-ghost opacity-70 hover:opacity-100' => ! $pointsOn])
        title="{{ $pointsOn ? __('Pay for it with money instead') : __('Buy it for points') }}"
        aria-pressed="{{ $pointsOn ? 'true' : 'false' }}">
    <x-icon name="coins" size="sm" /> {{ number_format($pointsCost, 0, '.', ' ') }}
</button>
