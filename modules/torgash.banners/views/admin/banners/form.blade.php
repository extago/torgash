@extends('admin.layout')

@section('title', $banner->exists ? $banner->name : __('New banner'))
@section('own_heading', '1')

@section('content')
    @php
        $formTemplate = (int) old('template_id', $banner->template_id);
        $formValues = (array) $banner->values;
        $formShapes = $templates->mapWithKeys(fn ($template) => [$template->id => $template->shapes()])->all();
        $formLocal = fn ($date) => $date ? $date->format('Y-m-d\TH:i') : '';
        $shapeNames = ['wide' => __('Wide slide'), 'card' => __('Upright card'), 'strip' => __('Low strip')];
    @endphp

    <form method="POST" enctype="multipart/form-data"
          action="{{ $banner->exists ? route('admin.banners.update', $banner) : route('admin.banners.store') }}"
          class="flex flex-col gap-4"
          x-data="{ tpl: {{ $formTemplate }}, shapes: @js($formShapes), fits(shape) { return (this.shapes[this.tpl] || []).includes(shape) } }">
        @csrf
        @if ($banner->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.banners.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to banners') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $banner->exists ? __('Edit banner') : __('New banner') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $banner->exists ? $banner->name : __('New banner') }}</div>
            </div>
            @if ($banner->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li><button type="submit" form="banner-duplicate"><x-icon name="copy" /> {{ __('Duplicate') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="banner-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.banners.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $banner->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm flex-col items-start gap-1">
                @foreach ($errors->all() as $formError)
                    <div>{{ $formError }}</div>
                @endforeach
            </div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                {{-- The template first: its fields are the form below. --}}
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold">{{ __('Template') }}</h3>
                            @can('banners.templates')
                                <a href="{{ route('admin.banners.templates.index') }}" class="link link-hover text-sm opacity-70">{{ __('Templates') }} →</a>
                            @endcan
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($templates as $choice)
                                <label class="flex gap-3 items-start rounded-box border p-3 cursor-pointer transition-colors"
                                       :class="tpl === {{ $choice->id }} ? 'border-primary bg-primary/5' : 'border-base-300 hover:border-base-content/30'">
                                    <input type="radio" name="template_id" value="{{ $choice->id }}" x-model.number="tpl" class="radio radio-sm radio-primary mt-0.5">
                                    <span class="min-w-0">
                                        <span class="block font-medium">{{ $choice->label() }}</span>
                                        <span class="flex flex-wrap gap-1 mt-1">
                                            @foreach ($choice->shapes() as $choiceShape)
                                                <span class="badge badge-ghost badge-sm">{{ $shapeNames[$choiceShape] }}</span>
                                            @endforeach
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Content') }}</h3>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name') }} *</legend>
                            <input name="name" value="{{ old('name', $banner->name) }}" required autocomplete="off" maxlength="190"
                                   class="input input-bordered w-full" placeholder="{{ __('iPhone 17 on the home page, instalments…') }}">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('For your own list — it is never printed on the page.') }}</p>
                        </fieldset>

                        {{-- One template's fields at a time: two templates may both
                             have a "title", and a hidden copy of the other one's
                             would be posted too. --}}
                        @foreach ($templates as $choice)
                            <template x-if="tpl === {{ $choice->id }}">
                                <div class="grid gap-4 md:grid-cols-2">
                                    @foreach ($choice->fieldList() as $field)
                                        @include('torgash.banners::admin._field', ['fieldDef' => $field, 'fieldValues' => $formValues, 'fieldRecord' => $banner, 'fieldPictures' => true])
                                    @endforeach
                                    @if (collect($choice->fieldList())->contains(fn ($field) => $field['type'] === 'rich'))
                                        <p class="text-xs opacity-60 md:col-span-2">
                                            {{ __('Texts may carry a few tags: <b>, <i>, <s>, <br>, <sup>, <sub> and <span class="…" style="…"> — a gradient word, a separator, a line break. Anything else is removed on saving.') }}
                                        </p>
                                    @endif
                                    @if ($choice->fieldList() === [])
                                        <p class="text-sm opacity-60 md:col-span-2">{{ __('This template has no fields: it is drawn the same every time.') }}</p>
                                    @endif
                                </div>
                            </template>
                        @endforeach
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <x-admin.display-rules
                            :rules="$banner->rules ?? []"
                            :match="$banner->rules_match ?? 'all'"
                            :groups="['where', 'when', 'who']"
                            :heading="__('Shown when')"
                            :hint="__('No conditions means wherever its slider is shown. The block that shows the slider has conditions of its own; these are the banner\'s.')" />
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $banner->enabled ?? true))>
                            <span class="label-text">{{ __('On') }}</span>
                        </label>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Shown from') }}</legend>
                            <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $formLocal($banner->starts_at)) }}" class="input input-bordered w-full">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Shown until') }}</legend>
                            <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $formLocal($banner->ends_at)) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Both optional. A banner past its date leaves its sliders by itself.') }}</p>
                        </fieldset>
                    </div>
                </section>

                @if ($clicks !== null)
                    {{-- Clicks only: a banner's views are the site statistics' business. --}}
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-2">
                            <h3 class="font-semibold">{{ __('Clicks') }}</h3>
                            <div class="grid grid-cols-3 gap-2 text-center">
                                @foreach (['week' => __('7 days'), 'month' => __('30 days'), 'total' => __('In all')] as $clickKey => $clickLabel)
                                    <div class="rounded-box bg-base-200 p-2">
                                        <div class="text-xl font-semibold tabular-nums">{{ $clicks[$clickKey] }}</div>
                                        <div class="text-xs opacity-60">{{ $clickLabel }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </section>
                @endif

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('In sliders') }}</h3>
                        @forelse ($sliders as $formSlider)
                            @php $sliderShape = $formSlider->frame?->shape ?: 'wide'; @endphp
                            <label class="label cursor-pointer justify-start gap-3 items-start">
                                <input type="checkbox" name="sliders[]" value="{{ $formSlider->id }}" class="checkbox checkbox-sm mt-0.5" @checked(in_array($formSlider->id, $inSliders, true))>
                                <span class="label-text whitespace-normal">
                                    {{ $formSlider->name }}
                                    <span class="block text-xs opacity-50">{{ $shapeNames[$sliderShape] }}</span>
                                    <span class="block text-xs text-warning" x-show="!fits(@js($sliderShape))" x-cloak>{{ __('This template cannot draw this shape — the slider will skip the banner.') }}</span>
                                </span>
                            </label>
                        @empty
                            <p class="text-sm opacity-60">{{ __('No sliders yet.') }}</p>
                        @endforelse
                        <a href="{{ route('admin.banners.sliders.create') }}" class="link link-primary text-sm">+ {{ __('New slider') }}</a>
                        <p class="text-xs opacity-60">{{ __('A banner is shown only through a slider; a slider is placed on the page by a "Banners" block.') }}</p>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($banner->exists)
        <form id="banner-duplicate" method="POST" action="{{ route('admin.banners.duplicate', $banner) }}" class="hidden">@csrf</form>
        <form id="banner-delete" method="POST" action="{{ route('admin.banners.destroy', $banner) }}" class="hidden"
              data-confirm="{{ __('Delete this banner?') }}">@csrf @method('DELETE')</form>
    @endif
@endsection
