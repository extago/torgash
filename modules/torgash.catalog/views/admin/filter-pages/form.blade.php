@extends('admin.layout')

@section('title', $page->exists ? __('Edit filter page') : __('New filter page'))
@section('own_heading', '1')

@section('content')
    @php $selected = old('values', $page->valueSet() ?: []); @endphp

    <form method="POST"
          action="{{ $page->exists ? route('admin.catalog.filter-pages.update', $page) : route('admin.catalog.filter-pages.store') }}"
          id="filter-page-form" class="flex flex-col gap-4">
        @csrf
        @if ($page->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.catalog.filter-pages.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to filter pages') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ __('Filter page') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $page->exists ? $page->title(admin_content_locale()) : __('New filter page') }}</div>
            </div>
            @if ($page->exists && $page->category)
                <a href="{{ $page->url() }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm btn-square" title="{{ __('Open on the storefront') }}">
                    <x-icon name="external-link" size="lg" />
                </a>
            @endif
            @if ($page->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="filter-page-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.catalog.filter-pages.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $page->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
        <div class="flex flex-col gap-4 min-w-0">
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('What the page is') }}</h3>

                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Category') }}</legend>
                    {{-- Changing it reloads the picker below, because which
                         characteristics exist is a property of the category.
                         The ticks are not carried over on purpose: another
                         category's values are not valid here. --}}
                    <select name="category_id" class="select select-bordered w-full"
                            hx-get="{{ route('admin.catalog.filter-pages.values') }}"
                            hx-target="#filter-page-values" hx-swap="innerHTML"
                            hx-trigger="change" hx-include="this">
                        @foreach ($categories as $catId => $catLabel)
                            <option value="{{ $catId }}" @selected(old('category_id', $page->category_id) == $catId)>
                                {{ $catLabel }}
                            </option>
                        @endforeach
                    </select>
                </fieldset>

                @php
                    // The town field appears only with a place registry
                    // installed: without one there is nothing to search, and a
                    // city page cannot be written. Included straight from the
                    // locations module — a branch that is never taken resolves
                    // no view, so this stays a soft dependency.
                    $suggest = \Illuminate\Support\Facades\Route::has('places.suggest') ? route('places.suggest') : null;
                @endphp

                @if ($suggest)
                    @include('torgash.locations::partials.place-picker-script')

                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Town') }}</legend>
                        <div x-data="placePicker({ endpoint: '{{ $suggest }}', label: @js($placeName), name: @js($placeName), placeId: @js($page->place_id ?? '') })"
                             class="relative">
                            <input type="text" class="input input-bordered w-full" autocomplete="off"
                                   value="{{ $placeName }}" :value="label"
                                   @input="search($event.target.value)" @focus="open = true"
                                   placeholder="{{ __('Start typing a town') }}">
                            <input type="hidden" name="place_id" value="{{ $page->place_id }}" :value="placeId">

                            @include('torgash.locations::partials.place-suggestions')
                        </div>
                        <div class="text-xs opacity-50">
                            {{ __('Makes this a city page: the same products as the category, under words of their own — "Laptops in Kyiv". Leave empty for an ordinary filter page.') }}
                        </div>
                    </fieldset>
                @endif

                {{-- The yes/no filters the shop's modules can answer for, as
                     part of the ADDRESS: "laptops in stock" is a page, not only
                     a tick box. The list is the registry's — a module that adds
                     one is publishable the same afternoon, and nothing here
                     names a single filter. --}}
                @if (! empty($flags))
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('System filters') }}</legend>
                        <p class="text-xs opacity-60 mb-2">
                            {{ __('Applied at this address whether or not the box is offered in the storefront panel. A filter whose module is gone narrows the page to nothing rather than quietly widening it.') }}
                        </p>
                        <div class="flex flex-wrap gap-x-6 gap-y-1">
                            @foreach ($flags as $flagKey => $flag)
                                <label class="label cursor-pointer justify-start gap-2 py-0.5">
                                    <input type="checkbox" name="flags[]" value="{{ $flagKey }}" class="checkbox checkbox-sm"
                                           @checked(in_array($flagKey, old('flags', $selectedFlags), true))>
                                    <span class="label-text">{{ app(\Torgash\Catalog\Filters\Flags::class)->label($flagKey) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                {{-- The page's own price boundary (PLAN §30.14) — the shop's
                     books, never the visitor's session currency, and matched
                     EXACTLY by canonicalFor(): "up to 29 999" is not this page
                     if it says 30 000. --}}
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Price range') }}</legend>
                    <div class="flex items-center gap-2 max-w-xs">
                        <input name="price_min" type="number" min="0" step="0.01" class="input input-bordered w-full"
                               value="{{ old('price_min', $page->price_min !== null ? \Torgash\Catalog\Models\FilterPage::bareAmount($page->price_min) : '') }}"
                               placeholder="{{ __('from') }}">
                        <span class="opacity-50">–</span>
                        <input name="price_max" type="number" min="0" step="0.01" class="input input-bordered w-full"
                               value="{{ old('price_max', $page->price_max !== null ? \Torgash\Catalog\Models\FilterPage::bareAmount($page->price_max) : '') }}"
                               placeholder="{{ __('to') }}">
                    </div>
                    <p class="text-xs opacity-60">
                        {{ __('In the shop\'s own currency. Either side may be empty. A listing narrowed to a DIFFERENT range never canonicalises here — the boundary has to match exactly.') }}
                    </p>
                </fieldset>

                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Characteristic values') }}</legend>
                    <p class="text-xs opacity-60 mb-2">
                        {{ __('The page shows the products in that category carrying these values. Several values of one characteristic mean "any of them"; values of different characteristics all have to match. A page about a town or a system filter may leave these empty.') }}
                    </p>

                    <div id="filter-page-values">
                        @include('torgash.catalog::admin.filter-pages._values')
                    </div>
                </fieldset>
            </div>
        </div>

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('Name and text') }}</h3>

                <x-admin.translatable-field name="name" label="{{ __('Name') }}" :value="$page->name ?? []"
                                            placeholder="{{ __('Apple laptops') }}" />

                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Address') }}</legend>
                    <label class="input input-bordered flex items-center gap-1 w-full">
                        <span class="opacity-50 text-xs whitespace-nowrap">/catalog/{{ $page->category?->slug ?? '…' }}/</span>
                        <input name="slug" value="{{ old('slug', $page->slug) }}" class="grow" placeholder="{{ __('auto from the name') }}">
                    </label>
                    <p class="text-xs opacity-60">{{ __('Changing this leaves a 301 from the old address.') }}</p>
                </fieldset>

                <x-admin.translatable-field name="description" label="{{ __('Text on the page') }}" :value="$page->description ?? []"
                                            :record="$page" textarea rich :rows="5" />
            </div>
        </div>

        <x-seo.fields :record="$page"
                      :url-preview="$page->exists && $page->category ? $page->url() : null" />
        </div>

        <div class="flex flex-col gap-4">
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('Publication') }}</h3>

                <div class="flex flex-col gap-1">
                    <label class="label cursor-pointer justify-start items-start gap-2 whitespace-normal">
                        <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $page->enabled ?? true))>
                        <span class="label-text">{{ __('Published') }}</span>
                    </label>
                    <label class="label cursor-pointer justify-start items-start gap-2 whitespace-normal">
                        <input type="checkbox" name="in_sitemap" value="1" class="toggle toggle-sm toggle-primary" @checked(old('in_sitemap', $page->in_sitemap ?? true))>
                        <span class="label-text">{{ __('In the sitemap') }}</span>
                    </label>
                    <label class="label cursor-pointer justify-start items-start gap-2 whitespace-normal">
                        <input type="checkbox" name="in_list" value="1" class="toggle toggle-sm toggle-primary" @checked(old('in_list', $page->in_list ?? true))>
                        <span class="label-text">{{ __('Linked from the category') }}</span>
                    </label>
                </div>

                <div class="text-xs opacity-60">{{ __('Unpublishing takes the address down; this only takes the button out of the strip above the grid. How many buttons that strip holds is a catalogue setting.') }}</div>

                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Position') }}</legend>
                    <input name="position" type="number" value="{{ old('position', $page->position ?? 0) }}" class="input input-bordered w-full" placeholder="0">
                    <p class="label text-xs opacity-60 whitespace-normal">{{ __('The order of the buttons in the category\'s strip.') }}</p>
                </fieldset>
            </div>
        </div>


        @if ($page->exists)
            {{-- What the page is NOW, for the person deciding what to do with it. --}}
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3 text-sm">
                    <h3 class="font-semibold">{{ __('About this page') }}</h3>
                    <div class="flex justify-between gap-2">
                        <span class="opacity-60">{{ __('Products') }}</span>
                        @if ($page->products_count === 0)
                            <span class="badge badge-sm badge-warning">{{ __('none — the page answers noindex') }}</span>
                        @else
                            <span class="tabular-nums font-semibold">{{ $page->products_count }}</span>
                        @endif
                    </div>
                    <div class="flex justify-between gap-2">
                        <span class="opacity-60">{{ __('Source') }}</span>
                        @if ($page->rule)
                            <a href="{{ route('admin.catalog.filter-rules.edit', $page->rule) }}" class="link link-hover text-end">{{ $page->rule->name }}</a>
                        @else
                            <span>{{ __('By hand') }}</span>
                        @endif
                    </div>
                    @if ($page->category)
                        <div class="flex flex-col gap-1">
                            <span class="opacity-60">{{ __('Address') }}</span>
                            <a href="{{ $page->url() }}" target="_blank" rel="noopener" class="link link-hover font-mono text-xs break-all">{{ $page->path() }}</a>
                        </div>
                    @endif
                </div>
            </div>
        @endif
        </div>
        </div>
    </form>

    {{-- Beside the form, never inside it — see docs/LESSONS.md. --}}
    @if ($page->exists)
        <form id="filter-page-delete" method="POST" action="{{ route('admin.catalog.filter-pages.destroy', $page) }}" class="hidden"
              data-confirm="{{ __('Delete this page? Its address will redirect to the category.') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
