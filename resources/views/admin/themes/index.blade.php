{{--
    Appearance → Themes (PLAN §37): every theme on disk as a card — its
    preview (assets/<preview> in the theme's folder), name, version, author,
    what it is based on, and which storefronts wear it — with "Configure" per
    storefront and "Put on" for the ones that do not.
--}}
@extends('admin.layout')

@section('title', __('Themes'))
@section('title_hint', __('A theme is a folder under themes/. One based on another draws only what it changes and takes the rest from it. Each storefront wears one theme and has its own settings for it.'))

@section('content')
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
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
