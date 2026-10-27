@extends('admin.layout')

@php $formLocale = admin_content_locale(); @endphp
@section('title', $rubric->exists ? ($rubric->t('name', $formLocale) ?: __('Rubric')) : __('New rubric'))
@section('own_heading', '1')

@section('content')
    <form method="POST" class="flex flex-col gap-4"
          action="{{ $rubric->exists ? route('admin.blog.rubrics.update', $rubric) : route('admin.blog.rubrics.store') }}">
        @csrf
        @if ($rubric->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.blog.rubrics.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Blog rubrics') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ __('Blog rubric') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $rubric->t('name', $formLocale) ?: __('New rubric') }}</div>
            </div>
            @if ($rubric->exists)
                <button type="submit" form="rubric-delete" class="btn btn-ghost btn-sm text-error">{{ __('Delete') }}</button>
            @endif
            <button type="submit" name="after_save" value="close" class="btn btn-ghost btn-sm">{{ __('Save and close') }}</button>
            <button class="btn btn-primary btn-sm">{{ $rubric->exists ? __('Save') : __('Create') }}</button>
        </div>

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
                        <x-admin.translatable-field name="name" :label="__('Name')" :value="$rubric->name ?? []" required />
                        <x-admin.translatable-field name="description" :label="__('Description')" :value="$rubric->description ?? []" textarea :rows="3" />
                        <p class="text-xs opacity-60 -mt-2">{{ __('Shown over the rubric\'s feed; also its meta description when none is written.') }}</p>
                    </div>
                </section>

                <x-seo.fields :record="$rubric" :url-preview="$rubric->exists ? $rubric->url() : null" />
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300" x-data="{ color: @js(old('color', $rubric->color ?? '#2563eb')), icon: @js(old('icon', $rubric->icon)) }">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('The plate') }}</h3>
                        <div class="flex items-center gap-2">
                            <span class="badge text-white border-0" :style="`background-color: ${color}`">{{ $rubric->t('name', $formLocale) ?: __('Rubric') }}</span>
                        </div>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Colour') }}</legend>
                            <input type="color" name="color" x-model="color" class="w-16 h-10 rounded cursor-pointer">
                        </fieldset>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Icon') }}</legend>
                            <input name="icon" x-model="icon" class="input input-bordered w-full font-mono" placeholder="smartphone">
                            <p class="label text-xs opacity-60 whitespace-normal">{!! __('A name from :link, like smartphone or gamepad-2.', ['link' => '<a href="https://lucide.dev/icons" target="_blank" rel="noopener" class="link">lucide.dev</a>']) !!}</p>
                        </fieldset>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $rubric->enabled ?? true))>
                            <span class="label-text">{{ __('Shown') }}</span>
                        </label>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Address') }}</legend>
                            <input name="slug" value="{{ old('slug', $rubric->slug) }}" class="input input-bordered w-full" placeholder="{{ __('made from the name') }}">
                        </fieldset>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                            <input type="number" name="sort" value="{{ old('sort', $rubric->sort ?? 0) }}" class="input input-bordered w-full">
                        </fieldset>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($rubric->exists)
        <form id="rubric-delete" method="POST" action="{{ route('admin.blog.rubrics.destroy', $rubric) }}"
              data-confirm="{{ __('Delete this rubric? Its articles stay, only out of it.') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
