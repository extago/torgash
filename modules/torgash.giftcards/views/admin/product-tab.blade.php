{{-- The product form's "Gift card" tab, inside the product form: ticking it
     makes this product a gift card, sold like any other and issued once its
     order is paid. `giftcard_form` says the tab was on the page, so a save
     from elsewhere (an import, the API) never unticks it. --}}
<div class="card bg-base-100 border border-base-300" x-data="{ isGift: {{ \Illuminate\Support\Js::from((bool) $giftRow) }}, free: {{ \Illuminate\Support\Js::from((bool) $giftRow?->free_amount) }} }">
    <div class="card-body gap-4">
        <input type="hidden" name="giftcard_form" value="1">

        <label class="label cursor-pointer justify-start gap-3">
            <input type="hidden" name="giftcard[is]" value="0">
            <input type="checkbox" name="giftcard[is]" value="1" class="toggle toggle-sm toggle-success" x-model="isGift">
            <span class="label-text font-medium">{{ __('This product is a gift card') }}</span>
        </label>
        <p class="text-xs opacity-60 -mt-2 max-w-3xl">
            {{ __('A card is issued for every unit once the order is paid, worth what one unit cost, and sent to whoever the customer named on the day they chose. Different fixed amounts are variations of the product; or let the customer type the amount.') }}
        </p>

        <div class="flex flex-col gap-4" x-show="isGift" x-cloak>
            <label class="label cursor-pointer justify-start gap-3">
                <input type="hidden" name="giftcard[free_amount]" value="0">
                <input type="checkbox" name="giftcard[free_amount]" value="1" class="checkbox checkbox-sm" x-model="free">
                <span class="label-text">{{ __('The customer types the amount') }}</span>
            </label>

            <div class="grid md:grid-cols-2 gap-4 max-w-2xl" x-show="free" x-cloak>
                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Smallest amount') }}</legend>
                    <label class="input input-bordered w-full">
                        <input name="giftcard[min]" type="number" step="0.01" min="0" class="grow"
                               value="{{ $giftRow?->min_amount !== null ? $giftRow->min_amount / 100 : '' }}"
                               placeholder="{{ setting('torgash.giftcards', 'min_amount', 100) }}">
                        <span class="opacity-50">{{ shop_currency() }}</span>
                    </label>
                </fieldset>
                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Largest amount') }}</legend>
                    <label class="input input-bordered w-full">
                        <input name="giftcard[max]" type="number" step="0.01" min="0" class="grow"
                               value="{{ $giftRow?->max_amount !== null ? $giftRow->max_amount / 100 : '' }}"
                               placeholder="{{ setting('torgash.giftcards', 'max_amount', 0) ?: __('no limit') }}">
                        <span class="opacity-50">{{ shop_currency() }}</span>
                    </label>
                </fieldset>
                <p class="text-xs opacity-60 md:col-span-2">{{ __('Empty — the shop\'s own limits from the module\'s settings. Set the product\'s price to 0: the customer pays exactly what they type.') }}</p>
            </div>

            <p class="text-xs opacity-60">{{ __('A card is money, not goods: coupons and promotions never discount it, and by default another gift card cannot pay for it.') }}</p>
        </div>
    </div>
</div>
