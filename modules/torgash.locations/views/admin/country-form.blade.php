@extends('admin.layout')

@section('title', $country->exists ? __('Edit country') : __('Add country'))
@section('own_heading', '1')

@section('content')
    <form method="POST" action="{{ $country->exists ? route('admin.locations.countries.update', $country) : route('admin.locations.countries.store') }}"
          class="flex flex-col gap-4">
        @csrf
        @if ($country->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.locations.countries') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to countries') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $country->exists ? __('Edit country') : __('Add country') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $country->exists ? $country->t('name', admin_content_locale()) : __('Add country') }}</div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-64 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($country->exists)
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="delete-country" class="text-error"><x-icon name="trash-2" /> {{ __('Remove from the list') }}</button></li>
                    @endif
                </ul>
            </div>
            <a href="{{ route('admin.locations.countries') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $country->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error text-sm flex-col items-start gap-1">
                @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif

        @if ($country->exists && ! $country->edited && $country->source === 'import')
            <div class="alert alert-info text-sm">
                {{ __('Saving marks this country as yours: from then on re-importing the world list only fills in what is still empty and adds name languages you do not have yet.') }}
            </div>
        @endif

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('Names') }}</h3>
                <div class="text-xs opacity-60 -mt-2">
                    {{ __('A pack carries only the languages it was built with. Anything the shop speaks and the pack does not is filled in here.') }}
                </div>

                <div class="grid md:grid-cols-3 gap-4">
                    @foreach ($languages as $code => $label)
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">
                                {{ $label }} <span class="opacity-40 font-mono">{{ $code }}</span>
                            </legend>
                            <input name="names[{{ $code }}]"
                                   value="{{ old("names.{$code}", $country->name[$code] ?? '') }}"
                                   class="input input-bordered w-full"
                                   @required($code === content_base_locale())>
                        </fieldset>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('Codes') }}</h3>

                <div class="grid md:grid-cols-4 gap-4">
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('ISO code') }}</legend>
                        <input name="code" maxlength="2" value="{{ old('code', $country->code) }}"
                               class="input input-bordered w-full uppercase font-mono" required>
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('ISO3') }}</legend>
                        <input name="code3" maxlength="3" value="{{ old('code3', $country->code3) }}"
                               class="input input-bordered w-full uppercase font-mono">
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Numeric') }}</legend>
                        <input name="numeric_code" maxlength="3" value="{{ old('numeric_code', $country->numeric_code) }}"
                               class="input input-bordered w-full font-mono">
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Continent') }}</legend>
                        <input name="continent" maxlength="2" value="{{ old('continent', $country->continent) }}"
                               class="input input-bordered w-full uppercase font-mono">
                    </fieldset>
                </div>

                <div class="grid md:grid-cols-4 gap-4">
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Currency') }}</legend>
                        <input name="currency" maxlength="3" value="{{ old('currency', $country->currency) }}"
                               class="input input-bordered w-full uppercase font-mono">
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Phone prefix') }}</legend>
                        <input name="phone" value="{{ old('phone', $country->phone) }}" class="input input-bordered w-full font-mono">
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Postal code format') }}</legend>
                        <input name="postal_format" value="{{ old('postal_format', $country->postal_format) }}"
                               class="input input-bordered w-full font-mono" placeholder="#####">
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Postal code pattern') }}</legend>
                        <input name="postal_regex" value="{{ old('postal_regex', $country->postal_regex) }}"
                               class="input input-bordered w-full font-mono text-xs">
                    </fieldset>
                </div>
                <div class="text-xs opacity-50 -mt-2">
                    {{ __('The prefix and the pattern are what the address form will use to help with a phone number and check a postal code.') }}
                </div>

                <label class="label cursor-pointer justify-start gap-2 p-0">
                    <input type="checkbox" name="enabled" value="1" class="checkbox checkbox-sm checkbox-primary"
                           @checked(old('enabled', $country->enabled ?? true))>
                    <span class="label-text">{{ __('Available at checkout') }}</span>
                </label>

                @if ($placeCount > 0)
                    <div class="text-xs opacity-60">
                        {{ __('Holds :count places.', ['count' => number_format($placeCount, 0, '.', ' ')]) }}
                        <a class="link" href="{{ route('admin.locations.countries.purge.confirm', $country->code) }}">
                            {{ __('Delete data…') }}
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </form>

    {{-- Beside the main form, never inside it. --}}
    @if ($country->exists)
        <form id="delete-country" method="POST" action="{{ route('admin.locations.countries.destroy', $country) }}"
              data-confirm="{{ $placeCount > 0
                            ? __('Remove :code from the list? Its :count places stay in the registry but stop being offered.', ['code' => $country->code, 'count' => number_format($placeCount, 0, '.', ' ')])
                            : __('Remove :code from the list?', ['code' => $country->code]) }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
