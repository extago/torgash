@extends('admin.layout')

@section('title', $language->exists ? $language->name : __('New language'))
@section('own_heading', '1')

@section('content')
    @php
        // Rows as the form draws them: what was just posted, else what is
        // stored, else nothing — a list of {name, example}.
        $languageLocked = $language->exists ? ($inUse[$language->code] ?? null) : null;
        $caseRows = old('cases', array_values(\App\Core\Support\GrammaticalCases::normalise((array) ($language->cases ?? []))));
    @endphp

    <form method="POST" class="flex flex-col gap-4"
          action="{{ $language->exists ? route('admin.languages.update', $language) : route('admin.languages.store') }}">
        @csrf
        @if ($language->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.languages.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to languages') }}" aria-label="{{ __('Back to languages') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $language->exists ? __('Edit language') : __('New language') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $language->exists ? $language->name : __('New language') }}</div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($language->exists && ! $languageLocked)
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="language-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    @endif
                </ul>
            </div>
            <x-slot:actions>
                <a href="{{ route('admin.languages.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $language->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Language') }}</h3>
                        <div class="grid md:grid-cols-3 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Code') }} *</legend>
                                <input name="code" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('code', $language->code) }}" required maxlength="5" class="input input-bordered w-full font-mono" placeholder="en" @readonly($language->exists)>
                                <p class="label text-xs opacity-60 whitespace-normal">
                                    {{ $language->exists
                                        ? __('Cannot be changed: every translated text is stored under it.')
                                        : __('Two letters, ISO 639-1: it is the prefix of every address in this language.') }}
                                </p>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name in English') }} *</legend>
                                <input name="name" value="{{ old('name', $language->name) }}" required class="input input-bordered w-full" placeholder="Polish">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name in the language itself') }} *</legend>
                                <input name="native_name" value="{{ old('native_name', $language->native_name) }}" required class="input input-bordered w-full" placeholder="Polski">
                            </fieldset>
                        </div>
                    </div>
                </section>

                {{-- The language's grammatical cases — a property of the
                     language, so any one of them gets cases without code.
                     Numbered by order: the number is what a template writes. --}}
                <section class="card bg-base-100 border border-base-300"
                         x-data="{ rows: @js(array_values(array_map(fn ($row) => ['name' => (string) ($row['name'] ?? ''), 'example' => (string) ($row['example'] ?? '')], (array) $caseRows))) }">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Grammatical cases') }}</h3>
                        <p class="text-sm opacity-70 max-w-3xl">
                            {{ __('For a language whose words change with the sentence — "in the city", "no city". A template picks a case by its number here: {location:6} is the sixth row. Leave it empty for a language with one form, like English.') }}
                        </p>

                        <template x-for="(row, index) in rows" :key="index">
                            <div class="flex items-center gap-2">
                                <span class="w-8 text-center font-mono text-sm opacity-60" x-text="index + 1"></span>
                                <input class="input input-bordered w-full max-w-56" :name="`cases[${index}][name]`" x-model="row.name"
                                       placeholder="{{ __('Name of the case') }}" aria-label="{{ __('Name of the case') }}">
                                <input class="input input-bordered w-full" :name="`cases[${index}][example]`" x-model="row.example"
                                       placeholder="{{ __('An example that shows it') }}" aria-label="{{ __('An example that shows it') }}">
                                <button type="button" class="btn btn-ghost btn-sm btn-square" :disabled="index === 0"
                                        @click="rows.splice(index - 1, 0, rows.splice(index, 1)[0])" title="{{ __('Move up') }}" aria-label="{{ __('Move up') }}">
                                    <x-icon name="arrow-up" />
                                </button>
                                <button type="button" class="btn btn-ghost btn-sm btn-square text-error" @click="rows.splice(index, 1)"
                                        title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                                    <x-icon name="trash-2" />
                                </button>
                            </div>
                        </template>

                        <div class="flex items-center gap-3 flex-wrap">
                            <button type="button" class="btn btn-sm" @click="rows.push({ name: '', example: '' })">+ {{ __('Add a case') }}</button>
                            <span class="text-xs opacity-60" x-show="rows.length < 2">{{ __('One form: this language does not decline.') }}</span>
                        </div>
                        <p class="text-xs opacity-60">
                            {{ __('Declaring the cases is enough for templates and for the forms a shop types by hand. Filling them in by themselves needs rules for the language — the locations module has them for Russian and Ukrainian, and a module can add more.') }}
                        </p>
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        @if ($languageLocked)
                            {{-- A disabled toggle reads as "off"; the locked answer is written out. --}}
                            <span class="badge badge-sm badge-success gap-1 w-fit"><x-icon name="check" size="sm" /> {{ __('Enabled') }}</span>
                            <p class="text-xs opacity-60">{{ $languageLocked }}</p>
                        @else
                            <label class="label cursor-pointer justify-start gap-3">
                                <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $language->enabled ?? true))>
                                <span class="label-text">{{ __('Enabled') }}</span>
                            </label>
                        @endif
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                            <input name="sort" type="number" value="{{ old('sort', $language->sort ?? 0) }}" class="input input-bordered w-full">
                        </fieldset>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($language->exists && ! $languageLocked)
        <form id="language-delete" method="POST" action="{{ route('admin.languages.destroy', $language) }}"
              data-confirm="{{ __('Delete :name?', ['name' => $language->name]) }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
