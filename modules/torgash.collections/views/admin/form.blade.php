@extends('admin.layout')

@section('title', $collection->exists ? __('Edit collection') : __('New collection'))
@section('own_heading', '1')

@section('content')
    @php
        $collectionLocal = fn ($date) => $date ? $date->format('Y-m-d\TH:i') : '';
        $collectionConditions = (array) ($collection->conditions ?? []);
        $collectionMajor = fn ($minor) => $minor === null ? '' : rtrim(rtrim(number_format($minor / 100, 2, '.', ''), '0'), '.');
        $collectionLookup = app(\Torgash\Catalog\Admin\ProductLookup::class);
    @endphp

    <form method="POST" enctype="multipart/form-data"
          action="{{ $collection->exists ? route('admin.collections.update', $collection) : route('admin.collections.store') }}"
          class="flex flex-col gap-4">
        @csrf
        @if ($collection->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.collections.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to collections') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $collection->exists ? __('Edit collection') : __('New collection') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $collection->exists ? ($collection->t('name', admin_content_locale()) ?: '—') : __('New collection') }}</div>
            </div>

            @if ($collection->exists && (($prevCollection ?? null) || ($nextCollection ?? null)))
                <div class="join">
                    @if ($prevCollection ?? null)
                        <a href="{{ route('admin.collections.edit', $prevCollection) }}" class="btn btn-sm join-item" title="{{ __('Previous collection') }}">
                            <x-icon name="chevron-left" />
                        </a>
                    @else
                        <span class="btn btn-sm join-item btn-disabled"><x-icon name="chevron-left" /></span>
                    @endif
                    @if ($nextCollection ?? null)
                        <a href="{{ route('admin.collections.edit', $nextCollection) }}" class="btn btn-sm join-item" title="{{ __('Next collection') }}">
                            <x-icon name="chevron-right" />
                        </a>
                    @else
                        <span class="btn btn-sm join-item btn-disabled"><x-icon name="chevron-right" /></span>
                    @endif
                </div>
            @endif

            @if ($collection->exists)
                {{-- "Delete" submits the standalone form below through the HTML
                     "form" attribute — a nested <form> would leak its
                     _method=DELETE into this collection's own submit. --}}
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-56 mt-2 p-2 z-50 border border-base-300">
                        <li>
                            <button type="submit" name="after_save" value="close">
                                <x-icon name="check" /> {{ __('Save and close') }}
                            </button>
                        </li>
                        @if ($collection->isShown())
                            <li>
                                <a href="{{ route('collections.show', $collection->slug) }}" target="_blank" rel="noopener">
                                    <x-icon name="external-link" /> {{ __('Preview') }}
                                </a>
                            </li>
                        @endif
                        <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
                        <li>
                            <button type="submit" form="collection-delete" class="text-error"
                                    data-confirm="{{ __('Delete this collection? Its page stops answering.') }}">
                                <x-icon name="trash-2" /> {{ __('Delete this collection') }}
                            </button>
                        </li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.collections.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $collection->exists ? __('Save') : __('Create') }}</button>
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
            {{-- Left: what the page SAYS, which products it holds, its pictures. --}}
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Text') }}</h3>
                        <x-admin.translatable-field name="name" :label="__('Name')" :value="(array) ($collection->name ?? [])" :required="true" :record="$collection" />
                        <x-admin.translatable-field name="description" :label="__('Description')" :value="(array) ($collection->description ?? [])" :rich="true" :record="$collection" />
                        @unless ($collection->exists)
                            <p class="text-xs opacity-60 -mt-2">{{ __('Pictures inside the description can be added once the collection is saved.') }}</p>
                        @endunless
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <div class="flex items-baseline justify-between gap-3 flex-wrap">
                            <h3 class="font-semibold">{{ __('Which products') }}</h3>
                            @if ($matches !== null)
                                <span class="badge badge-primary">{{ trans_choice(':count product now|:count products now', $matches) }}</span>
                            @endif
                        </div>
                        <p class="text-sm opacity-70 max-w-3xl -mt-2">{{ __('Like the catalogue\'s filter: what you fill in must ALL hold; within one line, any of what is picked. A category takes its subcategories. The products picked by hand are added; the ones left out never appear. Nothing filled in and nothing picked — an empty collection.') }}</p>

                        <x-admin.picker name="categories[]" :label="__('Categories')" :options="$categoryOptions" :selected="old('categories', $selectedCategories)"
                                        :placeholder="__('Search categories…')" :empty="__('Any category')" />
                        <x-admin.picker name="values[]" :label="__('Characteristics (the brand among them)')" :options="$valueOptions" :selected="old('values', $selectedValues)"
                                        :placeholder="__('Search values, e.g. Brand: Apple…')" :empty="__('Any characteristics')" />
                        <div class="flex flex-col gap-1">
                            <x-admin.picker name="labels[]" :label="__('Labels')" :options="$labelOptions" :selected="old('labels', $selectedLabels)"
                                            :placeholder="__('Search labels…')" :empty="__('Any labels')" />
                            <p class="text-xs opacity-60">{{ __('As on the cards: the products a label was put on by hand and the ones its rules give it to — "New" for a fresh product, "Hit" by sales.') }}</p>
                        </div>

                        <div class="grid sm:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('From the filter') }}</legend>
                                <div class="flex flex-col gap-2">
                                    @forelse ($flagOptions as $flagKey => $flagLabel)
                                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                                            <input type="checkbox" name="flags[]" value="{{ $flagKey }}" class="checkbox checkbox-sm" @checked(in_array($flagKey, old('flags', $selectedFlags), true))>
                                            {{ $flagLabel }}
                                        </label>
                                    @empty
                                        <span class="text-sm opacity-60">—</span>
                                    @endforelse
                                </div>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Price, :currency', ['currency' => shop_currency()]) }}</legend>
                                <div class="join">
                                    <input type="number" step="any" min="0" name="price_min" value="{{ old('price_min', $collectionMajor($collectionConditions['price_min'] ?? null)) }}" placeholder="{{ __('price from') }}" aria-label="{{ __('price from') }}" class="input input-bordered join-item w-full">
                                    <input type="number" step="any" min="0" name="price_max" value="{{ old('price_max', $collectionMajor($collectionConditions['price_max'] ?? null)) }}" placeholder="{{ __('price to') }}" aria-label="{{ __('price to') }}" class="input input-bordered join-item w-full">
                                </div>
                            </fieldset>
                        </div>

                        <div class="grid lg:grid-cols-2 gap-4">
                            <x-admin.picker name="picked[]" :label="__('Also these products')"
                                            :options="$collectionLookup->options(old('picked', $picked))" :selected="old('picked', $picked)"
                                            :source="route('admin.collections.products')"
                                            :placeholder="__('Search products by SKU or name…')" :empty="__('None picked.')" />
                            <x-admin.picker name="excluded[]" :label="__('Never these products')"
                                            :options="$collectionLookup->options(old('excluded', $excluded))" :selected="old('excluded', $excluded)"
                                            :source="route('admin.collections.products')"
                                            :placeholder="__('Search products by SKU or name…')" :empty="__('None left out.')" />
                        </div>
                    </div>
                </section>

                @if ($collection->exists)
                    @include('torgash.collections::admin.looks', ['looks' => $collection->looks])
                @endif

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('For search engines') }}</h3>
                        <x-admin.translatable-field name="meta_title" :label="__('Page title')" :value="(array) ($collection->meta_title ?? [])" :placeholder="__('The collection\'s name')" :record="$collection" />
                        <x-admin.translatable-field name="meta_description" :label="__('Meta description')" :value="(array) ($collection->meta_description ?? [])" textarea :rows="2" :placeholder="__('The start of the description')" :record="$collection" />
                    </div>
                </section>
            </div>

            {{-- Right: whether it is on, when, where it lives, its cover. --}}
            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-primary" @checked(old('enabled', $collection->exists ? $collection->enabled : true))>
                            <span class="label-text">{{ __('Enabled') }}</span>
                        </label>
                        <label class="label cursor-pointer justify-start items-start gap-3">
                            <input type="checkbox" name="listed" value="1" class="toggle toggle-sm toggle-primary mt-0.5" @checked(old('listed', $collection->exists ? $collection->listed : true))>
                            <span class="whitespace-normal">
                                <span class="label-text">{{ __('In the list of collections') }}</span>
                                <span class="block text-xs opacity-60">{{ __('Off — the page still answers, for a banner or a menu to point at.') }}</span>
                            </span>
                        </label>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('When it shows') }}</h3>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Starts') }}</legend>
                            <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $collectionLocal($collection->starts_at)) }}" class="input input-bordered w-full">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Ends') }}</legend>
                            <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $collectionLocal($collection->ends_at)) }}" class="input input-bordered w-full">
                        </fieldset>
                        <p class="text-xs opacity-60 -mt-2">{{ __('Empty — always.') }}</p>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Where it stands') }}</h3>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Address') }}</legend>
                            <label class="input input-bordered w-full">
                                <span class="opacity-50 text-sm">/collections/</span>
                                <input name="slug" value="{{ old('slug', $collection->slug) }}" placeholder="{{ __('from the name') }}" aria-label="{{ __('Address') }}" class="grow">
                            </label>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                            <input type="number" name="sort" value="{{ old('sort', $collection->sort ?? 0) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('The order in the list of collections and in the tiles block.') }}</p>
                        </fieldset>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Cover') }}</h3>
                        @if ($collectionCover = $collection->coverUrl())
                            <img src="{{ $collectionCover }}" alt="" class="rounded-box w-full aspect-[4/3] object-cover">
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="checkbox" name="remove_cover" value="1" class="checkbox checkbox-sm"> {{ __('Remove the cover') }}
                            </label>
                        @endif
                        <input type="file" name="cover" accept="image/webp,image/png,image/jpeg,image/avif" aria-label="{{ __('Cover') }}" class="file-input file-input-bordered w-full">
                        <span class="text-xs opacity-60">{{ __('WebP, PNG, JPEG or AVIF; 4:3 looks best on the tiles.') }}</span>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($collection->exists)
        <form id="collection-delete" method="POST" action="{{ route('admin.collections.destroy', $collection) }}" class="hidden">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
