@extends('admin.layout')

@section('title', $key === '' ? __('New status') : __('Edit status'))
@section('own_heading', '1')

@section('content')
    @php
        $own = $def['source'] === 'shop';
        // The header names it as the list does — in the reader's language, not
        // the content language being edited below.
        $shownName = $key === '' ? __('New status') : app(\Torgash\Catalog\Stock\AvailabilityStatuses::class)->label($key);
    @endphp

    <form method="POST" class="flex flex-col gap-4"
          action="{{ $key === '' ? route('admin.catalog.availability-statuses.store') : route('admin.catalog.availability-statuses.update', $key) }}">
        @csrf
        @if ($key !== '') @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.catalog.availability-statuses.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to statuses') }}" aria-label="{{ __('Back to statuses') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $key === '' ? __('New status') : __('Edit status') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $shownName }}</div>
            </div>

            @if ($key !== '')
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
                        <li>
                            <a href="{{ route('admin.catalog.products.index', ['availability' => $key]) }}">
                                <x-icon name="list" /> {{ __('View products') }}
                                <span class="badge badge-sm badge-ghost">{{ $products }}</span>
                            </a>
                        </li>
                        @if ($own)
                            <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
                            <li>
                                @if ($products === 0)
                                    <button type="submit" form="delete-status" class="text-error"
                                            data-confirm="{{ __('Delete this status?') }}">
                                        <x-icon name="trash-2" /> {{ __('Delete this status') }}
                                    </button>
                                @else
                                    <span class="opacity-50 pointer-events-none whitespace-normal" title="{{ __('Set on products — give them another status first.') }}">
                                        <x-icon name="trash-2" /> {{ __('Delete this status') }}
                                    </span>
                                @endif
                            </li>
                        @endif
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ route('admin.catalog.availability-statuses.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $key === '' ? __('Create') : __('Save') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            {{-- Left: what it is called and what it means. --}}
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Basics') }}</h3>

                        <x-admin.translatable-field name="label" :label="__('Name')" :value="$typed"
                            :required="$own"
                            :placeholder="$own ? __('e.g. Made to order') : label_text($def['label'], admin_content_locale(), $key)" />
                        @unless ($own)
                            <p class="text-xs opacity-60 -mt-2">{{ __('Leave empty to keep the built-in name.') }}</p>
                        @endunless

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Code') }}</legend>
                            @if ($key === '')
                                <input name="key" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('key') }}" class="input input-bordered w-full max-w-xs font-mono"
                                       placeholder="{{ __('auto from the name, e.g. made_to_order') }}">
                            @else
                                <input value="{{ $key }}" class="input input-bordered w-full max-w-xs font-mono" disabled>
                            @endif
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Latin letters, digits and underscores. It is what an import, the API and a theme’s CSS class use, so it cannot be changed later.') }}</p>
                        </fieldset>
                    </div>
                </section>

                @if ($own)
                    <section class="card bg-base-100 border border-base-300" x-data="{ tracks: @js((bool) old('tracks_stock', $def['tracks_stock'])) }">
                        <div class="card-body gap-3">
                            <h3 class="font-semibold">{{ __('What it means') }}</h3>

                            <label class="label cursor-pointer justify-start gap-2 p-0">
                                <input type="hidden" name="orderable" value="0">
                                <input type="checkbox" name="orderable" value="1" class="checkbox checkbox-sm" @checked(old('orderable', $def['orderable']))>
                                <span class="label-text">{{ __('Can be bought') }}</span>
                            </label>
                            <p class="text-xs opacity-60 -mt-2 ms-7">{{ __('Off: the product stays on the site and cannot be added to the cart, whatever its stock.') }}</p>

                            <label class="label cursor-pointer justify-start gap-2 p-0">
                                <input type="hidden" name="tracks_stock" value="0">
                                <input type="checkbox" name="tracks_stock" value="1" x-model="tracks" class="checkbox checkbox-sm">
                                <span class="label-text">{{ __('Counts stock') }}</span>
                            </label>
                            <p class="text-xs opacity-60 -mt-2 ms-7">{{ __('Off: the stock number is only for information — orders do not change it and it never stops a purchase.') }}</p>

                            <div x-show="tracks">
                                <label class="label cursor-pointer justify-start gap-2 p-0">
                                    <input type="hidden" name="allow_negative" value="0">
                                    <input type="checkbox" name="allow_negative" value="1" class="checkbox checkbox-sm" @checked(old('allow_negative', $def['allow_negative']))>
                                    <span class="label-text">{{ __('May be sold below zero') }}</span>
                                </label>
                                <p class="text-xs opacity-60 mt-1 ms-7">{{ __('As a preorder does: the number goes negative and the buy button stays. Off: the product cannot be bought once stock reaches zero.') }}</p>
                            </div>
                        </div>
                    </section>
                @else
                    {{-- Said up front, because the missing tick boxes are otherwise
                         a question: this status MEANS what the code that
                         registered it relies on. --}}
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h3 class="font-semibold">{{ __('What it means') }}</h3>
                            <p class="text-sm opacity-70">{{ __('This status is built in: what it means (whether it can be bought, whether stock is counted) is fixed by the module that registered it. You can rename it and set its weight.') }}</p>
                            <ul class="text-sm flex flex-col gap-1.5">
                                @foreach (['orderable' => __('Can be bought'), 'tracks_stock' => __('Counts stock'), 'allow_negative' => __('May be sold below zero')] as $fact => $factText)
                                    @continue($fact === 'allow_negative' && ! $def['tracks_stock'])
                                    <li class="flex items-center gap-2">
                                        <x-icon :name="$def[$fact] ? 'check' : 'x'" size="sm" :class="$def[$fact] ? 'text-success' : 'opacity-40'" />
                                        {{ $factText }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </section>
                @endif
            </div>

            {{-- Right: where it ranks and what a search engine is told. --}}
            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Weight in listings') }}</h3>
                        <input type="number" name="sort_weight" step="1" min="-1000" max="1000"
                               value="{{ old('sort_weight', $typedWeight) }}"
                               placeholder="{{ $def['orderable'] ? \Torgash\Catalog\Stock\AvailabilityStatuses::ORDERABLE_WEIGHT : \Torgash\Catalog\Stock\AvailabilityStatuses::UNORDERABLE_WEIGHT }}"
                               class="input input-bordered w-full tabular-nums">
                        <p class="text-xs opacity-60">{{ __('Higher comes first; equal weights are mixed. Blank: 10 if it can be bought, 0 if not. Used when ordering by availability is on in the catalogue settings.') }}</p>
                    </div>
                </section>

                @if ($own)
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h3 class="font-semibold">{{ __('For search engines') }}</h3>
                            <select name="schema" class="select select-bordered w-full">
                                @foreach ($schemaOptions as $uri)
                                    <option value="{{ $uri }}" @selected(old('schema', $def['schema']) === $uri)>{{ \Illuminate\Support\Str::after($uri, 'schema.org/') }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs opacity-60">{{ __('The schema.org availability a product page announces in its structured data.') }}</p>
                        </div>
                    </section>
                @endif

                @if ($key !== '')
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-2 text-sm">
                            <h3 class="font-semibold">{{ __('Products') }}</h3>
                            @if ($products > 0)
                                <a href="{{ route('admin.catalog.products.index', ['availability' => $key]) }}" class="link">
                                    {{ trans_choice(':count product carries it|:count products carry it', $products, ['count' => $products]) }}
                                </a>
                            @else
                                <p class="opacity-60">{{ __('No product carries it yet.') }}</p>
                            @endif
                            <p class="text-xs opacity-60">{{ __('A theme can style it by the class torgash-availability-:key.', ['key' => $key]) }}</p>
                        </div>
                    </section>
                @endif
            </div>
        </div>
    </form>

    @if ($key !== '' && $own && $products === 0)
        <form id="delete-status" method="POST" class="hidden" action="{{ route('admin.catalog.availability-statuses.destroy', $key) }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
