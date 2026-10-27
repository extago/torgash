{{-- One "where → rate" row of the tax form. Rendered for every stored row and
     once inside a <template> (index __INDEX__) that the add button clones.
     Each kind has its own control; only the one for the chosen kind is
     enabled, so nothing else is submitted. Variables are named distinctively:
     an @include inherits the caller's whole scope. --}}
@php
    $rateKind = $rateRow?->kind ?? 'country';
    $rateValue = $rateRow?->value ?? '';
    $rateNumber = $rateRow
        ? ($rateRow->rate_amount !== null ? $rateRow->rate_amount / 100 : $rateRow->rate_percent)
        : '';
@endphp
<tr x-data="{ kind: '{{ $rateKind }}', charged: '{{ ($rateRow?->charged ?? true) ? '1' : '0' }}' }">
    <td class="w-40">
        <select name="rates[{{ $rateIndex }}][kind]" x-model="kind" class="select select-bordered w-full">
            <option value="country">{{ __('Country') }}</option>
            <option value="state">{{ __('Region') }}</option>
            <option value="postal">{{ __('Postal code') }}</option>
        </select>
    </td>
    <td>
        <div x-show="kind === 'country'">
            @if ($rateCountries !== [])
                <select name="rates[{{ $rateIndex }}][country]" :disabled="kind !== 'country'" class="select select-bordered w-full">
                    @foreach ($rateCountries as $country)
                        <option value="{{ $country['code'] }}" @selected($rateKind === 'country' && $rateValue === $country['code'])>{{ $country['name'] }}</option>
                    @endforeach
                </select>
            @else
                <input name="rates[{{ $rateIndex }}][country]" :disabled="kind !== 'country'" maxlength="2"
                       value="{{ $rateKind === 'country' ? $rateValue : '' }}" placeholder="DE"
                       class="input input-bordered w-24 font-mono uppercase">
            @endif
        </div>
        <div x-show="kind === 'state'" x-cloak>
            @if ($rateRegions !== [])
                <select name="rates[{{ $rateIndex }}][state]" :disabled="kind !== 'state'" class="select select-bordered w-full">
                    @foreach (collect($rateRegions)->groupBy('country_name') as $countryName => $regionsOfCountry)
                        <optgroup label="{{ $countryName }}">
                            @foreach ($regionsOfCountry as $region)
                                <option value="{{ $region['value'] }}" @selected($rateKind === 'state' && $rateValue === $region['value'])>{{ $region['name'] }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            @else
                <input name="rates[{{ $rateIndex }}][state]" :disabled="kind !== 'state'"
                       value="{{ $rateKind === 'state' ? $rateValue : '' }}" placeholder="US:CA"
                       class="input input-bordered w-32 font-mono">
            @endif
        </div>
        <div x-show="kind === 'postal'" x-cloak>
            <input name="rates[{{ $rateIndex }}][postal]" :disabled="kind !== 'postal'"
                   value="{{ $rateKind === 'postal' ? $rateValue : '' }}" placeholder="98101, 98*, 98?78"
                   class="input input-bordered w-full font-mono">
        </div>
    </td>
    <td class="w-60">
        <select name="rates[{{ $rateIndex }}][charged]" x-model="charged" class="select select-bordered w-full">
            <option value="1">{{ __('Charged at') }}</option>
            <option value="0">{{ __('Not charged') }}</option>
        </select>
    </td>
    <td class="w-36">
        <label class="input input-bordered w-full" x-show="charged === '1'">
            <input name="rates[{{ $rateIndex }}][rate]" type="number" step="0.0001" min="0"
                   value="{{ $rateNumber }}" :disabled="charged !== '1'" class="grow">
            <span class="opacity-50" x-text="rateType === 'percent' ? '%' : '{{ shop_currency() }}'"></span>
        </label>
    </td>
    <td class="w-8 text-right">
        <button type="button" class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Remove') }}"
                @click="$el.closest('tr').remove()">
            <x-icon name="trash-2" />
        </button>
    </td>
</tr>
