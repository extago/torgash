@extends('admin.layout')

@section('title', $item->exists ? __('Edit card') : __('New card'))
@section('own_heading', '1')

@section('content')
    @php
        $locale = admin_content_locale();
        $selectedCategoryIds = old('category_ids', $item->exists ? $item->categories->pluck('id')->all() : []);
        $selectedProductIds = old('product_ids', $item->exists ? $item->products->pluck('id')->all() : []);
        $selectedGroupIds = old('customer_group_ids', $item->exists ? $item->customerGroups->pluck('id')->all() : []);
    @endphp

    <form method="POST" enctype="multipart/form-data"
          action="{{ $item->exists ? route('admin.motivation.update', $item) : route('admin.motivation.store') }}"
          x-data="{
              iconType: '{{ old('icon_type', $item->icon_type ?? 'none') }}',
              contentType: '{{ old('content_type', $item->content_type ?? 'html') }}',
          }" class="flex flex-col gap-4">
        @csrf
        @if ($item->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.motivation.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to cards') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $item->exists ? __('Edit card') : __('New card') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $item->exists ? ($item->t('name', $locale) ?: '#'.$item->id) : __('New card') }}</div>
            </div>
            @if ($item->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li><button type="submit" form="motivation-duplicate"><x-icon name="copy" /> {{ __('Duplicate') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="motivation-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.motivation.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $item->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm flex-col items-start gap-1">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Content') }}</h3>
                        <x-admin.translatable-field name="name" label="{{ __('Title') }}" :value="$item->name ?? []" required placeholder="{{ __('e.g. Delivery') }}" />

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('What the card says') }}</legend>
                            <select name="content_type" x-model="contentType" class="select select-bordered w-full">
                                <option value="html">{{ __('Custom text') }}</option>
                                <option value="categories">{{ __("This product's categories, linked") }}</option>
                                <option value="payment_methods" @disabled(! $checkoutEnabled)>
                                    {{ __('Active payment methods') }}{{ $checkoutEnabled ? '' : ' — '.__('needs the Checkout module') }}
                                </option>
                                <option value="shipping_methods" @disabled(! $checkoutEnabled)>
                                    {{ __('Active shipping methods') }}{{ $checkoutEnabled ? '' : ' — '.__('needs the Checkout module') }}
                                </option>
                            </select>
                            <p class="label text-xs opacity-60 whitespace-normal" x-show="contentType !== 'html'" x-cloak>
                                {{ __('Everything but "Custom text" is generated automatically — nothing to fill in below.') }}
                            </p>
                        </fieldset>

                        <div x-show="contentType === 'html'">
                            <x-admin.translatable-field name="content" label="{{ __('Text') }}" :value="$item->content ?? []" textarea rich :rows="6" />
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Categories') }}</h3>
                        <p class="text-xs opacity-60">{{ __('Leave empty to show on every product. A viewed product matches if it belongs to any of its own categories that are selected here.') }}</p>

                        <fieldset class="fieldset p-0 max-w-sm"><legend class="fieldset-legend">{{ __('When categories are selected below') }}</legend>
                            <select name="exclude_categories" class="select select-bordered w-full">
                                <option value="0" @selected(! old('exclude_categories', $item->exclude_categories ?? false))>{{ __('Show only on selected') }}</option>
                                <option value="1" @selected(old('exclude_categories', $item->exclude_categories ?? false))>{{ __('Show everywhere except selected') }}</option>
                            </select>
                        </fieldset>

                        <x-admin.picker
                            name="category_ids[]"
                            :options="$categoryOptions"
                            :selected="$selectedCategoryIds"
                            :label="null"
                            :placeholder="__('Search categories…')"
                            :empty="__('No categories selected — shown everywhere.')" />
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Products') }}</h3>
                        <p class="text-xs opacity-60">{{ __('Scopes to specific products directly, on top of the category rule above — both have to allow a product for the card to show.') }}</p>

                        <fieldset class="fieldset p-0 max-w-sm"><legend class="fieldset-legend">{{ __('When products are selected below') }}</legend>
                            <select name="exclude_products" class="select select-bordered w-full">
                                <option value="0" @selected(! old('exclude_products', $item->exclude_products ?? false))>{{ __('Show only on selected') }}</option>
                                <option value="1" @selected(old('exclude_products', $item->exclude_products ?? false))>{{ __('Show everywhere except selected') }}</option>
                            </select>
                        </fieldset>

                        <x-admin.picker
                            name="product_ids[]"
                            :options="$productOptions"
                            :selected="$selectedProductIds"
                            :label="null"
                            :placeholder="__('Search products by SKU or name…')"
                            :empty="__('No products selected — shown everywhere.')" />
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Customer groups') }}</h3>
                        <p class="text-xs opacity-60">{{ __('Leave empty to show to everyone, guests included.') }}</p>

                        <x-admin.picker
                            name="customer_group_ids[]"
                            :options="$customerGroupOptions"
                            :selected="$selectedGroupIds"
                            :label="null"
                            :placeholder="__('Search customer groups…')"
                            :empty="__('No groups selected — shown to everyone.')" />
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $item->enabled ?? true))>
                            <span class="label-text">{{ __('Card shown') }}</span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="expanded" value="1" class="checkbox checkbox-sm checkbox-primary" @checked(old('expanded', $item->expanded ?? true))>
                            <span class="label-text">{{ __('Open by default') }}</span>
                        </label>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                            <input name="sort" type="number" value="{{ old('sort', $item->sort ?? 0) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('The order of the cards under the buy button.') }}</p>
                        </fieldset>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Icon') }}</h3>

                        <select name="icon_type" x-model="iconType" class="select select-bordered w-full" aria-label="{{ __('Icon') }}">
                            <option value="none">{{ __('None') }}</option>
                            <option value="lucide">{{ __('From the icon set') }}</option>
                            <option value="image">{{ __('Image') }}</option>
                        </select>

                        <div x-show="iconType === 'lucide'" class="flex flex-col gap-4">
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">
                                    {{ __('Icon name') }}
                                    <a href="https://lucide.dev/icons" target="_blank" rel="noopener" class="link link-hover text-xs font-normal">lucide.dev/icons</a>
                                </legend>
                                <input name="icon_name" list="lucide-icon-names" value="{{ old('icon_name', $item->icon_name) }}"
                                       class="input input-bordered w-full" placeholder="truck, shield-check, gift…" autocomplete="off">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Color') }}</legend>
                                <select name="icon_color" class="select select-bordered w-full">
                                    <option value="">{{ __('Default') }}</option>
                                    @foreach (\Torgash\Motivation\Models\MotivationItem::COLORS as $c)
                                        <option value="{{ $c }}" @selected(old('icon_color', $item->icon_color) === $c)>{{ __(\Torgash\Motivation\Models\MotivationItem::COLOR_LABELS[$c] ?? ucfirst($c)) }}</option>
                                    @endforeach
                                </select>
                            </fieldset>
                            @if ($item->icon_type === 'lucide' && $item->icon_name)
                                <div class="flex items-center gap-2 text-sm opacity-60">
                                    {{ __('Current:') }}
                                    <x-icon :name="$item->icon_name" size="lg" class="{{ $item->icon_color ? 'text-'.$item->icon_color : '' }}" />
                                </div>
                            @endif
                        </div>

                        <div x-show="iconType === 'image'" class="flex flex-col gap-2">
                            @if ($item->exists && $item->iconUrl())
                                <img src="{{ $item->iconUrl() }}" class="h-10 w-10 object-contain" alt="">
                            @endif
                            <input name="icon_image" type="file" accept="image/*" class="file-input file-input-bordered w-full" aria-label="{{ __('Image') }}">
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($item->exists)
        <form method="POST" id="motivation-duplicate" action="{{ route('admin.motivation.duplicate', $item) }}" class="hidden">@csrf</form>
        <form method="POST" id="motivation-delete" action="{{ route('admin.motivation.destroy', $item) }}" class="hidden"
              data-confirm="{{ __('Delete this card?') }}">
            @csrf @method('DELETE')
        </form>
    @endif

    <datalist id="lucide-icon-names">
        @foreach ($iconNames as $name)
            <option value="{{ $name }}"></option>
        @endforeach
    </datalist>
@endsection
