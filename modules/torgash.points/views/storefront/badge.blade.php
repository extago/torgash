{{-- Under a price: what buying it earns ("+24 points", to a guest "with an
     account"), and — where the shop sells for points — "or 1 500 points". --}}
<div @class(['flex flex-wrap items-center gap-x-3 gap-y-0.5 text-sm', 'mt-1' => $pointsWhere === 'page', 'text-xs' => $pointsWhere === 'card'])>
    @if ($pointsPrice !== null)
        <span class="font-medium text-warning flex items-center gap-1">
            <x-icon name="coins" size="sm" /> {{ trans_choice('or :points point|or :points points', $pointsPrice, ['points' => number_format($pointsPrice, 0, '.', ' ')]) }}
        </span>
    @endif
    @if ($pointsEarn > 0)
        <span class="opacity-70 flex items-center gap-1" title="{{ __('Bonus points for buying it') }}">
            @if ($pointsPrice === null)<x-icon name="coins" size="sm" />@endif
            {{ $pointsGuest && $pointsWhere === 'page'
                ? trans_choice('+:points point with an account|+:points points with an account', $pointsEarn, ['points' => number_format($pointsEarn, 0, '.', ' ')])
                : trans_choice('+:points point|+:points points', $pointsEarn, ['points' => number_format($pointsEarn, 0, '.', ' ')]) }}
        </span>
    @endif
</div>
