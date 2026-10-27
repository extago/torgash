{{-- Where a card may be spent (PLAN §40.4). Shared by "Issue" and a card's
     own page; `$giftRules` is the card's stored rules (or [] for a new one). --}}
<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-4">
        <h3 class="font-semibold">{{ __('Where it can be spent') }}</h3>
        <p class="text-xs opacity-60 -mt-2">{{ __('Nothing here — anywhere, for the whole order.') }}</p>

        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Only for goods in these categories') }}</legend>
            <select name="rule_categories[]" multiple size="6" class="select select-bordered w-full h-auto">
                @foreach ($categoryOptions as $categoryId => $categoryLabel)
                    <option value="{{ $categoryId }}" @selected(in_array((int) $categoryId, array_map('intval', (array) old('rule_categories', $giftRules['categories'] ?? [])), true))>{{ $categoryLabel }}</option>
                @endforeach
            </select>
            <p class="label text-xs opacity-60 whitespace-normal">{{ __('With everything beneath them. None chosen — every category.') }}</p>
        </fieldset>

        <div class="grid md:grid-cols-3 gap-4">
            @php $shippingRule = old('rule_shipping', array_key_exists('shipping', $giftRules) ? ($giftRules['shipping'] ? 'yes' : 'no') : ''); @endphp
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Delivery') }}</legend>
                <select name="rule_shipping" class="select select-bordered w-full">
                    <option value="" @selected($shippingRule === '')>{{ __('As the shop says') }}</option>
                    <option value="yes" @selected($shippingRule === 'yes')>{{ __('It may pay for delivery') }}</option>
                    <option value="no" @selected($shippingRule === 'no')>{{ __('Goods only') }}</option>
                </select>
            </fieldset>
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('At most, % of an order') }}</legend>
                <input name="rule_max_percent" type="number" min="1" max="100" class="input input-bordered w-full"
                       value="{{ old('rule_max_percent', $giftRules['max_percent'] ?? '') }}" placeholder="100">
            </fieldset>
            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('On an order from') }}</legend>
                <label class="input input-bordered w-full">
                    <input name="rule_min_order" type="number" step="0.01" min="0" class="grow"
                           value="{{ old('rule_min_order', isset($giftRules['min_order']) ? $giftRules['min_order'] / 100 : '') }}"
                           placeholder="{{ __('any') }}">
                    <span class="opacity-50">{{ shop_currency() }}</span>
                </label>
            </fieldset>
        </div>
    </div>
</section>
