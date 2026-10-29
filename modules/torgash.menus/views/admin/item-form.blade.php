@extends('admin.layout')

@section('title', $item->exists ? __('Edit item') : __('New item'))
@section('own_heading', '1')

@section('content')
    @php
        $locale = admin_content_locale();
        $itemOptions = (array) ($item->options ?? []);
        $opt = fn (string $key, $default = null) => old('options.'.$key, $itemOptions[$key] ?? $default);
    @endphp

    <form method="POST"
          action="{{ $item->exists ? route('admin.menus.items.update', [$menu, $item]) : route('admin.menus.items.store', $menu) }}"
          x-data="{ kind: @js(old('kind', $item->kind ?? 'link')), showWhen: @js((string) $opt('show_when', 'always')) }"
          class="flex flex-col gap-4 max-w-4xl">
        @csrf
        @if ($item->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.menus.items', $menu) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to items') }}" aria-label="{{ __('Back to items') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $menu->t('name', $locale) }}</div>
                <div class="font-semibold truncate leading-tight">{{ $item->exists ? ($item->adminLabel() ?: __('Edit item')) : __('New item') }}</div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($item->exists)
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="menu-item-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    @endif
                </ul>
            </div>
            <x-slot:actions>
                <a href="{{ route('admin.menus.items', $menu) }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $item->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error text-sm flex-col items-start gap-1">
                @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif

        {{-- WHAT the item is: its kind decides which of the fields below mean anything. --}}
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('What it is') }}</h3>

                <div class="grid md:grid-cols-2 gap-4">
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Kind') }}</legend>
                        <select name="kind" x-model="kind" class="select select-bordered w-full">
                            @foreach ($kinds as $kindKey => $kindLabel)
                                <option value="{{ $kindKey }}" @selected(old('kind', $item->kind ?? 'link') === $kindKey)>{{ $kindLabel }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Parent item') }}</legend>
                        <select name="parent_id" class="select select-bordered w-full">
                            <option value="">— {{ __('Root level') }} —</option>
                            @foreach ($parentOptions as $parentOption)
                                <option value="{{ $parentOption['id'] }}" @selected(old('parent_id', $item->parent_id) == $parentOption['id'])>{{ $parentOption['label'] }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                </div>

                <div x-show="kind === 'page'" x-cloak>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Page') }}</legend>
                        <select name="target" class="select select-bordered w-full">
                            <option value="">— {{ __('not set') }} —</option>
                            @foreach ($pages as $pageGroup => $pageOptions)
                                <optgroup label="{{ $pageGroup }}">
                                    @foreach ($pageOptions as $pageKey => $pageLabel)
                                        <option value="{{ $pageKey }}" @selected(old('target', $item->target) === $pageKey)>{{ $pageLabel }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <div class="text-xs opacity-60">{{ __('The address is built for the language the visitor reads in, and the item disappears by itself if the page does.') }}</div>
                    </fieldset>
                </div>

                <div x-show="kind === 'shortcut'" x-cloak>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Shortcut') }}</legend>
                        <select name="shortcut_target" class="select select-bordered w-full">
                            <option value="">— {{ __('not set') }} —</option>
                            @foreach ($shortcuts as $shortcutKey => $shortcutLabel)
                                <option value="{{ $shortcutKey }}" @selected(old('shortcut_target', $item->kind === 'shortcut' ? $item->target : null) === $shortcutKey)>{{ $shortcutLabel }}</option>
                            @endforeach
                        </select>
                        <div class="text-xs opacity-60">{{ __('A live entry a module keeps up to date: the basket carries its count, "Catalogue" opens the phone\'s menu. Modules add their own.') }}</div>
                    </fieldset>
                </div>

                <div x-show="kind === 'category'" x-cloak>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Category') }}</legend>
                        <select name="source_category_id" class="select select-bordered w-full">
                            <option value="">— {{ __('The whole catalogue (the top categories)') }} —</option>
                            @foreach ($categoryOptions as $categoryId => $categoryLabel)
                                <option value="{{ $categoryId }}" @selected(old('source_category_id', $item->source_category_id) == $categoryId)>{{ $categoryLabel }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                </div>

                <x-admin.translatable-field name="name" label="{{ __('Name') }}" :value="$item->name ?? []" />
                <div class="text-xs opacity-60 -mt-2" x-show="kind === 'page' || kind === 'category' || kind === 'shortcut'" x-cloak>{{ __('Leave empty to use the name of the page or category — it then follows a rename.') }}</div>

                <fieldset class="fieldset p-0" x-show="kind === 'link' || kind === 'category'" x-cloak>
                    <legend class="fieldset-legend">{{ __('URL') }}</legend>
                    <input name="url" value="{{ old('url', $item->url) }}" class="input input-bordered w-full" placeholder="https://… {{ __('or') }} /catalog">
                    <div class="text-xs opacity-60" x-show="kind === 'category'">{{ __('Optional: an address to lead to instead of the category\'s own.') }}</div>
                    <div class="text-xs opacity-60" x-show="kind === 'link'">{{ __('External link or a path inside the store. For the shop\'s own pages prefer "Shop page": its address follows the visitor\'s language.') }}</div>
                </fieldset>
            </div>
        </div>

        {{-- How a category opens (PLAN §39.4). --}}
        <div class="card bg-base-100 border border-base-300" x-show="kind === 'category'" x-cloak>
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('Subcategories') }}</h3>

                <div class="grid md:grid-cols-3 gap-4">
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Levels down') }}</legend>
                        <select name="options[depth]" class="select select-bordered w-full">
                            @foreach ([0 => __('None — only the category'), 1 => '1', 2 => '2', 3 => '3'] as $depthValue => $depthLabel)
                                <option value="{{ $depthValue }}" @selected((int) $opt('depth', 1) === $depthValue)>{{ $depthLabel }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                    <fieldset class="fieldset p-0 md:col-span-2"><legend class="fieldset-legend">{{ __('Show a subcategory') }}</legend>
                        <select name="options[show_when]" x-model="showWhen" class="select select-bordered w-full">
                            <option value="always">{{ __('Always') }}</option>
                            <option value="listed">{{ __('When it has products at all') }}</option>
                            <option value="orderable">{{ __('When something in it can be bought (a preorder counts)') }}</option>
                            <option value="in_stock">{{ __('When something in it is on the shelf') }}</option>
                        </select>
                        <div class="text-xs opacity-60">{{ __('Counted through the whole branch, for the products THIS visitor is shown — with a pickup point chosen and "hide the rest" on, by that point\'s assortment.') }}</div>
                    </fieldset>
                </div>

                <label class="label cursor-pointer justify-start gap-2 p-0" x-show="showWhen !== 'always'" x-cloak>
                    <input type="checkbox" name="options[hide_empty]" value="1" class="checkbox checkbox-sm" @checked($opt('hide_empty', false))>
                    <span class="label-text">{{ __('Hide the item itself when its category is empty by the same rule') }}</span>
                </label>

                <div class="grid md:grid-cols-2 gap-4">
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Order of the subcategories') }}</legend>
                        <select name="options[sort]" class="select select-bordered w-full">
                            <option value="tree" @selected($opt('sort', 'tree') === 'tree')>{{ __('As in the catalogue') }}</option>
                            <option value="name" @selected($opt('sort') === 'name')>{{ __('By name') }}</option>
                            <option value="count" @selected($opt('sort') === 'count')>{{ __('Most products first') }}</option>
                        </select>
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('At most per level') }}</legend>
                        <input type="number" min="0" max="100" name="options[limit]" value="{{ $opt('limit', 0) }}" class="input input-bordered w-full">
                        <div class="text-xs opacity-60">{{ __('0 — all of them. Past the limit a "N more" link leads to the category.') }}</div>
                    </fieldset>
                </div>

                <div class="flex flex-col gap-2">
                    <label class="label cursor-pointer justify-start gap-2 p-0">
                        <input type="checkbox" name="options[replace]" value="1" class="checkbox checkbox-sm" @checked($opt('replace', false))>
                        <span class="label-text">{{ __('Put the subcategories in place of the item') }} <span class="opacity-60">— {{ __('"the whole catalogue" this way becomes the list of departments, and stays up to date by itself') }}</span></span>
                    </label>
                    <label class="label cursor-pointer justify-start gap-2 p-0">
                        <input type="checkbox" name="options[all_link]" value="1" class="checkbox checkbox-sm" @checked($opt('all_link', false))>
                        <span class="label-text">{{ __('"Everything in …" as the first entry of the submenu') }}</span>
                    </label>
                    <label class="label cursor-pointer justify-start gap-2 p-0">
                        <input type="checkbox" name="options[counts]" value="1" class="checkbox checkbox-sm" @checked($opt('counts', false))>
                        <span class="label-text">{{ __('Show the number of products') }}</span>
                    </label>
                    <label class="label cursor-pointer justify-start gap-2 p-0">
                        <input type="checkbox" name="options[child_icons]" value="1" class="checkbox checkbox-sm" @checked($opt('child_icons', true))>
                        <span class="label-text">{{ __('Subcategories with their own icons') }}</span>
                    </label>
                </div>

                <x-admin.picker name="options[exclude][]" :label="__('Never show these subcategories')" :options="$categoryPicker"
                                :selected="array_map('intval', (array) $opt('exclude', []))" :empty="__('None excluded')" />

                {{-- Live: asked again whenever the form changes, from what is
                     typed rather than what is saved. `intersect` rather than
                     `load`, so a link item's form never counts anything. --}}
                <div class="rounded-lg border border-base-300 p-3 flex flex-col gap-2">
                    <h3 class="font-semibold">{{ __('What it opens into now') }}</h3>
                    <div hx-get="{{ route('admin.menus.items.preview', $menu) }}"
                         hx-include="closest form"
                         hx-trigger="intersect once, change from:closest form delay:300ms"
                         hx-sync="this:replace">
                        <span class="loading loading-dots loading-sm opacity-50"></span>
                    </div>
                </div>
            </div>
        </div>

        {{-- How it looks. --}}
        <div class="card bg-base-100 border border-base-300" x-show="kind !== 'divider'">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('How it looks') }}</h3>

                <div class="grid md:grid-cols-2 gap-4">
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Icon') }}</legend>
                        <div class="flex items-center gap-2">
                            @if ($item->icon && is_file(resource_path('icons/lucide/'.$item->icon.'.svg')))
                                <span class="w-10 h-10 rounded-lg bg-base-200 flex items-center justify-center shrink-0"><x-icon :name="$item->icon" /></span>
                            @endif
                            <input name="icon" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('icon', $item->icon) }}" class="input input-bordered w-full font-mono" placeholder="badge-percent">
                        </div>
                        <div class="text-xs opacity-60">{!! __('A name from :link. Empty — the page\'s or category\'s own icon, if it has one.', ['link' => '<a href="https://lucide.dev/icons/" target="_blank" rel="noopener" class="link">lucide.dev</a>']) !!}</div>
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Screens') }}</legend>
                        <select name="show_on" class="select select-bordered w-full">
                            <option value="all" @selected(old('show_on', $item->show_on ?? 'all') === 'all')>{{ __('Everywhere') }}</option>
                            <option value="desktop" @selected(old('show_on', $item->show_on) === 'desktop')>{{ __('Only on a computer') }}</option>
                            <option value="mobile" @selected(old('show_on', $item->show_on) === 'mobile')>{{ __('Only on a phone') }}</option>
                        </select>
                    </fieldset>
                </div>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <x-admin.translatable-field name="badge" label="{{ __('Badge') }}" :value="$item->badge ?? []" placeholder="-50%" />
                    </div>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Badge colour') }}</legend>
                        <div class="flex flex-wrap gap-2">
                            @foreach (\Torgash\Menus\Models\MenuItem::BADGE_STYLES as $badgeStyle)
                                <label class="cursor-pointer flex items-center gap-1.5">
                                    <input type="radio" name="badge_style" value="{{ $badgeStyle }}" class="radio radio-sm" @checked(old('badge_style', $item->badge_style ?? 'primary') === $badgeStyle)>
                                    <span class="badge badge-{{ $badgeStyle }} badge-sm">{{ __('Badge') }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>

                <x-admin.translatable-field name="note" label="{{ __('Small line under the name') }}" :value="$item->note ?? []" />
                <div class="text-xs opacity-60 -mt-2">{{ __('Shown by the looks that have room for it — the phone menu\'s rows ("Products on sale" under "Sale").') }}</div>

                <div class="grid md:grid-cols-2 gap-4">
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Custom CSS class') }}</legend>
                        <input name="css_class" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('css_class', $item->css_class) }}" class="input input-bordered w-full font-mono" placeholder="menu-item--sale">
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                        <input name="sort" type="number" value="{{ old('sort', $item->sort ?? 0) }}" class="input input-bordered w-full">
                    </fieldset>
                </div>

                <div class="flex flex-wrap gap-6">
                    <label class="label cursor-pointer justify-start gap-2 p-0">
                        <input type="checkbox" name="highlight" value="1" class="checkbox checkbox-sm" @checked(old('highlight', $item->highlight ?? false))>
                        <span class="label-text">{{ __('Set off in the shop\'s colour') }}</span>
                    </label>
                    <label class="label cursor-pointer justify-start gap-2 p-0">
                        <input type="checkbox" name="new_tab" value="1" class="checkbox checkbox-sm" @checked(old('new_tab', $item->new_tab ?? false))>
                        <span class="label-text">{{ __('Open in a new tab') }}</span>
                    </label>
                </div>
            </div>
        </div>

        {{-- Who sees it — core's display rules, the same editor a block has. --}}
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <x-admin.display-rules :rules="$item->rules ?? []" :match="$item->rules_match ?? 'all'" :groups="['where', 'when', 'who']"
                                       :heading="__('Show this item only when…')" />
                <label class="label cursor-pointer justify-start gap-2 p-0">
                    <input type="hidden" name="enabled" value="0">
                    <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $item->enabled ?? true))>
                    <span class="label-text">{{ __('Enabled') }}</span>
                </label>
            </div>
        </div>
    </form>

    @if ($item->exists)
        <form id="menu-item-delete" method="POST" action="{{ route('admin.menus.items.destroy', [$menu, $item]) }}"
              data-confirm="{{ __('Delete this item and everything inside it?') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
