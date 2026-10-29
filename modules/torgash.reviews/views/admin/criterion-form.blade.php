@extends('admin.layout')

@section('title', $criterion->exists ? __('Edit criterion') : __('New criterion'))
@section('own_heading', '1')

@section('content')
    @php
        $criterionSelected = collect(old('categories', $criterion->exists ? $criterion->categories->pluck('category_id')->all() : []))
            ->map(fn ($id) => (string) $id)->all();
    @endphp

    <form method="POST" class="flex flex-col gap-4"
          action="{{ $criterion->exists ? route('admin.reviews.criteria.update', $criterion) : route('admin.reviews.criteria.store') }}">
        @csrf
        @if ($criterion->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.reviews.criteria.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to criteria') }}" aria-label="{{ __('Back to criteria') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $criterion->exists ? __('Edit criterion') : __('New criterion') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $criterion->exists ? ($criterion->label() ?: $criterion->code) : __('New criterion') }}</div>
            </div>
            @if ($criterion->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="criterion-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ route('admin.reviews.criteria.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $criterion->exists ? __('Save') : __('Create') }}</button>
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
                        <h3 class="font-semibold">{{ __('Basics') }}</h3>
                        <x-admin.translatable-field name="name" label="{{ __('Name') }}" :value="$criterion->name ?? []" required />
                        <fieldset class="fieldset p-0 max-w-xs"><legend class="fieldset-legend">{{ __('Code') }}</legend>
                            <input name="code" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('code', $criterion->code) }}" class="input input-bordered w-full font-mono" placeholder="{{ __('from the name') }}">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Latin letters, digits and underscores; made from the name when empty. Imports and the API refer to the criterion by it.') }}</p>
                        </fieldset>
                    </div>
                </section>

                {{-- Where it is asked: a checklist of the category tree, with a
                     box that narrows it — a catalogue of three hundred
                     categories is not scrolled through. --}}
                <section class="card bg-base-100 border border-base-300"
                         x-data="{ filter: '', picked: {{ \Illuminate\Support\Js::from($criterionSelected) }} }">
                    <div class="card-body gap-3">
                        <div class="flex items-start justify-between gap-3 flex-wrap">
                            <div>
                                <h3 class="font-semibold">{{ __('Asked about') }}</h3>
                                <p class="text-xs opacity-60 mt-1" x-show="!picked.length">{{ __('No category ticked: asked about every product.') }}</p>
                                <p class="text-xs opacity-60 mt-1" x-show="picked.length" x-cloak>
                                    {{ __('Asked about products of the ticked categories and of everything under them.') }}
                                </p>
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm" x-show="picked.length" x-cloak @click="picked = []">{{ __('Clear') }}</button>
                        </div>
                        <label class="input input-bordered w-full max-w-md gap-2">
                            <x-icon name="search" size="sm" class="opacity-50" />
                            <input type="search" x-model="filter" class="grow" placeholder="{{ __('Find a category…') }}">
                        </label>
                        <div class="max-h-96 overflow-y-auto border border-base-300 rounded-box p-2 flex flex-col">
                            @foreach ($categories as $categoryId => $categoryLabel)
                                <label class="flex items-center gap-2 rounded px-2 py-1 hover:bg-base-200 cursor-pointer"
                                       x-show="!filter || @js(mb_strtolower(trim($categoryLabel))).includes(filter.toLowerCase())">
                                    <input type="checkbox" name="categories[]" value="{{ $categoryId }}" class="checkbox checkbox-sm" x-model="picked">
                                    <span class="whitespace-pre text-sm">{{ $categoryLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $criterion->enabled ?? true))>
                            <span class="label-text">{{ __('Asked') }}</span>
                        </label>
                        <p class="text-xs opacity-60">{{ __('Switched off, it is not asked any more; scores already given stay in the reviews and the averages.') }}</p>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                            <input type="number" name="sort" min="0" value="{{ old('sort', $criterion->sort ?? 0) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('The order of the questions in the review form.') }}</p>
                        </fieldset>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($criterion->exists)
        <form method="POST" id="criterion-delete" action="{{ route('admin.reviews.criteria.destroy', $criterion) }}" class="hidden"
              data-confirm="{{ __('Delete this criterion? Scores already given keep their numbers.') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
