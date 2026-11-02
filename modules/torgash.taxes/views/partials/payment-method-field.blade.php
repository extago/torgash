{{-- The taxes a payment method's surcharge carries (PLAN §66) — on the
     method's form, saved by checkout.payment_method.saved in
     TaxesServiceProvider. None ticked is no tax on the surcharge. --}}
<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-3">
        <h3 class="font-semibold">{{ __('Taxes on the surcharge') }}</h3>
        <input type="hidden" name="surcharge_taxes_shown" value="1">
        <div class="flex flex-wrap gap-x-4 gap-y-1">
            @foreach ($taxes as $tax)
                <label class="label cursor-pointer justify-start gap-2 py-1">
                    <input type="checkbox" name="surcharge_taxes[]" value="{{ $tax->id }}" class="checkbox checkbox-sm"
                           @checked(in_array($tax->id, array_map('intval', (array) old('surcharge_taxes', $selected)), true))>
                    <span class="label-text text-sm">
                        {{ $tax->t('name', admin_content_locale()) }}
                        <span class="opacity-50">{{ $tax->rateLabel() }}{{ $tax->price_includes_tax ? ', '.__('in the price') : '' }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        <p class="text-xs opacity-60 max-w-3xl">
            {{ __('Charged on the surcharge alone, at the rate that applies where the goods go. A tax included in the price is inside the surcharge (the bill discloses it); one added on top is added to it. Only matters when the method has a surcharge.') }}
        </p>
    </div>
</section>
