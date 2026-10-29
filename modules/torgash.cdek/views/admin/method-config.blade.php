{{-- CDEK's section of the shipping-method form. Rendered by the shipping
     method form via ShippingProvider::configView(); $config is the method's
     current provider_config. --}}
@php
    use Torgash\Cdek\PointType;
    use Torgash\Cdek\Tariff;

    $delivery = ($config['delivery'] ?? 'point') === 'door' ? 'door' : 'point';
    $pointTypes = (array) ($config['point_types'] ?? []);
    $handover = ($config['handover'] ?? 'office') === 'door' ? 'door' : 'office';
    $tariff = (string) ($config['tariff'] ?? Tariff::PARCEL);
    $calculate = (bool) ($config['calculate'] ?? true);
@endphp

<div class="flex flex-col gap-4" x-data="{ delivery: '{{ $delivery }}' }">
    <div class="text-xs font-semibold uppercase tracking-wide opacity-50">{{ __('CDEK') }}</div>

    <fieldset class="fieldset p-0">
        <legend class="fieldset-legend">{{ __('This method delivers') }}</legend>
        <div class="flex flex-col gap-1">
            @foreach (['point' => __('To a pickup point'), 'door' => __('By courier')] as $value => $label)
                <label class="label cursor-pointer justify-start gap-2 p-0">
                    <input type="radio" name="provider_config[delivery]" value="{{ $value }}"
                           class="radio radio-sm" x-model="delivery" @checked($delivery === $value)>
                    <span class="label-text">{{ $label }}</span>
                </label>
            @endforeach
        </div>
    </fieldset>

    <fieldset class="fieldset p-0" x-show="delivery === 'point'" x-cloak>
        <legend class="fieldset-legend">{{ __('Point types to offer') }}</legend>
        <div class="flex flex-wrap gap-x-6 gap-y-1">
            @foreach (PointType::all() as $type => $label)
                <label class="label cursor-pointer justify-start gap-2 p-0">
                    <input type="checkbox" name="provider_config[point_types][]" value="{{ $type }}"
                           class="checkbox checkbox-sm" @checked(in_array($type, $pointTypes, true))>
                    <span class="label-text">{{ $label }}</span>
                </label>
            @endforeach
        </div>
        <div class="text-xs opacity-50">{{ __('None ticked = all of them.') }}</div>
    </fieldset>

    <fieldset class="fieldset p-0">
        <legend class="fieldset-legend">{{ __('Tariff') }}</legend>
        <select name="provider_config[tariff]" class="select select-bordered w-full max-w-xs">
            @foreach (Tariff::families() as $value => $label)
                <option value="{{ $value }}" @selected($tariff === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <div class="text-xs opacity-50">{{ __('Economy parcel has no locker variant: a locker priced by it has no live price.') }}</div>
    </fieldset>

    <fieldset class="fieldset p-0">
        <legend class="fieldset-legend">{{ __('You hand parcels to CDEK') }}</legend>
        <div class="flex flex-col gap-1">
            @foreach (['office' => __('At a CDEK office'), 'door' => __('CDEK’s courier collects them')] as $value => $label)
                <label class="label cursor-pointer justify-start gap-2 p-0">
                    <input type="radio" name="provider_config[handover]" value="{{ $value }}"
                           class="radio radio-sm" @checked($handover === $value)>
                    <span class="label-text">{{ $label }}</span>
                </label>
            @endforeach
        </div>
        <div class="text-xs opacity-50">{{ __('Part of the tariff: collection from your door costs more.') }}</div>
    </fieldset>

    <label class="label cursor-pointer justify-start gap-2 p-0">
        <input type="hidden" name="provider_config[calculate]" value="0">
        <input type="checkbox" name="provider_config[calculate]" value="1"
               class="checkbox checkbox-sm" @checked($calculate)>
        <span class="label-text">{{ __('Ask CDEK for the price') }}</span>
    </label>
    <div class="text-xs opacity-50 -mt-2">
        {{ __('Off: the method is priced by your own rate table.') }}
    </div>
</div>
