{{-- The same row, expanded into its editor. A <form> of its own — it is never
     inside the method's form, which is why the rates tab sits beside it. --}}
@php
    $axes = \Torgash\Shipping\Models\ShippingRateRow::AXES;
    // Money is typed in major units everywhere in admin, so the order-total
    // axis needs no suffix; the other two are not obvious without one. Weight
    // is whatever the shop types weights in (Settings → Store).
    $units = ['C' => '', 'W' => units()->weightLabel(), 'I' => __('pcs')];
    $target = '#rate-zone-'.$zone->id;

    // A rate may be priced in a currency the catalogue is not kept in — the
    // ordinary case for a carrier that bills hryvnia in a dollar shop. Empty
    // means the shop's own currency, and the symbol follows the choice.
    $currencies = \App\Models\Currency::where('enabled', true)->orderBy('code')->get();
    $symbol = \Torgash\Shipping\Money::symbol($form['currency'] ?: null);
@endphp
<tr id="rate-zone-{{ $zone->id }}" class="bg-base-200/40">
    <td colspan="6" class="p-4">
        <form hx-put="{{ route('admin.shipping.methods.rates.save', [$method, $zone]) }}"
              hx-target="{{ $target }}" hx-swap="outerHTML"
              class="flex flex-col gap-4">

            <div class="flex items-center gap-3">
                <div class="flex-1 min-w-0">
                    <div class="text-xs opacity-50 leading-none">{{ __('Rate in zone') }}</div>
                    <div class="font-semibold truncate leading-tight">{{ $zone->t('name', admin_content_locale()) }}</div>
                </div>
                <button type="button" class="btn btn-ghost btn-sm"
                        hx-get="{{ route('admin.shipping.methods.rates.show', [$method, $zone]) }}"
                        hx-target="{{ $target }}" hx-swap="outerHTML">
                    {{ __('Cancel') }}
                </button>
                <button class="btn btn-primary btn-sm">{{ __('Save rate') }}</button>
            </div>

            @if ($error)
                <div class="alert alert-error text-sm">{{ $error }}</div>
            @endif

            @if (in_array($method->price_mode, ['carrier', 'free', 'quote'], true))
                <div class="alert text-sm">
                    <x-icon name="info" size="sm" />
                    <span>
                        @switch($method->price_mode)
                            @case('carrier')
                                {{ __('This method is priced by its carrier, so the amounts below are not charged: a rate here decides where the method is offered at all, and its delivery time is used when the carrier gives none.') }}
                                @break
                            @case('free')
                                {{ __('This method is always free, so the amounts below are not charged: a rate here decides where it is offered.') }}
                                @break
                            @default
                                {{ __('This method is priced by a human after the order is placed, so the amounts below are not charged: a rate here decides where it is offered.') }}
                        @endswitch
                        {{ __('They are used if you switch the pricing mode back.') }}
                    </span>
                </div>
            @endif

            <div class="grid md:grid-cols-3 gap-4">
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Base rate') }} *</legend>
                    <label class="input input-bordered w-full">
                        <input name="base_rate" type="number" step="0.01" min="0" required class="grow" value="{{ $form['base_rate'] }}">
                        @if ($symbol)
                            <span class="opacity-50">{{ $symbol }}</span>
                        @endif
                    </label>
                    <div class="text-xs opacity-50">{{ __('Charged always, before any band below.') }}</div>
                </fieldset>

                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Priced in') }}</legend>
                    <select name="currency" class="select select-bordered w-full">
                        <option value="">{{ __("The shop's currency") }}</option>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->code }}" @selected($form['currency'] === $currency->code)>
                                {{ $currency->code }} {{ $currency->symbol }}
                            </option>
                        @endforeach
                    </select>
                    <div class="text-xs opacity-50">{{ __("Converted to the shop's currency at the current rate when the order is placed.") }}</div>
                </fieldset>
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Free above') }}</legend>
                    <input name="free_over" type="number" step="0.01" min="0"
                           class="input input-bordered w-full"
                           placeholder="{{ __('leave empty — never free') }}" value="{{ $form['free_over'] }}">
                    <div class="text-xs opacity-50">{{ __('An order this large ships free here — bands included.') }}</div>
                </fieldset>
            </div>

            <div class="grid md:grid-cols-3 gap-4">
                @foreach ($languages as $code => $label)
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">
                            {{ __('Delivery time') }} <span class="opacity-40 font-mono">{{ $code }}</span>
                        </legend>
                        <input name="delivery_time[{{ $code }}]" class="input input-bordered w-full"
                               placeholder="1–3 {{ __('days') }}" value="{{ $form['delivery_time'][$code] ?? '' }}">
                    </fieldset>
                @endforeach
            </div>

            <label class="label cursor-pointer justify-start gap-2 p-0">
                <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success"
                       @checked($form['enabled'])>
                <span class="label-text">{{ __('Enabled') }}</span>
            </label>

            <div class="border-t border-base-300 pt-3 flex flex-col gap-3"
                 x-data="{
                     rows: {{ \Illuminate\Support\Js::from($rows) }},
                     units: {{ \Illuminate\Support\Js::from($units) }},
                     axes: {{ \Illuminate\Support\Js::from(array_map(fn ($label) => __($label), $axes)) }},
                     add(axis) { this.rows.push({ axis, range_from: 0, range_to: '', value_type: 'fixed', value: 0, per_unit: false }) },
                     perUnitAxis(axis) { return axis === 'W' || axis === 'I' },
                 }">
                <div class="text-xs font-semibold uppercase tracking-wide opacity-50">{{ __('Bands') }}</div>
                <div class="text-xs opacity-60 -mt-2">
                    {{ __('At most one band per axis is charged — the one the order falls into — and the three are added to the base rate. A gap between bands charges nothing extra.') }}
                    @if (($method->charge_on ?? 'actual') !== 'actual')
                        {{-- The bands read a different weight than the scales
                             say, and that is set on the method, not here. --}}
                        <span class="text-warning">
                            {{ __('Weight bands here are read as :what (÷ :divisor).', [
                                'what' => mb_strtolower(__(\Torgash\Shipping\Models\ShippingMethod::CHARGE_ON[$method->charge_on])),
                                'divisor' => $method->volumetric_divisor,
                            ]) }}
                        </span>
                    @endif
                </div>

                <template x-for="(row, index) in rows" :key="index">
                    <div class="flex flex-wrap gap-2 items-end border-b border-base-200 pb-3">
                        <input type="hidden" :name="`rows[${index}][axis]`" :value="row.axis">

                        <div class="w-28 pb-2">
                            <span class="badge badge-neutral badge-sm" x-text="axes[row.axis]"></span>
                        </div>

                        <fieldset class="fieldset p-0 w-36">
                            <legend class="fieldset-legend">{{ __('From') }}</legend>
                            <label class="input input-bordered w-full">
                                <input type="number" step="any" min="0" class="grow min-w-0"
                                       :name="`rows[${index}][range_from]`" x-model="row.range_from">
                                <span class="opacity-50" x-show="units[row.axis]" x-text="units[row.axis]"></span>
                            </label>
                        </fieldset>

                        <fieldset class="fieldset p-0 w-36">
                            <legend class="fieldset-legend">{{ __('To') }}</legend>
                            <input type="number" step="any" min="0" class="input input-bordered w-full"
                                   placeholder="{{ __('and up') }}"
                                   :name="`rows[${index}][range_to]`" x-model="row.range_to">
                        </fieldset>

                        <fieldset class="fieldset p-0 w-36">
                            <legend class="fieldset-legend">{{ __('Charge') }}</legend>
                            <input type="number" step="any" class="input input-bordered w-full"
                                   :name="`rows[${index}][value]`" x-model="row.value">
                        </fieldset>

                        <fieldset class="fieldset p-0 w-40">
                            <legend class="fieldset-legend">{{ __('as') }}</legend>
                            <select class="select select-bordered w-full"
                                    :name="`rows[${index}][value_type]`" x-model="row.value_type">
                                <option value="fixed">{{ __('fixed amount') }}</option>
                                <option value="percent">{{ __('% of order') }}</option>
                            </select>
                        </fieldset>

                        <label class="label cursor-pointer gap-2 pb-3" x-show="perUnitAxis(row.axis)" x-cloak>
                            <input type="checkbox" value="1" class="checkbox checkbox-sm"
                                   :name="`rows[${index}][per_unit]`" x-model="row.per_unit">
                            <span class="label-text text-xs"
                                  x-text="row.axis === 'W' ? '{{ __('per :unit', ['unit' => units()->weightLabel()]) }}' : '{{ __('per item') }}'"></span>
                        </label>

                        <button type="button" class="btn btn-ghost btn-sm btn-square text-error mb-1.5" @click="rows.splice(index, 1)"
                                title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                            <x-icon name="trash-2" />
                        </button>
                    </div>
                </template>

                <div class="flex flex-wrap gap-2 items-center">
                    @foreach ($axes as $axis => $label)
                        <button type="button" class="btn btn-sm" @click="add('{{ $axis }}')">
                            + {{ __($label) }}
                        </button>
                    @endforeach
                    <span class="text-xs opacity-50" x-show="rows.length === 0" x-cloak>
                        {{ __('No bands: this rate is the base rate, whatever the order.') }}
                    </span>
                </div>
            </div>
        </form>
    </td>
</tr>
