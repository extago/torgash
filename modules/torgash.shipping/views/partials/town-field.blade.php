{{-- Where to deliver: a country, then a town in it.

     With the locations registry installed the town is a type-ahead that also
     yields a place id, which is what zones actually match on. Without that
     module it is a plain text field and there is no country list to offer:
     checkout does not depend on it, and a shop without one still matches zones
     on the postal code the customer types.

     The country box appears only when the shop actually serves more than one.
     A single-country shop gets a hidden field instead — the answer is not in
     doubt, but zones still need it, and asking a question with one possible
     answer is noise.

     The visible town input carries no name. It shows the disambiguating label
     ("Ivanivka (Bashtanka district)") while a hidden field posts the bare town
     name, because that label belongs in a picker, not on an invoice. The hidden
     fields also carry a server-rendered value, so the form still posts a sane
     address if Alpine never starts. --}}
@php
    $suggest = \Illuminate\Support\Facades\Route::has('places.suggest') ? route('places.suggest') : null;
    $countries = hook_filter('checkout.location.countries', []);
    $inputClass = 'input input-bordered w-full '.($size ?? '');
    $required = $required ?? false;
    $town = $town ?? '';
    $placeId = $placeId ?? '';
    // One country needs no question; none means the registry is not installed.
    $only = count($countries) === 1 ? $countries[0]['code'] : null;
    $country = ($country ?? null) ?: $only;
@endphp

@if ($suggest)
    @include('torgash.locations::partials.place-picker-script')

    {{-- `choose-place` is how anything else on the page names a town: a
         carrier's "the nearest branch is in Kremenchuk" offers it as a button,
         and the box fills in and re-quotes exactly as if it had been typed and
         picked. The event carries what a suggestion carries — {id, label,
         name} — so there is one way in, not two. --}}
    <div x-data="placePicker({
             endpoint: '{{ $suggest }}',
             label: @js($town), name: @js($town),
             placeId: @js($placeId), country: @js($country ?? ''),
         })" class="flex flex-col gap-2"
         @choose-place.window="pick($event.detail)">

        @if (count($countries) > 1)
            <select name="country" class="select select-bordered w-full {{ $size ?? '' }}"
                    @change="setCountry($event.target.value)">
                <option value="">{{ __('Select a country') }}</option>
                @foreach ($countries as $option)
                    <option value="{{ $option['code'] }}" @selected($country === $option['code'])>{{ $option['name'] }}</option>
                @endforeach
            </select>
        @elseif ($only)
            <input type="hidden" name="country" value="{{ $only }}">
        @endif

        <div class="relative">
            <input type="text" class="{{ $inputClass }}" autocomplete="off" @required($required) aria-label="{{ __('Town') }}"
                   value="{{ $town }}" :value="label"
                   @input="search($event.target.value)" @focus="open = true"
                   placeholder="{{ __('Start typing your town') }}">
            <input type="hidden" name="city" value="{{ $town }}" :value="name">
            <input type="hidden" name="place_id" value="{{ $placeId }}" :value="placeId">

            @include('torgash.locations::partials.place-suggestions')
        </div>
    </div>
@else
    <input type="text" name="city" value="{{ $town }}" class="{{ $inputClass }}" @required($required)>
@endif
