@extends('admin.layout')

@section('title', $tab->exists ? __('Edit tab') : __('New tab'))
@section('own_heading', '1')

@section('content')
    @php
        $locale = admin_content_locale();
        // The section this row speaks for, when it speaks for one: the page's
        // own or a module's (ProductSections::known()).
        $sectionName = $tab->section ? ($known['label'] ?? $tab->section) : null;
        $fromModule = $known['module'] ?? null;
        $renamable = ! $tab->section || ($known['renamable'] ?? true);
        $backUrl = $tab->product ? route('admin.catalog.products.edit', $tab->product) : route('admin.catalog.product-tabs.index');
    @endphp

    <form method="POST" class="flex flex-col gap-4"
          action="{{ $tab->exists ? route('admin.catalog.product-tabs.update', $tab) : route('admin.catalog.product-tabs.store') }}">
        @csrf
        @if ($tab->exists) @method('PUT') @endif

        {{-- Which product, when this tab belongs to one. A hidden field rather
             than a picker: a tab for a single product is created from that
             product's own form, which is where somebody already has it in front
             of them — and a picker here would be a second way to answer the
             same question. --}}
        <input type="hidden" name="product_id" value="{{ old('product_id', $tab->product_id) }}">
        {{-- Which of the page's own sections this row names, when it names one.
             Hidden, because it is decided by the link that opened this form: a
             row cannot become a different section without becoming a different
             answer to a different question. --}}
        <input type="hidden" name="section" value="{{ old('section', $tab->section) }}">

        <x-admin.form-bar>
            <a href="{{ $backUrl }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back') }}" aria-label="{{ __('Back') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $tab->exists ? __('Edit tab') : __('New tab') }}</div>
                <div class="font-semibold truncate leading-tight">
                    {{ $tab->t('title', $locale) ?: ($sectionName ?? ($tab->exists ? __('(untitled)') : __('New tab'))) }}
                </div>
            </div>

            <x-admin.placeholders name="product-tab" :groups="['page', 'product', 'shop']"
                :trigger="__('Placeholders')" trigger-class="btn btn-ghost btn-sm" />

            @if ($tab->exists)
                {{-- Delete submits the standalone form below through the HTML
                     "form" attribute: a nested <form> would leak its
                     _method=DELETE into this one's submit. --}}
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-64 mt-2 p-2 z-50 border border-base-300">
                        <li>
                            <button type="submit" name="after_save" value="close">
                                <x-icon name="check" /> {{ __('Save and close') }}
                            </button>
                        </li>
                        @if ($tab->product)
                            <li>
                                <a href="{{ route('catalog.product', $tab->product->slug) }}" target="_blank" rel="noopener">
                                    <x-icon name="external-link" /> {{ __('Preview') }}
                                </a>
                            </li>
                        @endif
                        <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
                        <li>
                            <button type="submit" form="delete-tab" class="text-error"
                                    data-confirm="{{ $tab->section ? __('Forget this section\'s own words? It goes back to the engine\'s title, position and being shown.') : __('Delete this tab?') }}">
                                <x-icon :name="$tab->section ? 'rotate-ccw' : 'trash-2'" />
                                {{ $tab->section ? __('Reset to the default') : __('Delete this tab') }}
                            </button>
                        </li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ $backUrl }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $tab->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            {{-- Left: what it is called, what it says, and where it appears. --}}
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Text') }}</h3>

                        @if ($renamable)
                            <x-admin.translatable-field name="title" :label="__('Tab title')" :value="$tab->title ?? []"
                                :required="! $tab->section"
                                placeholder="{{ $sectionName ?? __('Delivery and payment') }}" />
                            @if ($tab->section)
                                <p class="text-xs opacity-60 -mt-2">{{ __('Empty — the usual name, :name.', ['name' => $sectionName]) }}</p>
                            @endif
                        @else
                            <p class="text-sm opacity-70">{{ __('Each of these is called what it is called in its module, so there is no name to write here — the position, the heading and where it is shown are.') }}</p>
                        @endif

                        {{-- The other name. A tab holds a word because six stand in a
                             row; a heading holds a line, and the line a shop wants
                             there has the product in it. --}}
                        <x-admin.translatable-field name="heading" :label="__('Section heading')" :value="$tab->heading ?? []"
                            placeholder="{{ __('Reviews of {product}') }}" />
                        <p class="text-xs opacity-60 -mt-2">
                            {{ __('Used when the page is laid out as sections rather than tabs (Catalogue settings → Under the gallery). Empty — the section is headed by its tab title.') }}
                        </p>

                        @unless ($tab->section)
                            <x-admin.translatable-field name="content" :label="__('What it says')" :value="$tab->content ?? []"
                                :record="$tab" textarea rich :rows="8" />
                        @endunless

                        <p class="text-xs opacity-60">
                            {{ __('Title, heading and text all take placeholders, so one row can speak about the product it is on: "{feature_name:ram_gb}: {feature:ram_gb}". A tab of your own with nothing written in it is not shown at all.') }}
                        </p>
                    </div>
                </section>

                @unless ($tab->product_id)
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-4">
                            {{-- Core's own rule engine, the same one the blocks carry —
                                 so "only on products in this category" is written once
                                 in the registry and answered here for nothing. --}}
                            <x-admin.display-rules
                                :heading="__('On which products')"
                                :rules="$tab->rules ?? []"
                                :match="$tab->rules_match ?? 'all'"
                                :hint="__('No conditions means the tab is on every product page. A condition whose module is switched off hides the tab rather than being ignored.')" />
                        </div>
                    </section>
                @endunless
            </div>

            {{-- Right: whether it is on, where it stands, and whose it is. --}}
            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $tab->enabled ?? true))>
                            <span class="label-text">{{ $tab->section ? __('Show this section') : __('Enabled') }}</span>
                        </label>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Position') }}</legend>
                            <input type="number" name="sort" value="{{ old('sort', $tab->sort ?? 50) }}"
                                   class="input input-bordered w-full" min="0" max="999">
                            <p class="label text-xs opacity-60 whitespace-normal">
                                {{ __('Description is 10, Characteristics 20, kits 25, reviews 30 — change the numbers to change the order.') }}
                            </p>
                        </fieldset>
                    </div>
                </section>

                @if ($tab->product || $tab->section)
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-2 text-sm">
                            @if ($tab->product)
                                <h3 class="font-semibold">{{ __('One product\'s tab') }}</h3>
                                <p class="opacity-70">{{ __('Shown on this product only:') }}</p>
                                <a href="{{ route('admin.catalog.products.edit', $tab->product) }}" class="link font-medium">
                                    {{ $tab->product->t('name', $locale) ?: $tab->product->sku }}
                                </a>
                            @endif
                            @if ($tab->section && $fromModule)
                                <h3 class="font-semibold">{{ __('Module: :name', ['name' => $fromModule]) }}</h3>
                                <p class="opacity-70">{{ __('What is in it is the module\'s. This row carries the words, the position and whether the section is shown at all.') }}</p>
                                @if ($known['url'] ?? null)
                                    <a href="{{ $known['url'] }}" class="link text-sm inline-flex items-center gap-1">{{ __('Open the module') }} <x-icon name="arrow-up-right" size="sm" /></a>
                                @endif
                            @elseif ($tab->section)
                                <h3 class="font-semibold">{{ __('The page\'s own section') }}: {{ $sectionName }}</h3>
                                <p class="opacity-70">{{ __('It has no text of its own — the page makes that. What it does carry is the words, the position and whether the section is shown at all.') }}</p>
                            @endif
                        </div>
                    </section>
                @endif
            </div>
        </div>
    </form>

    @if ($tab->exists)
        {{-- Beside the form, never inside it: a nested form is dropped by the
             parser while its _method rides along with the outer submit. --}}
        <form id="delete-tab" method="POST" class="hidden" action="{{ route('admin.catalog.product-tabs.destroy', $tab) }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
