{{-- Points for paying this way (PLAN §74, stage 4) — on the payment method's
     own form through checkout.payment_method.extra, saved by
     checkout.payment_method.saved in PointsServiceProvider. --}}
<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-3">
        <h3 class="font-semibold flex items-center gap-2"><x-icon name="coins" size="sm" /> {{ __('Bonus points for paying this way') }}</h3>
        <input type="hidden" name="points_payment_shown" value="1">
        <div class="flex flex-wrap items-end gap-3">
            <label class="fieldset p-0"><span class="fieldset-legend">{{ __('An order paid this way earns on top') }}</span>
                <input type="number" name="points_payment_value" min="0" step="0.01" class="input input-bordered w-40"
                       value="{{ old('points_payment_value', $pointsPayment['value'] > 0 ? rtrim(rtrim(number_format($pointsPayment['value'], 2, '.', ''), '0'), '.') : '') }}">
            </label>
            <label class="fieldset p-0"><span class="fieldset-legend sr-only">{{ __('Counted as') }}</span>
                <select name="points_payment_mode" class="select select-bordered" aria-label="{{ __('Counted as') }}">
                    <option value="fixed" @selected(old('points_payment_mode', $pointsPayment['mode']) !== 'percent')>{{ __('points per order') }}</option>
                    <option value="percent" @selected(old('points_payment_mode', $pointsPayment['mode']) === 'percent')>{{ __('% of the goods, as points') }}</option>
                </select>
            </label>
        </div>
        <p class="text-xs opacity-60 max-w-3xl">
            {{ __('Empty — nothing. The points wait and are credited with the order\'s own, and go back with them. A percent is counted from the goods after every discount; an order of gift cards alone earns none. Signed-in customers see "+N points" beside the method at checkout.') }}
        </p>
    </div>
</section>
