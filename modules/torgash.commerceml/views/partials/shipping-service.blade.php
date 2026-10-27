{{-- Printed onto another module's form through shipping.method.extra, and
     stored by this module through shipping.method.saved. A card of its own:
     the form lays each module's answer out beside its own sections. --}}
<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-2">
        <h3 class="font-semibold">{{ __('Exchange with 1C') }}</h3>
        <fieldset class="fieldset p-0 max-w-md">
            <legend class="fieldset-legend">{{ __('1C: delivery service identifier') }}</legend>
            <input aria-label="{{ __('1C: delivery service identifier') }}" name="commerceml_service_guid" value="{{ $guid }}" class="input input-bordered w-full font-mono text-sm">
            <div class="text-xs opacity-50 whitespace-normal">
                {{ __('The identifier of the service item in 1C that this delivery is billed to. An order exported to 1C carries its delivery as a service line — without it the lines do not add up to the total — and this says which line of 1C’s own nomenclature that is. Leave empty for a carrier 1C knows nothing about: the line still goes, by name.') }}
            </div>
        </fieldset>
    </div>
</section>
