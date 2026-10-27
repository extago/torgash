{{-- Nova Poshta's section of the shipping-method form. Rendered by the
     checkout method form via ShippingProvider::configView(); $config is the
     method's current provider_config. --}}
@php
    use Torgash\NovaPoshta\ServiceType;
    use Torgash\NovaPoshta\WarehouseType;

    // A method saved when "to a parcel locker" was its own variant reads as
    // "to a branch, lockers only" — the same thing, said the way the form now
    // says it. The next save writes it that way for good.
    $serviceType = $config['service_type'] ?? ServiceType::BRANCH;
    $warehouseTypes = (array) ($config['warehouse_types'] ?? []);

    if ($serviceType === ServiceType::LEGACY_LOCKER) {
        $serviceType = ServiceType::BRANCH;
        $warehouseTypes = [WarehouseType::PARCEL_LOCKER];
    }
    $calculate = (bool) ($config['calculate'] ?? true);
@endphp

<div class="flex flex-col gap-4"
     x-data="{ serviceType: '{{ $serviceType }}' }">
    <div class="text-xs font-semibold uppercase tracking-wide opacity-50">{{ __('Nova Poshta') }}</div>

    <fieldset class="fieldset p-0">
        <legend class="fieldset-legend">{{ __('This method delivers') }}</legend>
        <div class="flex flex-col gap-1">
            @foreach (ServiceType::all() as $type)
                <label class="label cursor-pointer justify-start gap-2 p-0">
                    <input type="radio" name="provider_config[service_type]" value="{{ $type }}"
                           class="radio radio-sm radio-primary" x-model="serviceType"
                           @checked($serviceType === $type)>
                    <span class="label-text">{{ ServiceType::label($type) }}</span>
                </label>
            @endforeach
        </div>
    </fieldset>

    <fieldset class="fieldset p-0" x-show="serviceType === '{{ ServiceType::BRANCH }}'" x-cloak>
        <legend class="fieldset-legend">{{ __('Branch types to offer') }}</legend>
        <div class="flex flex-wrap gap-x-6 gap-y-1">
            {{-- Lockers included: which KIND of pickup point is a tick, not a
                 variant. A shop that wants lockers priced or named on their own
                 makes a second method and ticks only that one. --}}
            @foreach (WarehouseType::all() as $ref => $label)
                <label class="label cursor-pointer justify-start gap-2 p-0">
                    <input type="checkbox" name="provider_config[warehouse_types][]" value="{{ $ref }}"
                           class="checkbox checkbox-sm checkbox-primary"
                           @checked(in_array($ref, $warehouseTypes, true))>
                    <span class="label-text">{{ $label }}</span>
                </label>
            @endforeach
        </div>
        <div class="text-xs opacity-50">{{ __('None ticked = all of them.') }}</div>
    </fieldset>

    <label class="label cursor-pointer justify-start gap-2 p-0">
        <input type="hidden" name="provider_config[calculate]" value="0">
        <input type="checkbox" name="provider_config[calculate]" value="1"
               class="checkbox checkbox-sm checkbox-primary" @checked($calculate)>
        <span class="label-text">{{ __('Ask Nova Poshta for the price') }}</span>
    </label>
    <div class="text-xs opacity-50 -mt-2">
        {{ __('Off: the method shows free and you invoice delivery separately.') }}
    </div>
</div>
