@extends('admin.layout')

@section('title', $category->exists ? __('Edit category') : __('New category'))
@section('own_heading', '1')

@section('content')
    @php $locale = admin_content_locale(); @endphp

    <form method="POST"
          action="{{ $category->exists ? route('admin.catalog.categories.update', $category) : route('admin.catalog.categories.store') }}"
          enctype="multipart/form-data"
          class="flex flex-col gap-4">
        @csrf
        @if ($category->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.catalog.categories.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to categories') }}" aria-label="{{ __('Back to categories') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $category->exists ? __('Edit category') : __('New category') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $category->exists ? ($category->t('name', $locale) ?: '—') : __('New category') }}</div>
            </div>
            @if ($category->exists && (($prevCategory ?? null) || ($nextCategory ?? null)))
                <div class="join">
                    @if ($prevCategory ?? null)
                        <a href="{{ route('admin.catalog.categories.edit', $prevCategory) }}" class="btn btn-sm join-item" title="{{ __('Previous category') }}" aria-label="{{ __('Previous category') }}">
                            <x-icon name="chevron-left" />
                        </a>
                    @else
                        <span class="btn btn-sm join-item btn-disabled"><x-icon name="chevron-left" /></span>
                    @endif
                    @if ($nextCategory ?? null)
                        <a href="{{ route('admin.catalog.categories.edit', $nextCategory) }}" class="btn btn-sm join-item" title="{{ __('Next category') }}" aria-label="{{ __('Next category') }}">
                            <x-icon name="chevron-right" />
                        </a>
                    @else
                        <span class="btn btn-sm join-item btn-disabled"><x-icon name="chevron-right" /></span>
                    @endif
                </div>
            @endif

            @if ($category->exists)
                {{-- "Move to trash" submits the standalone form below through the
                     HTML "form" attribute — a nested <form> here would leak its
                     _method=DELETE into this category's own submit. --}}
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li>
                            <button type="submit" name="after_save" value="close">
                                <x-icon name="check" /> {{ __('Save and close') }}
                            </button>
                        </li>
                        <li>
                            <a href="{{ route('admin.catalog.categories.create', ['parent' => $category->id]) }}">
                                <x-icon name="folder-plus" /> {{ __('Add subcategory') }}
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('admin.catalog.products.create', ['category' => $category->id]) }}">
                                <x-icon name="package-plus" /> {{ __('Add product') }}
                            </a>
                        </li>
                        <li>
                            @if ($category->isReachable() && $category->slug)
                                <a href="{{ route('catalog.category', $category->slug) }}" target="_blank" rel="noopener">
                                    <x-icon name="external-link" /> {{ __('Preview') }}
                                </a>
                            @else
                                {{-- A disabled category has no page: a link here would open a 404. --}}
                                <span class="opacity-50 pointer-events-none" title="{{ __('A disabled category has no page on the storefront.') }}">
                                    <x-icon name="external-link" /> {{ __('Preview') }}
                                </span>
                            @endif
                        </li>
                        <li>
                            <a href="{{ route('admin.catalog.products.index', ['category' => $category->id]) }}">
                                <x-icon name="list" /> {{ __('View products') }}
                                <span class="badge badge-sm badge-ghost">{{ $productCount ?? 0 }}</span>
                            </a>
                        </li>
                        <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
                        <li>
                            <button type="submit" form="category-delete" class="text-error"
                                    data-confirm="{{ __('Move this category to the trash?') }}">
                                <x-icon name="trash-2" /> {{ __('Move to trash') }}
                            </button>
                        </li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ route('admin.catalog.categories.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $category->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            {{-- Left: what the category IS and how its page reads. --}}
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Basics') }}</h3>

                        <x-admin.translatable-field name="name" label="{{ __('Name') }}" :value="$category->name ?? []" required placeholder="{{ __('Name') }}" />

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Address') }}</legend>
                            <label class="input input-bordered w-full gap-0">
                                <span class="opacity-50 font-mono text-sm">/catalog/</span>
                                <input name="slug" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('slug', $category->slug) }}" class="grow font-mono text-sm" placeholder="{{ __('from the name') }}">
                            </label>
                            <p class="label whitespace-normal">{{ __('Changing it later leaves a redirect from the old address.') }}</p>
                        </fieldset>

                        <x-admin.translatable-field name="description" label="{{ __('Description') }}" :value="$category->description ?? []" textarea rich :rows="6" placeholder="{{ __('Description') }}" />
                        <p class="text-xs opacity-60 -mt-2">{{ __('Printed under the products on the category page. Leave it empty and nothing is drawn.') }}</p>
                    </div>
                </section>

                <x-seo.fields :record="$category" picture
                              :url-preview="$category->slug ? url('/catalog/'.$category->slug) : null" />
            </div>

            {{-- Right: where it stands and how it looks in a menu. --}}
            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        @php $currentStatus = old('status', $category->status ?? \Torgash\Catalog\Models\Category::STATUS_ACTIVE); @endphp
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend sr-only">{{ __('Status') }}</legend>
                            <select name="status" class="select select-bordered w-full">
                                @foreach (\Torgash\Catalog\Models\Category::statusLabels() as $statusValue => $statusText)
                                    <option value="{{ $statusValue }}" @selected($currentStatus === $statusValue)>{{ $statusText }}</option>
                                @endforeach
                            </select>
                            <p class="label text-xs opacity-60 whitespace-normal">
                                {{ __('Hidden keeps the category out of the menus, the tree and the sitemap, but its own address still opens — for a page a banner leads to. Disabled gives it no page at all; its products stay on sale elsewhere either way.') }}
                            </p>
                        </fieldset>

                        {{-- Who may see it (PLAN §46): nothing ticked is everybody.
                             The hidden marker tells "untick all" from a form that
                             never had the field. --}}
                        @php $shownTo = (array) old('customer_groups', $category->customer_groups ?? []); @endphp
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Who sees it') }}</legend>
                            <input type="hidden" name="customer_groups_set" value="1">
                            @foreach (\Torgash\Catalog\Support\Assortment::choices() as $groupToken => $groupLabel)
                                <label class="label cursor-pointer justify-start gap-2 text-sm">
                                    <input type="checkbox" name="customer_groups[]" value="{{ $groupToken }}" class="checkbox checkbox-sm" @checked(in_array($groupToken, $shownTo, true))>
                                    {{ $groupLabel }}
                                </label>
                            @endforeach
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Nothing ticked — everybody. Ticked — only them: others do not find it in the catalogue, search or the sitemap.') }}</p>
                        </fieldset>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Place in the tree') }}</h3>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Parent category') }}</legend>
                            <select name="parent_id" class="select select-bordered w-full">
                                <option value="">{{ __('— top level —') }}</option>
                                @foreach ($parents as $parentId => $parentLabel)
                                    <option value="{{ $parentId }}" @selected(old('parent_id', $category->parent_id) == $parentId)>
                                        {{ $parentLabel }}
                                    </option>
                                @endforeach
                            </select>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                            <input name="sort" type="number" value="{{ old('sort', $category->sort ?? 0) }}" class="input input-bordered w-full" placeholder="0">
                            <p class="label whitespace-normal">{{ __('Smaller first, among the categories of the same parent.') }}</p>
                        </fieldset>
                    </div>
                </section>

                {{-- The categories under this one, above its products: the
                     theme's choice unless the category makes its own; and
                     whether this one is among those on its parent's page.
                     The hidden 0 tells "unticked" from a form without the box. --}}
                @php $subcatsOwn = old('subcategories_view', $category->subcategories_view); @endphp
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Subcategories') }}</h3>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('On this category\'s page') }}</legend>
                            <select name="subcategories_view" class="select select-bordered w-full">
                                <option value="">{{ __('As the theme says') }}</option>
                                @foreach (['tiles' => __('Tiles with a picture'), 'buttons' => __('Buttons — compact, in two rows'), 'none' => __('Not shown')] as $subcatsValue => $subcatsText)
                                    <option value="{{ $subcatsValue }}" @selected($subcatsOwn === $subcatsValue)>{{ $subcatsText }}</option>
                                @endforeach
                            </select>
                            <p class="label whitespace-normal">{{ __('How the categories under this one are shown above its products. The theme\'s own choice is in its settings, "Category page".') }}</p>
                        </fieldset>
                        <div>
                            <input type="hidden" name="shown_in_parent" value="0">
                            <label class="label cursor-pointer justify-start gap-2 text-sm text-base-content whitespace-normal">
                                <input type="checkbox" name="shown_in_parent" value="1" class="checkbox checkbox-sm" @checked(old('shown_in_parent', $category->shown_in_parent ?? true))>
                                {{ __('Shown among its parent\'s subcategories') }}
                            </label>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Untick to leave it out of the tiles and buttons on its parent\'s page — the menus and the list beside the catalogue still have it.') }}</p>
                        </div>
                    </div>
                </section>

                {{-- A lucide.dev name; the saved one is drawn beside the box (from the
                     engine's own pack, nothing fetched), so a typo shows as an
                     empty square after saving. --}}
                @php $categoryIcon = old('icon', $category->icon); @endphp
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Icon') }}</h3>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Icon name') }}</legend>
                            <div class="flex items-center gap-2">
                                <input name="icon" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ $categoryIcon }}" class="input input-bordered w-full font-mono" placeholder="shirt">
                                <span class="w-10 h-10 shrink-0 rounded-field bg-base-200 flex items-center justify-center">
                                    @if ($categoryIcon && preg_match('/^[a-z0-9-]+$/', $categoryIcon) && is_file(resource_path('icons/lucide/'.$categoryIcon.'.svg')))
                                        <x-icon :name="$categoryIcon" size="lg" />
                                    @endif
                                </span>
                            </div>
                            <p class="label whitespace-normal">{{ __('A name from lucide.dev, e.g. shirt, flame, backpack — drawn beside the category in the phone menu.') }}</p>
                        </fieldset>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Or your own picture') }}</legend>
                            <div class="flex items-center gap-2">
                                <input type="file" name="icon_file" accept="image/png,image/jpeg,image/webp,image/gif" class="file-input file-input-bordered w-full">
                                <span class="w-10 h-10 shrink-0 rounded-field bg-base-200 flex items-center justify-center overflow-hidden">
                                    @if ($categoryIconImage = $category->iconImageUrl())
                                        <img src="{{ $categoryIconImage }}" alt="" class="w-8 h-8 object-contain">
                                    @endif
                                </span>
                            </div>
                            @if ($category->icon_image)
                                <label class="label cursor-pointer justify-start gap-2 mt-1">
                                    <input type="checkbox" name="icon_image_remove" value="1" class="checkbox checkbox-sm">
                                    <span class="label-text">{{ __('Remove the picture') }}</span>
                                </label>
                            @endif
                            <p class="label whitespace-normal">{{ __('PNG, JPG, WebP or GIF up to 512 KB, square, on a transparent background. Wins over the name above wherever the category has an icon: the menus, the home page.') }}</p>
                        </fieldset>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Tile picture') }}</legend>
                            <div class="flex items-center gap-2">
                                <input type="file" name="tile_file" accept="image/png,image/jpeg,image/webp,image/gif" class="file-input file-input-bordered w-full">
                                <span class="w-16 h-16 shrink-0 rounded-field bg-base-200 flex items-center justify-center overflow-hidden">
                                    @if ($categoryTileImage = $category->tileImageUrl())
                                        <img src="{{ $categoryTileImage }}" alt="" class="w-14 h-14 object-contain">
                                    @endif
                                </span>
                            </div>
                            @if ($category->tile_image)
                                <label class="label cursor-pointer justify-start gap-2 mt-1">
                                    <input type="checkbox" name="tile_image_remove" value="1" class="checkbox checkbox-sm">
                                    <span class="label-text">{{ __('Remove the picture') }}</span>
                                </label>
                            @endif
                            <p class="label whitespace-normal">{{ __('A photo of what is inside — for the "Category tiles" block. PNG, JPG, WebP or GIF up to 2 MB, square, at least 256 px.') }}</p>
                        </fieldset>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($category->exists)
        <form id="category-delete" method="POST" action="{{ route('admin.catalog.categories.destroy', $category) }}" class="hidden">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
