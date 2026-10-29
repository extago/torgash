@extends('admin.layout')

@section('title', __('Menu items') . ': ' . $menu->t('name', admin_content_locale()))
@section('own_heading', '1')

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <x-admin.form-bar>
        <a href="{{ route('admin.menus.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to menus') }}" aria-label="{{ __('Back to menus') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none">{{ __('Menu items') }} · <span class="font-mono">{{ $menu->code }}</span></div>
            <div class="font-semibold truncate leading-tight">{{ $menu->t('name', admin_content_locale()) }}</div>
        </div>
        <a href="{{ route('admin.menus.edit', $menu) }}" class="btn btn-ghost btn-sm gap-1.5"><x-icon name="settings" size="sm" /> {{ __('Menu settings') }}</a>
        <x-slot:actions>
            <a href="{{ route('admin.menus.items.create', $menu) }}" class="btn btn-primary btn-sm">+ {{ __('New item') }}</a>
        </x-slot:actions>
    </x-admin.form-bar>

    @if ($menu->code === (string) theme_setting('tabbar_menu', 'tabbar'))
        {{-- Said here, where the sixth entry gets added, not discovered on a phone. --}}
        <div class="alert alert-info text-sm">
            <x-icon name="smartphone" />
            <span>{{ __('This menu is the phone\'s bottom bar: the first five top-level entries with an address are drawn, each with its icon — the sixth and beyond are not.') }}</span>
        </div>
    @endif

    @include('torgash.menus::admin.partials.placements', ['menuPlaces' => $placements])

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body">
            @if ($byParent->isEmpty())
                <div class="text-sm opacity-50 py-6 text-center">{{ __('No items yet.') }}</div>
            @else
                {{-- One nested list, the shape the menu really has: the script
                     moves <li>s about and posts the new parents and order; with
                     no script the arrows on each row do the same one step at a
                     time. --}}
                <ol data-menu-tree data-arrange-url="{{ route('admin.menus.items.arrange', $menu) }}"
                    data-error="{{ __('The new order could not be saved. The page will reload with what is stored.') }}"
                    class="flex flex-col">@foreach ($byParent->get(0, collect()) as $item)@include('torgash.menus::admin.partials.item-row', ['item' => $item])@endforeach</ol>
                <p class="text-xs opacity-60 mt-2">{{ __('Drag a row by its handle: onto the upper or lower edge of another row to put it before or after, onto its middle to put it inside. The arrows do the same one step at a time.') }}</p>
            @endif
            <p class="text-xs opacity-60 mt-2">{{ __('A menu is placed on the site by a "Menu" block (Appearance → Blocks), or chosen in the theme\'s settings for the Catalogue button and the strip under the header.') }}</p>
        </div>
    </div>
@endsection

@push('scripts')
    <script nonce="{{ csp_nonce() }}" src="/modules/torgash.menus/assets/menu-tree.js" defer></script>
@endpush

