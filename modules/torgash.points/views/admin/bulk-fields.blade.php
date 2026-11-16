{{-- Bonus points in the products list's "Change…" dialog
     (`catalog.products.bulk.fields`): the same two answers as a product's own
     tab, for every product ticked. "Leave as it is" writes nothing. --}}
<section class="flex flex-col gap-3" x-data="{ pointsEarn: '', pointsSpend: '' }">
    <h4 class="font-semibold">{{ __('Bonus points') }}</h4>
    <div class="grid sm:grid-cols-[10rem_1fr] gap-x-4 gap-y-3 items-center">
        <span class="text-sm">{{ __('Earns') }}</span>
        <div class="join">
            <select name="points_bulk_earn" x-model="pointsEarn" class="select select-sm join-item w-64" aria-label="{{ __('Earns') }}">
                <option value="">{{ __('Leave as it is') }}</option>
                <option value="default">{{ __('As the rules say') }}</option>
                <option value="multiplier">{{ __('The shop\'s rate, multiplied') }}</option>
                <option value="percent">{{ __('A percent of the price, in points') }}</option>
                <option value="fixed">{{ __('A fixed number of points per piece') }}</option>
                <option value="none">{{ __('Nothing') }}</option>
            </select>
            <input type="number" step="0.01" min="0" name="points_bulk_earn_value" class="input input-sm join-item w-28" aria-label="{{ __('Value') }}"
                   :disabled="!['multiplier', 'percent', 'fixed'].includes(pointsEarn)"
                   :placeholder="pointsEarn === 'multiplier' ? '2' : (pointsEarn === 'percent' ? '5' : '100')">
        </div>

        <span class="text-sm">{{ __('Paying with points') }}</span>
        <div class="join">
            <select name="points_bulk_spend" x-model="pointsSpend" class="select select-sm join-item w-64" aria-label="{{ __('Paying with points') }}">
                <option value="">{{ __('Leave as it is') }}</option>
                <option value="default">{{ __('As the rules say') }}</option>
                <option value="allowed">{{ __('Allowed, within the shop\'s share') }}</option>
                <option value="limit">{{ __('Allowed, up to its own share') }}</option>
                <option value="forbidden">{{ __('Not allowed') }}</option>
            </select>
            <input type="number" step="1" min="0" max="100" name="points_bulk_spend_percent" class="input input-sm join-item w-28" aria-label="{{ __('Share, %') }}"
                   :disabled="pointsSpend !== 'limit'" placeholder="30">
        </div>
    </div>
    <p class="text-xs opacity-60">{{ __('A product\'s own answer wins over every rule — for many products alike a rule is usually the better tool.') }}</p>
</section>
