@extends('admin.layout')

@section('title', $place->exists ? __('Edit place') : __('Add place'))
@section('own_heading', '1')

@section('content')
    <form method="POST" action="{{ $place->exists ? route('admin.locations.update', $place) : route('admin.locations.store') }}"
          class="flex flex-col gap-4">
        @csrf
        @if ($place->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.locations.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to locations') }}" aria-label="{{ __('Back to locations') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $place->exists ? __('Edit place') : __('Add place') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $place->exists ? ($place->t('name', admin_content_locale()) ?? $place->t('name', 'en')) : __('Add place') }}</div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-64 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($place->exists)
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="delete-place" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    @endif
                </ul>
            </div>
            <x-slot:actions>
                <a href="{{ route('admin.locations.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $place->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error text-sm flex-col items-start gap-1">
                @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif

        @if ($place->exists && $place->source === 'import' && ! $place->edited)
            <div class="alert alert-info text-sm">
                {{ __('This place came from a data pack. Saving marks it as yours, and future imports will leave it alone.') }}
            </div>
        @endif

        @if ($place->missing_in_source)
            <div class="alert alert-warning text-sm">
                {{ __('The last import no longer listed this place. It is kept because addresses and orders may point at it.') }}
            </div>
        @endif

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('Names') }}</h3>

                {{-- One field per language the shop has enabled, plus whatever
                     languages the imported pack brought that the shop does not
                     speak — hiding those would delete them on the next save. --}}
                <div class="grid md:grid-cols-3 gap-4">
                    @foreach ($languages as $code => $label)
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">
                                {{ $label }} <span class="opacity-40 font-mono">{{ $code }}</span>
                            </legend>
                            <input name="names[{{ $code }}]"
                                   value="{{ old("names.{$code}", $place->name[$code] ?? '') }}"
                                   class="input input-bordered w-full"
                                   @required($code === content_base_locale())>
                        </fieldset>
                    @endforeach
                </div>

                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Other spellings') }}</legend>
                    <input name="aliases" value="{{ old('aliases', $place->aliases) }}" class="input input-bordered w-full"
                           placeholder="{{ __('Old name|another spelling') }}">
                    <div class="text-xs opacity-50">
                        {{ __('Separated by |. Searched but never displayed — this is how a customer finds a town by its old name.') }}
                    </div>
                </fieldset>
            </div>
        </div>

        {{-- The cases, for the languages that have more than one.

             Every box is EMPTY by default and shows the derived form as its
             placeholder: a pack brings 192 000 towns, the rules answer for all
             of them, and the shop corrects the handful it publishes a city page
             for (PLAN §30.9). Typing nothing here is the normal state, not an
             unfinished one. --}}
        @php $caseLanguages = array_filter($languages, fn ($label, $code) => count($place->formRows($code)) > 1, ARRAY_FILTER_USE_BOTH); @endphp

        @if ($caseLanguages)
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body gap-4">
                    <h3 class="font-semibold">{{ __('Cases') }}</h3>
                    <div class="text-xs opacity-60 -mt-2">
                        {{ __('Only for a town you publish a page about: the greyed-out word is what the shop will write by itself. Fill a box to correct it.') }}
                    </div>

                    @foreach ($caseLanguages as $code => $label)
                        <div>
                            <div class="text-sm font-medium mb-2">
                                {{ $label }} <span class="opacity-40 font-mono">{{ $code }}</span>
                            </div>
                            <div class="grid md:grid-cols-3 gap-3">
                                @foreach ($place->formRows($code) as $row)
                                    <fieldset class="fieldset p-0">
                                        <legend class="fieldset-legend">{{ $row['label'] }}</legend>
                                        <input name="forms[{{ $code }}][{{ $row['case'] }}]"
                                               value="{{ old("forms.{$code}.{$row['case']}", $row['typed']) }}"
                                               placeholder="{{ $row['derived'] }}"
                                               class="input input-bordered w-full">
                                    </fieldset>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('Where it is') }}</h3>

                <div class="grid md:grid-cols-2 gap-4">
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Country code') }}</legend>
                        <input name="country" maxlength="2" value="{{ old('country', $place->country) }}"
                               class="input input-bordered w-full uppercase" placeholder="UA" required>
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Region') }}</legend>
                        <select name="state_id" class="select select-bordered w-full">
                            <option value="">{{ __('none') }}</option>
                            @foreach ($states as $state)
                                <option value="{{ $state->id }}" @selected(old('state_id', $place->state_id) == $state->id)>
                                    {{ $state->country }} · {{ $state->t('name', admin_content_locale()) }}
                                </option>
                            @endforeach
                        </select>
                    </fieldset>
                </div>

                {{-- A district is translated for the same reason a name is: it
                     is printed beside the name to tell namesakes apart, and a
                     reader who cannot read it cannot tell them apart. --}}
                <div>
                    <div class="fieldset-legend">{{ __('District') }}</div>
                    <div class="text-xs opacity-50 pb-2">{{ __('Shown next to the name to tell namesakes apart.') }}</div>
                    <div class="grid md:grid-cols-3 gap-4">
                        @foreach ($languages as $code => $label)
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">
                                    {{ $label }} <span class="opacity-40 font-mono">{{ $code }}</span>
                                </legend>
                                <input name="districts[{{ $code }}]"
                                       value="{{ old("districts.{$code}", $place->district[$code] ?? '') }}"
                                       class="input input-bordered w-full">
                            </fieldset>
                        @endforeach
                    </div>
                </div>

                <div class="grid md:grid-cols-4 gap-4">
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Type') }}</legend>
                        <select name="type" class="select select-bordered w-full">
                            @foreach (\Torgash\Locations\Models\Place::TYPES as $type => $typeLabel)
                                <option value="{{ $type }}" @selected(old('type', $place->type) === $type)>{{ __($typeLabel) }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Population') }}</legend>
                        <input name="population" type="number" min="0" value="{{ old('population', $place->population) }}" class="input input-bordered w-full">
                        <div class="text-xs opacity-50">{{ __('Orders the suggestions.') }}</div>
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Latitude') }}</legend>
                        <input name="latitude" value="{{ old('latitude', $place->latitude) }}" class="input input-bordered w-full">
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Longitude') }}</legend>
                        <input name="longitude" value="{{ old('longitude', $place->longitude) }}" class="input input-bordered w-full">
                    </fieldset>
                </div>

                <div class="grid md:grid-cols-3 gap-4">
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Postal codes from') }}</legend>
                        <input name="postal_from" value="{{ old('postal_from', $place->postal_from) }}" class="input input-bordered w-full">
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('to') }}</legend>
                        <input name="postal_to" value="{{ old('postal_to', $place->postal_to) }}" class="input input-bordered w-full">
                    </fieldset>
                    <label class="label cursor-pointer justify-start gap-2 p-0 self-end pb-2">
                        <input type="checkbox" name="enabled" value="1" class="checkbox checkbox-sm"
                               @checked(old('enabled', $place->enabled ?? true))>
                        <span class="label-text">{{ __('Available at checkout') }}</span>
                    </label>
                </div>
            </div>
        </div>
    </form>

    {{-- Beside the main form, never inside it: a nested form loses its method
         and the delete leaks into the save. --}}
    @if ($place->exists)
        <form id="delete-place" method="POST" action="{{ route('admin.locations.destroy', $place) }}"
              data-confirm="{{ __('Delete this place?') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
