@extends('admin.layout')

@php
    $templateName = $template->subject ? $subjects->label($template->subject) : __('New template');
@endphp

@section('title', $template->exists ? __('Template').': '.$templateName : __('New template'))
@section('own_heading', '1')

@section('content')
    <form method="POST" id="seo-template-form"
          action="{{ $template->exists ? route('admin.seo.update', $template) : route('admin.seo.store') }}"
          class="flex flex-col gap-4">
        @csrf
        @if ($template->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.seo.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to templates') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $template->exists ? __('SEO template') : __('New template') }}</div>
                <div class="font-semibold truncate leading-tight">
                    {{ $template->exists ? $templateName : __('New template') }}
                </div>
            </div>
            @if ($template->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li><button type="submit" form="seo-template-duplicate"><x-icon name="copy" /> {{ __('Duplicate') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="seo-template-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.seo.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $template->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <div class="flex items-center gap-3 flex-wrap">
                            <h3 class="font-semibold grow">{{ __('What it says') }}</h3>
                            {{-- The groups that answer about the PAGE, not the
                                 whole registry: the icon tokens alone would be
                                 fifty lines of noise in a box a person is meant
                                 to read. Everything else a block can use still
                                 works here. --}}
                            <x-admin.placeholders name="seo-template" :groups="['page', 'shop', 'product']"
                                :trigger="__('What you can put in a template')"
                                trigger-class="btn btn-ghost btn-sm gap-1.5" />
                        </div>
                        <p class="text-xs opacity-60 -mt-2">{{ __('A template is ordinary text with {tokens} in it — the shop fills them in for each page.') }}</p>

                        <x-admin.translatable-field name="title" :label="__('Page title')" :value="$template->title ?? []"
                            placeholder="{product} — {category|lower} | {storefront}" />

                        <x-admin.translatable-field name="description" :label="__('Meta description')" :value="$template->description ?? []"
                            textarea rows="3"
                            placeholder="{product}[ — {feature:color}]. {min_price}." />

                        <x-admin.translatable-field name="h1" :label="__('Heading (H1)')" :value="$template->h1 ?? []" />
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Photographs') }}</h3>
                        <p class="text-xs opacity-60 -mt-2">
                            {{ __('Used for photographs nobody has captioned — the alt text is what a reader who cannot see the page is told. {image_number} is blank for the first photograph.') }}
                        </p>

                        <x-admin.translatable-field name="image_alt" :label="__('Image alt text')" :value="$template->image_alt ?? []"
                            placeholder="{product}[ {image_number}]" />

                        <x-admin.translatable-field name="image_title" :label="__('Image title')" :value="$template->image_title ?? []" />
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Where it applies') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $template->enabled ?? true))>
                            <span class="label-text">{{ __('Applied') }}</span>
                        </label>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Pages') }}</legend>
                            <select name="subject" class="select select-bordered w-full">
                                @foreach ($subjects->options() as $kind => $label)
                                    <option value="{{ $kind }}" @selected(old('subject', $template->subject) === $kind)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </fieldset>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Where') }}</legend>
                            <select name="scope" class="select select-bordered w-full">
                                <option value="global" @selected(old('scope', $template->scope) === 'global')>{{ __('Everywhere') }}</option>
                                @foreach ($categories as $value => $label)
                                    <option value="{{ $value }}" @selected(old('scope', $template->scope) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('A category also covers everything under it. The nearest template wins.') }}</p>
                        </fieldset>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('When') }}</legend>
                            <select name="mode" class="select select-bordered w-full">
                                <option value="fill_empty" @selected(old('mode', $template->mode) === 'fill_empty')>{{ __('Only where nothing is typed in') }}</option>
                                <option value="override" @selected(old('mode', $template->mode) === 'override')>{{ __('Always, over what is typed in') }}</option>
                                <option value="skip" @selected(old('mode', $template->mode) === 'skip')>{{ __('Do nothing here') }}</option>
                            </select>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('"Do nothing" is how one category opts out of a shop-wide template.') }}</p>
                        </fieldset>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Position') }}</legend>
                            <input name="position" type="number" value="{{ old('position', $template->position ?? 0) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Between two templates for the same pages and the same place, the lower number wins.') }}</p>
                        </fieldset>
                    </div>
                </section>

                {{-- Asked of the server as the form changes: the tokens are
                     expanded by the storefront's own code, never by a copy of
                     it in JavaScript. `_method` stays behind — the preview is
                     a POST whatever the form itself is. --}}
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold flex items-center gap-2"><x-icon name="eye" size="sm" /> {{ __('How it looks on a page') }}</h3>
                        <div id="seo-preview" class="flex flex-col gap-3"
                             hx-post="{{ route('admin.seo.preview') }}" hx-include="#seo-template-form" hx-params="not _method"
                             hx-trigger="load, input changed delay:400ms from:#seo-template-form, change from:#seo-template-form">
                            <span class="loading loading-dots loading-sm opacity-40"></span>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </form>

    {{-- Beside the form, never inside it — see docs/LESSONS.md. --}}
    @if ($template->exists)
        <form id="seo-template-duplicate" method="POST" action="{{ route('admin.seo.duplicate', $template) }}" class="hidden">@csrf</form>
        <form id="seo-template-delete" method="POST" action="{{ route('admin.seo.destroy', $template) }}" class="hidden"
              data-confirm="{{ __('Delete this template?') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
