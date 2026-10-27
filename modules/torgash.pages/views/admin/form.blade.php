@extends('admin.layout')

@section('title', $page->exists ? ($page->t('title', admin_content_locale()) ?: __('Page')) : __('New page'))
@section('own_heading', '1')

@section('content')
    <form method="POST" class="flex flex-col gap-4"
          action="{{ $page->exists ? route('admin.pages.update', $page) : route('admin.pages.store') }}">
        @csrf
        @if ($page->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.pages.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to pages') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $page->exists ? __('Edit page') : __('New page') }}</div>
                <div class="font-semibold truncate leading-tight">
                    {{ $page->t('title', admin_content_locale()) ?: __('New page') }}
                </div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($page->exists)
                        <li><a href="{{ $page->url() }}" target="_blank" rel="noopener"><x-icon name="external-link" /> {{ __('Open on the site') }}</a></li>
                        <li><a href="{{ route('admin.pages.create', ['parent' => $page->id]) }}"><x-icon name="file-plus" /> {{ __('New page inside this one') }}</a></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="page-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    @endif
                </ul>
            </div>
            <a href="{{ route('admin.pages.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $page->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <x-admin.translatable-field name="title" :label="__('Title')" :value="$page->title ?? []" required />

                        {{-- The record goes with it, so the editor can offer pictures inside
                             the text once the page is saved (the rule the product
                             form follows: no id, nothing to hang a file on). --}}
                        <x-admin.translatable-field name="body" :label="__('Text')" :value="$page->body ?? []" rich :rows="16" :record="$page->exists ? $page : null" />

                        <p class="text-xs opacity-50">
                            {{ __('Placeholders work here exactly as they do in an HTML block — {callback_link}, the icon tokens and anything a module registers.') }}
                        </p>
                    </div>
                </section>

                {{-- Where the page is shown. Core's engine, the same editor a block and
                     a promotion use — storefront, language, customer group. --}}
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <x-admin.display-rules
                            :rules="$page->rules ?? []"
                            :match="$page->rules_match ?? 'all'"
                            :groups="['where', 'when', 'who']"
                            :heading="__('Shown when')"
                            :hint="__('No conditions means every storefront, every language and every visitor. A condition whose module is switched off hides the page rather than being ignored.')" />
                    </div>
                </section>

                {{-- Another module's section of this form (the page's videos).
                     Inside the form: whatever it draws is saved with the page. --}}
                {!! hook_filter('pages.page.form_sections', '', ['record' => $page]) !!}

                {{-- The same card as the product, the category and the brand carry, so
                     the fields, the wording and the hints are learnt once. `page_title`
                     is this module's own column name — see App\Core\Seo\MetaFields. --}}
                <x-seo.fields :record="$page" title-field="page_title"
                              :url-preview="$page->path ? url('/'.$page->path) : null" />
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success"
                                   @checked(old('enabled', $page->enabled ?? true))>
                            <span class="label-text">{{ __('Published') }}</span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="in_sitemap" value="1" class="toggle toggle-sm toggle-primary"
                                   @checked(old('in_sitemap', $page->in_sitemap ?? true))>
                            <span class="label-text">{{ __('In the sitemap') }}</span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-3 whitespace-normal">
                            <input type="checkbox" name="show_section_nav" value="1" class="toggle toggle-sm toggle-primary"
                                   @checked(old('show_section_nav', $page->show_section_nav ?? true))>
                            <span class="label-text">{{ __('Section navigation') }}</span>
                        </label>
                        <p class="text-xs opacity-60">{{ __('The list of the section\'s pages, beside the text. A page with no section around it never shows one.') }}</p>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Place on the site') }}</h3>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Inside') }}</legend>
                            <select name="parent_id" class="select select-bordered w-full">
                                <option value="">{{ __('Nothing — a top-level page') }}</option>
                                @foreach ($parents as $parentId => $parentLabel)
                                    <option value="{{ $parentId }}" @selected(old('parent_id', $page->parent_id ?? request('parent')) == $parentId)>
                                        {{ $parentLabel }}
                                    </option>
                                @endforeach
                            </select>
                        </fieldset>

                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Address') }}</legend>
                            <input name="slug" value="{{ old('slug', $page->slug) }}"
                                   class="input input-bordered w-full" placeholder="{{ __('made from the title') }}">
                            <p class="label text-xs opacity-60 whitespace-normal break-all">
                                {{-- The full address is the section's plus this segment, and
                                     moving the page rewrites it — along with every page under
                                     it, each leaving its own 301. --}}
                                {{ $page->exists ? url('/'.$page->path) : __('Renaming or moving it later leaves a 301 behind, so old links keep working.') }}
                            </p>
                        </fieldset>

                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                            <input type="number" name="position" value="{{ old('position', $page->position ?? 0) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Among the pages of the same section, smaller first.') }}</p>
                        </fieldset>
                    </div>
                </section>
            </div>
        </div>
    </form>

    {{-- Beside the main form, never inside it. --}}
    @if ($page->exists)
        <form id="page-delete" method="POST" action="{{ route('admin.pages.destroy', $page) }}"
              data-confirm="{{ __('Delete this page? Pages inside it move up a level and keep working.') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
