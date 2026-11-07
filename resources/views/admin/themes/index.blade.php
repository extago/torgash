{{--
    Appearance → Themes (PLAN §37): every theme on disk as a card — its
    preview (assets/<preview> in the theme's folder), name, version, author,
    what it is based on, and which storefronts wear it — with "Configure" per
    storefront and "Put on" for the ones that do not.
--}}
@extends('admin.layout')

@section('title', __('Themes'))
@section('title_hint', __('A theme is a folder under themes/. One based on another draws only what it changes and takes the rest from it. Each storefront wears one theme and has its own settings for it.'))

{{-- Putting code on the server is the built-in group's alone
     (ThemesController::upload); everybody else sees the cards only. --}}
@if ($canUpload)
    @section('title_actions')
        <button type="button" class="btn btn-primary gap-1.5" data-open-dialog="modal-theme-upload">
            <x-icon name="upload" size="sm" /> {{ __('Upload a theme') }}
        </button>
    @endsection
@endif

@section('content')
    @if ($uploadsOff)
        <p class="text-xs opacity-60">{{ __('Uploading code through the admin is switched off on this server (TORGASH_CODE_UPLOAD in .env): a theme is added by putting its folder into themes/.') }}</p>
    @endif
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
        @foreach ($cards as $themeCard)
            @php
                $themeMeta = $themeCard['meta'];
                $themeDescription = $themeMeta['description'] ?? null;
                $themeDescription = label_text($themeDescription) ?: null;
                $themeAuthor = $themeMeta['author'] ?? null;
            @endphp
            <div class="card bg-base-100 border border-base-300/60 shadow-sm overflow-hidden">
                <figure class="w-full aspect-[16/10] bg-base-200 flex items-center justify-center">
                    @if ($themeCard['preview'])
                        <img src="{{ $themeCard['preview'] }}" alt="" class="w-full h-full object-cover object-top">
                    @else
                        <x-icon name="swatch-book" size="xl" class="opacity-20" />
                    @endif
                </figure>
                <div class="card-body gap-3">
                    <div>
                        <div class="flex items-baseline gap-2 flex-wrap">
                            <h2 class="card-title text-base">{{ $themeCard['name'] }}</h2>
                            @if (! empty($themeMeta['version']))
                                <span class="text-xs opacity-50">{{ $themeMeta['version'] }}</span>
                            @endif
                        </div>
                        <div class="text-xs opacity-60 flex flex-wrap gap-x-3">
                            <span class="font-mono">{{ $themeCard['dir'] }}</span>
                            @if ($themeCard['bundled'])
                                <span>{{ __('ships with the engine') }}</span>
                            @endif
                            @if ($themeCard['parent'])
                                <span>{{ __('based on :theme', ['theme' => $themeCard['parent']]) }}</span>
                            @endif
                            @if (is_array($themeAuthor) && ! empty($themeAuthor['name']))
                                @if (! empty($themeAuthor['url']) && preg_match('#^https?://#', $themeAuthor['url']))
                                    <a href="{{ $themeAuthor['url'] }}" class="link link-hover" target="_blank" rel="noopener">{{ $themeAuthor['name'] }}</a>
                                @else
                                    <span>{{ $themeAuthor['name'] }}</span>
                                @endif
                            @endif
                        </div>
                    </div>

                    @if ($themeDescription)
                        <p class="text-sm opacity-70">{{ $themeDescription }}</p>
                    @endif

                    <div class="flex flex-col gap-2 mt-auto">
                        @forelse ($themeCard['storefronts'] as $themeStorefront)
                            @php
                                $themeStorefrontUrl = $themeStorefront->url('/');
                                $themeStorefrontHost = $themeStorefrontUrl ? parse_url($themeStorefrontUrl, PHP_URL_HOST) : null;
                                // A storefront with an address of its own is configured THERE
                                // (the preview frame shares its origin): the button signs the
                                // person in on that address and says so.
                                $themeStorefrontAway = ! \App\Core\Access\Handoff::isHome(request(), $themeStorefront);
                            @endphp
                            <div class="flex items-center gap-2">
                                <span class="badge badge-success badge-sm">{{ __('in use') }}</span>
                                <div class="flex-1 min-w-0 flex flex-col">
                                    @if ($themeStorefrontUrl)
                                        <a href="{{ $themeStorefrontUrl }}" target="_blank" rel="noopener" class="link link-hover text-sm truncate">{{ $themeStorefront->name }}</a>
                                        <span class="text-xs opacity-60 truncate">{{ $themeStorefrontHost }}</span>
                                    @else
                                        <span class="text-sm truncate">{{ $themeStorefront->name }}</span>
                                    @endif
                                </div>
                                @if ($themeCard['configurable'])
                                    @if ($themeStorefrontAway)
                                        <form method="POST" action="{{ route('admin.themes.open', $themeStorefront) }}" target="_blank">
                                            @csrf
                                            <button class="btn btn-primary btn-outline btn-sm gap-2" title="{{ __('Opens on :host', ['host' => $themeStorefrontHost]) }}">
                                                <x-icon name="sliders-horizontal" /> {{ __('Configure') }} <x-icon name="external-link" />
                                            </button>
                                        </form>
                                    @else
                                        <a href="{{ route('admin.themes.customize', $themeStorefront) }}" class="btn btn-primary btn-sm gap-2"><x-icon name="sliders-horizontal" /> {{ __('Configure') }}</a>
                                    @endif
                                @endif
                            </div>
                        @empty
                            <span class="text-xs opacity-50">{{ __('No storefront wears it.') }}</span>
                        @endforelse

                        @php $themeOthers = $storefronts->where('theme', '!=', $themeCard['dir']); @endphp
                        @if ($themeOthers->isNotEmpty())
                            <form method="POST" action="{{ route('admin.themes.apply') }}" class="join w-full"
                                  data-confirm="{{ __('Put this theme on the storefront? Its visitors see it at once.') }}">
                                @csrf
                                <input type="hidden" name="theme" value="{{ $themeCard['dir'] }}">
                                <select name="storefront" class="select select-bordered select-sm join-item flex-1 min-w-0" aria-label="{{ __('Storefront') }}">
                                    @foreach ($themeOthers as $themeOther)
                                        <option value="{{ $themeOther->id }}">{{ $themeOther->name }}</option>
                                    @endforeach
                                </select>
                                <button class="btn btn-outline btn-sm join-item">{{ __('Put on') }}</button>
                            </form>
                        @endif

                        {{-- A theme of the shop's own, worn by nobody and based on
                             by nobody, may go (ThemeArchive::undeletable). The
                             engine's own themes never show the button. --}}
                        @if ($canDelete && ! $themeCard['bundled'])
                            @if ($themeCard['undeletable'] === null)
                                <form method="POST" action="{{ route('admin.themes.destroy', $themeCard['dir']) }}" class="flex justify-end"
                                      data-confirm="{{ __('Delete the theme ":theme"? Its folder is moved to storage/app/theme-backups/; the settings storefronts saved for it stay.', ['theme' => $themeCard['name']]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-ghost btn-sm text-error gap-1.5"><x-icon name="trash-2" size="sm" /> {{ __('Delete') }}</button>
                                </form>
                            @else
                                <span class="text-xs opacity-50">{{ $themeCard['undeletable'] }}</span>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if ($canUpload)
        <x-modal name="theme-upload" :title="__('Upload a theme')" icon="upload">
            <form method="POST" action="{{ route('admin.themes.upload') }}" enctype="multipart/form-data" class="flex flex-col gap-4">
                @csrf
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Theme archive (.zip)') }}</legend>
                    <input type="file" name="archive" accept=".zip,application/zip" required class="file-input file-input-bordered w-full">
                    <p class="label whitespace-normal">{{ __('The theme\'s folder, zipped: theme.json at the root of the archive or in one folder, which becomes the folder under themes/. Up to 20 MB.') }}</p>
                </fieldset>
                <ul class="text-xs opacity-70 list-disc pl-5 flex flex-col gap-1">
                    <li>{{ __('The theme.json must name its author: a theme without one counts as the engine\'s and is replaced whole by the next update.') }}</li>
                    <li>{{ __('A newer version of a theme already here replaces it. The old folder is kept in storage/app/theme-backups/ and put back if anything fails.') }}</li>
                    <li>{{ __('The themes that ship with the engine are updated with it and cannot be uploaded or deleted here.') }}</li>
                    <li>{{ __('A theme is code that runs with the shop\'s full rights. Upload only what you got from its author.') }}</li>
                </ul>
                <div>
                    <button class="btn btn-primary btn-sm gap-1.5"><x-icon name="upload" size="sm" /> {{ __('Upload') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
@endsection
