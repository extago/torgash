{{-- The two fields that decide what the rate table MEANS. They belong to the
     method and save with it, so they carry form="" — on the zones tab they sit
     outside the method's <form> (a rate has its own, and forms do not nest). --}}
<div class="grid md:grid-cols-3 gap-4">
    <fieldset class="fieldset p-0">
        <legend class="fieldset-legend">{{ __('How the price is worked out') }}</legend>
        <select name="price_mode" form="shipping-method-form" class="select select-bordered w-full">
            @foreach (\Torgash\Shipping\Models\ShippingMethod::MODES as $value => $label)
                <option value="{{ $value }}" @selected(old('price_mode', $method->price_mode ?? 'bands') === $value)>{{ __($label) }}</option>
            @endforeach
        </select>
        <div class="text-xs opacity-50">
            {{ __('"Asked of the carrier" needs a carrier on the next tab. A zone still needs a rate here whatever the mode — that is what decides where the method is offered.') }}
        </div>
    </fieldset>
    <fieldset class="fieldset p-0">
        <legend class="fieldset-legend">{{ __('When there is no way to price it') }}</legend>
        <select name="on_incalculable" form="shipping-method-form" class="select select-bordered w-full">
            @foreach (\Torgash\Shipping\Models\ShippingMethod::ON_INCALCULABLE as $value => $label)
                <option value="{{ $value }}" @selected(old('on_incalculable', $method->on_incalculable ?? 'quote') === $value)>{{ __($label) }}</option>
            @endforeach
        </select>
        <div class="text-xs opacity-50">
            {{ __('A weight band with no weight on some product, or a carrier that refuses the address. "Quoted by a human" lets the order through and asks you for the amount afterwards.') }}
        </div>
    </fieldset>

    {{-- Which weight the bands below are read with. Alpine only hides the
         divisor when nothing reads it — the value still posts, so switching
         back does not silently reset it to the default. --}}
    <fieldset class="fieldset p-0" x-data="{ chargeOn: '{{ old('charge_on', $method->charge_on ?? 'actual') }}' }">
        <legend class="fieldset-legend">{{ __('Weight bands are read as') }}</legend>
        <div class="flex gap-2">
            <select name="charge_on" form="shipping-method-form" class="select select-bordered w-full" x-model="chargeOn">
                @foreach (\Torgash\Shipping\Models\ShippingMethod::CHARGE_ON as $value => $label)
                    <option value="{{ $value }}" @selected(old('charge_on', $method->charge_on ?? 'actual') === $value)>{{ __($label) }}</option>
                @endforeach
            </select>
            <label class="input input-bordered w-32 shrink-0" x-show="chargeOn !== 'actual'" x-cloak
                   title="{{ __('Divide length × width × height in centimetres by this to get kilograms.') }}">
                <span class="opacity-50">÷</span>
                <input name="volumetric_divisor" type="number" min="1" step="1" form="shipping-method-form"
                       value="{{ old('volumetric_divisor', $method->volumetric_divisor ?? 4000) }}" class="grow min-w-0">
            </label>
        </div>
        <div class="text-xs opacity-50">
            {{ __('Volumetric weight is (length × width × height in cm) ÷ the divisor — 4000 for Nova Poshta and DHL. "Whichever is greater" is what carriers actually bill. Needs all three sides on every product; without them the method falls back as above.') }}
        </div>
    </fieldset>
</div>
