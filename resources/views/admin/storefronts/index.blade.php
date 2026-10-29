@extends('admin.layout')

@section('title', __('Storefronts'))
@section('title_hint', __('A storefront is a site of the shop: the domains it answers on, its languages and currencies, its theme and its contact details. An address no storefront claims is served by the default one.'))
@section('title_actions')
    <a href="{{ route('admin.storefronts.create') }}" class="btn btn-primary">+ {{ __('New storefront') }}</a>
@endsection

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Storefront') }}</th>
                            <th>{{ __('Domains') }}</th>
                            <th>{{ __('Theme') }}</th>
                            <th>{{ __('Languages') }}</th>
                            <th>{{ __('Currencies') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($storefronts as $storefront)
                            @php $storefrontUrl = $storefront->url(); @endphp
                            <tr class="hover:bg-base-200/50 group">
                                <td>
                                    <a href="{{ route('admin.storefronts.edit', $storefront) }}" class="link link-hover font-medium">{{ $storefront->name }}</a>
                                    @if ($storefront->is_default)
                                        <span class="badge badge-sm badge-primary ml-1" title="{{ __('Serves every address no other storefront claims.') }}">{{ __('Default') }}</span>
                                    @endif
                                    <div class="text-xs opacity-50 font-mono">{{ $storefront->code }}</div>
                                </td>
                                <td class="text-sm">
                                    @foreach ($storefront->domains ?? [] as $domain)
                                        <div class="font-mono text-xs">{{ $domain }}</div>
                                    @endforeach
                                </td>
                                <td class="text-sm">
                                    {{ $themes[$storefront->theme] ?? $storefront->theme }}
                                    @can('themes.manage')
                                        <div><a href="{{ route('admin.themes.customize', $storefront) }}" class="link link-hover text-xs opacity-70">{{ __('Configure') }}</a></div>
                                    @endcan
                                </td>
                                <td>
                                    {{-- The default first and solid: it is the language a
                                         bare address answers in. --}}
                                    <div class="flex flex-wrap gap-1">
                                        @foreach (collect($storefront->locales ?? [])->sortBy(fn ($code) => $code === $storefront->default_locale ? 0 : 1) as $code)
                                            <span @class(['badge badge-sm font-mono uppercase', 'badge-neutral' => $code === $storefront->default_locale, 'badge-ghost' => $code !== $storefront->default_locale])
                                                  title="{{ $languages[$code] ?? $code }}{{ $code === $storefront->default_locale ? ' — '.__('default') : '' }}">{{ $code }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach (collect($storefront->currencies ?? [])->sortBy(fn ($code) => $code === $storefront->default_currency ? 0 : 1) as $code)
                                            <span @class(['badge badge-sm font-mono', 'badge-neutral' => $code === $storefront->default_currency, 'badge-ghost' => $code !== $storefront->default_currency])
                                                  title="{{ $code === $storefront->default_currency ? __('The shop\'s books are kept in it') : $code }}">{{ $code }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                @include('admin.storefronts._status', ['storefront' => $storefront])
                                <td class="text-end whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1">
                                        @unless ($storefront->is_default)
                                            <form method="POST" action="{{ route('admin.storefronts.default', $storefront) }}" class="inline"
                                                  data-confirm="{{ __('Make :name the default storefront? Every address no storefront claims will be served by it.', ['name' => $storefront->name]) }}">
                                                @csrf
                                                <button class="btn btn-ghost btn-sm btn-square opacity-0 group-hover:opacity-100 focus:opacity-100 pointer-coarse:opacity-100"
                                                        title="{{ __('Make it the default storefront') }}" aria-label="{{ __('Make it the default storefront') }}"><x-icon name="star" /></button>
                                            </form>
                                        @endunless
                                        @if ($storefrontUrl)
                                            <a href="{{ $storefrontUrl }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Open the storefront') }}" aria-label="{{ __('Open the storefront') }}"><x-icon name="external-link" /></a>
                                        @endif
                                        <a href="{{ route('admin.storefronts.edit', $storefront) }}" class="btn btn-ghost btn-sm btn-square"
                                           title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                        <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                        @if ($storefront->is_default)
                                            <span class="btn btn-ghost btn-sm btn-square btn-disabled" title="{{ __('The default storefront cannot be deleted — make another one the default first.') }}"><x-icon name="trash-2" /></span>
                                        @else
                                            <form method="POST" action="{{ route('admin.storefronts.destroy', $storefront) }}" class="inline"
                                                  data-confirm="{{ __('Delete the storefront :name? Its orders stay; its own settings go with it.', ['name' => $storefront->name]) }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
