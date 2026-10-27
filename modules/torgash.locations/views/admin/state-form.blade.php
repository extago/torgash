@extends('admin.layout')

@section('title', $state->exists ? __('Edit region') : __('Add region'))
@section('own_heading', '1')

@section('content')
    <form method="POST" action="{{ $state->exists ? route('admin.locations.regions.update', $state) : route('admin.locations.regions.store') }}"
          class="flex flex-col gap-4">
        @csrf
        @if ($state->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.locations.regions') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to regions') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $state->exists ? __('Edit region') : __('Add region') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $state->exists ? $state->t('name', admin_content_locale()) : __('Add region') }}</div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-64 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($state->exists)
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="delete-region" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    @endif
                </ul>
            </div>
            <a href="{{ route('admin.locations.regions') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $state->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error text-sm flex-col items-start gap-1">
                @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif

        @if ($state->exists && $state->source === 'import' && ! $state->edited)
            <div class="alert alert-info text-sm">
                {{ __('This region came from a data pack. Saving marks it as yours, and future imports will leave it alone.') }}
            </div>
        @endif

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <div class="grid md:grid-cols-2 gap-4">
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Country code') }}</legend>
                        <input name="country" maxlength="2" value="{{ old('country', $state->country) }}"
                               class="input input-bordered w-full uppercase" placeholder="UA" required>
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Region code') }}</legend>
                        <input name="code" value="{{ old('code', $state->code) }}" class="input input-bordered w-full font-mono" required>
                        <div class="text-xs opacity-50">
                            {{ __('How the pack refers to this region. Any short string will do for a region you add yourself.') }}
                        </div>
                    </fieldset>
                </div>

                <h3 class="font-semibold">{{ __('Names') }}</h3>

                <div class="grid md:grid-cols-3 gap-4">
                    @foreach ($languages as $code => $label)
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">
                                {{ $label }} <span class="opacity-40 font-mono">{{ $code }}</span>
                            </legend>
                            <input name="names[{{ $code }}]"
                                   value="{{ old("names.{$code}", $state->name[$code] ?? '') }}"
                                   class="input input-bordered w-full"
                                   @required($code === content_base_locale())>
                        </fieldset>
                    @endforeach
                </div>

                <label class="label cursor-pointer justify-start gap-2 p-0">
                    <input type="checkbox" name="enabled" value="1" class="checkbox checkbox-sm checkbox-primary"
                           @checked(old('enabled', $state->enabled ?? true))>
                    <span class="label-text">{{ __('Available at checkout') }}</span>
                </label>

                @if ($placeCount > 0)
                    <div class="text-xs opacity-60">
                        {{ __('Holds :count places. Deleting the region keeps them — they simply lose it.', [
                            'count' => number_format($placeCount, 0, '.', ' '),
                        ]) }}
                    </div>
                @endif
            </div>
        </div>
    </form>

    {{-- Beside the main form, never inside it. --}}
    @if ($state->exists)
        <form id="delete-region" method="POST" action="{{ route('admin.locations.regions.destroy', $state) }}"
              data-confirm="{{ $placeCount > 0
                            ? __('Delete this region? Its :count places stay, without a region.', ['count' => number_format($placeCount, 0, '.', ' ')])
                            : __('Delete this region?') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
