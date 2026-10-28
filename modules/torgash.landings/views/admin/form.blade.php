@extends('admin.layout')

@section('title', $landing->exists ? $landing->name : __('New landing'))
@section('own_heading', '1')

@section('content')
    @php
        $landingLocal = fn ($date) => $date ? $date->format('Y-m-d\TH:i') : '';
        $landingPlacements = [
            'before' => [__('Above the description'), __('Before the product\'s own text, in the "Description" section.')],
            'instead' => [__('Instead of the description'), __('The page shows the landing; the text stays the product\'s — for feeds, exports and search engines.')],
            'after' => [__('Below the description'), __('After the product\'s own text.')],
            'tab' => [__('A tab of its own'), __('A section of the product page with its own name, among the others by its position.')],
        ];
        $landingSpaces = ['none' => __('No space'), 'small' => __('Small space'), 'large' => __('Large space')];
        $landingPlacement = old('placement', $landing->placement ?? 'after');
    @endphp

    <form method="POST" id="landing-form"
          action="{{ $landing->exists ? route('admin.landings.update', $landing) : route('admin.landings.store') }}"
          class="flex flex-col gap-4" x-data="{ placement: @js($landingPlacement) }">
        @csrf
        @if ($landing->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.landings.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to landings') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $landing->exists ? __('Edit landing') : __('New landing') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $landing->exists ? $landing->name : __('New landing') }}</div>
            </div>
            @if ($landing->exists)
                @if ($previewProduct)
                    <a href="{{ route('catalog.product', $previewProduct->slug) }}?landing_preview={{ $landing->id }}" target="_blank" rel="noopener"
                       class="btn btn-ghost btn-sm gap-1.5" title="{{ __('As saved, on :product — even while it is switched off', ['product' => $previewProduct->t('name')]) }}">
                        <x-icon name="eye" /> {{ __('Look at it') }}
                    </a>
                @endif
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li><button type="submit" form="landing-copy"><x-icon name="copy" /> {{ __('Duplicate') }}</button></li>
                        <li><a href="{{ route('admin.landings.export', $landing) }}"><x-icon name="download" /> {{ __('Download as ZIP') }}</a></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="landing-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.landings.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $landing->exists ? __('Save') : __('Create') }}</button>
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
                <div class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <label class="fieldset p-0">
                            <span class="fieldset-legend">{{ __('Name') }} *</span>
                            <input name="name" value="{{ old('name', $landing->name) }}" required maxlength="190" class="input input-bordered w-full">
                            <span class="text-xs opacity-60">{{ __('For the list. A tab takes it as its name unless it has one below.') }}</span>
                        </label>

                        <div class="flex items-end justify-between gap-2">
                            <div class="text-sm opacity-70">{{ __('The markup. Its pictures are "assets/…" — the files below. Placeholders put in the product\'s own data.') }}</div>
                            <x-admin.placeholders name="landing" :groups="['product', 'shop', 'page']" />
                        </div>
                        <x-admin.translatable-field name="html" :label="__('HTML')" :value="(array) ($landing->html ?? [])" textarea :rows="18" :record="$landing" />

                        <label class="fieldset p-0">
                            <span class="fieldset-legend">{{ __('CSS') }}</span>
                            <textarea name="css" rows="12" class="textarea textarea-bordered w-full font-mono text-sm" spellcheck="false">{{ old('css', $landing->css) }}</textarea>
                            <span class="text-xs opacity-60 leading-relaxed">{{ __('One for every language. It reaches only this landing, and the shop\'s styles do not reach in: write selectors for what is inside it, :host for the landing itself (:root and :scope mean the same). url(assets/…) names its files.') }}</span>
                        </label>
                    </div>
                </div>

                <div class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h2 class="font-semibold">{{ __('For which products') }}</h2>
                        <p class="text-sm opacity-70 max-w-3xl">{{ __('Products picked by hand see it; so do the products the conditions describe. With neither, every product sees it. A product left out never does.') }}</p>

                        <div class="grid lg:grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1">
                                <span class="text-sm font-medium">{{ __('Also for these products') }}</span>
                                <x-admin.picker name="picked[]" :options="app(\Torgash\Catalog\Admin\ProductLookup::class)->options(old('picked', $picked))" :selected="old('picked', $picked)"
                                                :source="route('admin.landings.products')"
                                                :placeholder="__('Search products by SKU or name…')" :empty="__('None picked.')" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <span class="text-sm font-medium">{{ __('Never for these products') }}</span>
                                <x-admin.picker name="excluded[]" :options="app(\Torgash\Catalog\Admin\ProductLookup::class)->options(old('excluded', $excluded))" :selected="old('excluded', $excluded)"
                                                :source="route('admin.landings.products')"
                                                :placeholder="__('Search products by SKU or name…')" :empty="__('None left out.')" />
                            </div>
                        </div>

                        <x-admin.display-rules :rules="$landing->rules ?? []" :match="$landing->rules_match ?? 'all'"
                                               :heading="__('Conditions')"
                                               :hint="__('A category takes its subcategories with it. \'Description is missing\' shows it only where the product has no text of its own.')" />
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <div class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <label class="flex items-center justify-between gap-3 cursor-pointer">
                            <span class="font-medium">{{ __('Shown') }}</span>
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-success" @checked(old('enabled', $landing->exists ? $landing->enabled : true))>
                        </label>

                        <div class="fieldset p-0">
                            <span class="fieldset-legend">{{ __('Where on the product page') }}</span>
                            <div class="flex flex-col gap-2">
                                @foreach ($landingPlacements as $placementKey => [$placementLabel, $placementHelp])
                                    <label class="flex items-start gap-2 cursor-pointer">
                                        <input type="radio" name="placement" value="{{ $placementKey }}" class="radio mt-0.5" x-model="placement" @checked($landingPlacement === $placementKey)>
                                        <span class="text-sm">{{ $placementLabel }}<span class="block text-xs opacity-60">{{ $placementHelp }}</span></span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div x-show="placement === 'tab'" x-cloak>
                            <x-admin.translatable-field name="tab_title" :label="__('Tab name')" :value="(array) ($landing->tab_title ?? [])" :placeholder="__('The landing\'s name')" />
                        </div>

                        <label class="fieldset p-0">
                            <span class="fieldset-legend">{{ __('Position') }}</span>
                            <input type="number" name="sort" value="{{ old('sort', $landing->sort ?? 0) }}" class="input input-bordered w-full">
                            <span class="text-xs opacity-60" x-show="placement !== 'tab'">{{ __('Landings in one place stand in this order, smaller first.') }}</span>
                            <span class="text-xs opacity-60" x-show="placement === 'tab'" x-cloak>{{ __('Among the page\'s sections: the description is 10, the characteristics 20.') }}</span>
                        </label>

                        <div class="grid grid-cols-2 gap-3">
                            @foreach (['space_top' => __('Space above'), 'space_bottom' => __('Space below')] as $spaceField => $spaceLabel)
                                <label class="fieldset p-0">
                                    <span class="fieldset-legend">{{ $spaceLabel }}</span>
                                    <select name="{{ $spaceField }}" class="select select-bordered w-full">
                                        @foreach ($landingSpaces as $spaceKey => $spaceName)
                                            <option value="{{ $spaceKey }}" @selected(old($spaceField, $landing->{$spaceField} ?? 'none') === $spaceKey)>{{ $spaceName }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                        </div>

                        {{-- A long landing folds as the description does, at the catalogue's height. --}}
                        <label class="flex items-start gap-3 cursor-pointer" x-show="placement !== 'tab'">
                            <input type="checkbox" name="fold" value="1" class="checkbox mt-0.5" @checked(old('fold', $landing->fold ?? false))>
                            <span class="text-sm">{{ __('Fold like the description') }}
                                <span class="block text-xs opacity-60">{{ __('A long landing shows its first screenful and "Read in full", at the height set for the description (Settings → Catalogue).') }}</span>
                            </span>
                        </label>

                        <div class="grid grid-cols-1 gap-3">
                            <label class="fieldset p-0">
                                <span class="fieldset-legend">{{ __('Shown from') }}</span>
                                <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $landingLocal($landing->starts_at)) }}" class="input input-bordered w-full">
                            </label>
                            <label class="fieldset p-0">
                                <span class="fieldset-legend">{{ __('Shown until') }}</span>
                                <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $landingLocal($landing->ends_at)) }}" class="input input-bordered w-full">
                            </label>
                            <span class="text-xs opacity-60">{{ __('Empty — always. Weekdays and hours are in the conditions.') }}</span>
                        </div>
                    </div>
                </div>

                @if ($landing->exists)
                    {{-- Its own forms stand after the main one; these controls reach them by `form=` (never a form inside a form). --}}
                    <div class="card bg-base-100 border border-base-300" id="files">
                        <div class="card-body gap-3">
                            <h2 class="font-semibold">{{ __('Files') }}</h2>
                            <input type="file" name="files[]" multiple form="landing-upload" class="file-input file-input-sm w-full"
                                   accept=".webp,.png,.jpg,.jpeg,.gif,.avif,.woff,.woff2,.ttf,.otf,.mp4,.webm">
                            <div class="flex gap-2">
                                <input name="folder" form="landing-upload" placeholder="{{ __('Folder (optional)') }}" class="input input-bordered input-sm flex-1 min-w-0">
                                <button form="landing-upload" class="btn btn-sm gap-1"><x-icon name="upload" size="sm" /> {{ __('Upload') }}</button>
                            </div>
                            <span class="text-xs opacity-60">{{ __('Pictures (WebP, PNG, JPEG, GIF, AVIF), fonts and video, up to :mb MB each. SVG is not taken: on the shop\'s address it could run a script.', ['mb' => \Torgash\Landings\Files::MAX_SIZE / 1048576]) }}</span>

                            @forelse ($files as $landingFile)
                                <div class="flex items-center gap-2 text-sm">
                                    @if (preg_match('/\.(webp|png|jpe?g|gif|avif)$/i', $landingFile['path']))
                                        <img src="{{ $landingFile['url'] }}" alt="" class="w-10 h-10 object-cover rounded border border-base-300 shrink-0" loading="lazy">
                                    @else
                                        <span class="w-10 h-10 grid place-items-center rounded border border-base-300 shrink-0"><x-icon name="file" /></span>
                                    @endif
                                    <span class="font-mono text-xs truncate flex-1 min-w-0" title="{{ $landingFile['path'] }}">{{ $landingFile['path'] }}</span>
                                    <button type="submit" form="landing-file-delete" name="path" value="{{ $landingFile['path'] }}"
                                            class="btn btn-ghost btn-xs btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }} {{ $landingFile['path'] }}">
                                        <x-icon name="trash-2" size="sm" />
                                    </button>
                                </div>
                            @empty
                                <span class="text-sm opacity-60">{{ __('No files yet.') }}</span>
                            @endforelse
                        </div>
                    </div>

                    <div class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h2 class="font-semibold">{{ __('From a ZIP') }}</h2>
                            <p class="text-xs opacity-70 leading-relaxed">{{ __('index.html (index.ru.html for another language), style.css and an assets folder — as a designer hands it over. The HTML, CSS and files are replaced; where and for whom stay.') }}</p>
                            <input type="file" name="archive" accept=".zip,application/zip" form="landing-import" class="file-input file-input-sm w-full">
                            <button form="landing-import" class="btn btn-sm gap-1"><x-icon name="file-archive" size="sm" /> {{ __('Replace from the ZIP') }}</button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </form>

    @if ($landing->exists)
        <form method="POST" id="landing-copy" action="{{ route('admin.landings.copy', $landing) }}">@csrf</form>
        <form method="POST" id="landing-delete" action="{{ route('admin.landings.destroy', $landing) }}"
              data-confirm="{{ __('Delete this landing and its files?') }}">@csrf @method('DELETE')</form>
        <form method="POST" id="landing-upload" action="{{ route('admin.landings.files.upload', $landing) }}" enctype="multipart/form-data">@csrf</form>
        <form method="POST" id="landing-file-delete" action="{{ route('admin.landings.files.delete', $landing) }}"
              data-confirm="{{ __('Delete this file?') }}">@csrf @method('DELETE')</form>
        <form method="POST" id="landing-import" action="{{ route('admin.landings.import', $landing) }}" enctype="multipart/form-data"
              data-confirm="{{ __('Replace the HTML, CSS and files of this landing with the archive\'s?') }}">@csrf</form>
    @endif
@endsection
