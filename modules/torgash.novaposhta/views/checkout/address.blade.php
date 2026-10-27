{{-- Courier delivery: the street the parcel is driven to. The town is already
     chosen above (it is what matched the carrier's city), so this asks only for
     what the town does not say. --}}
<fieldset class="fieldset p-0">
    <legend class="fieldset-legend">{{ __('Street, building, flat') }}</legend>
    <input aria-label="{{ __('Street, building, flat') }}" name="shipping_fields[address]" class="input input-bordered {{ $fieldSize ?? 'input-sm' }} w-full"
           placeholder="{{ __('вул. Соборна, 12, кв. 5') }}"
           value="{{ old('shipping_fields.address') }}">
    <div class="text-xs opacity-50">{{ __('The courier calls before arriving.') }}</div>
</fieldset>
