@extends('admin.layout')

@section('title', __('Menus'))
@section('title_hint', __('Storefront navigation. A menu is drawn where a "Menu" block or a theme setting names it — "Where it stands" lists those places.'))

@section('content')
    @php $locale = admin_content_locale(); @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- The states as one row of tabs over the card, the screen's action at
         its end (app.css, `admin-tabs`); LINKS carrying the search. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Menus') }}">
            @foreach (['' => __('All'), 'enabled' => __('Enabled'), 'disabled' => __('Disabled')] as $statusKey => $statusLabel)
                <a href="{{ route('admin.menus.index', array_filter(['status' => $statusKey, 'q' => $filters['q']], fn ($v) => $v !== null && $v !== '')) }}"
                   @class(['admin-tab', 'is-active' => $filters['status'] === $statusKey])>
                    {{ $statusLabel }} <span class="admin-tab-count">{{ $statusCounts[$statusKey] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.menus.create') }}" class="btn btn-primary">+ {{ __('New menu') }}</a>
        </div>
    </div>

    {{-- One Alpine root: the bulk bar and every row's checkbox share
         `selected` — the categories list's own arrangement. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                @if ($filters['status'])
                    <input type="hidden" name="status" value="{{ $filters['status'] }}">
                @endif
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Name or code…') }}">
                </label>
                @if ($filters['q'] !== '' || $filters['status'])
                    <a href="{{ route('admin.menus.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick menus to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.menus.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    <div class="join">
                        <button name="action" value="enable" class="btn btn-sm join-item">{{ __('Enable') }}</button>
                        <button name="action" value="disable" class="btn btn-sm join-item">{{ __('Disable') }}</button>
                    </div>
                    <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Delete the selected menus with their items? The places where they stand are left empty.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($menus->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="menu" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['status'] ? __('No menus match this search.') : __('No menus yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($menus->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $menus->count() }}">
                                </th>
                                <th>{{ __('Name') }}</th>
                                <th class="text-end">{{ __('Menu items') }}</th>
                                <th>{{ __('Where it stands') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($menus as $menu)
                                @php $menuPlaces = $placements[$menu->code] ?? []; @endphp
                                <tr class="hover:bg-base-200/50 align-top" :class="selected.includes('{{ $menu->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $menu->id }}" x-model="selected"
                                               aria-label="{{ $menu->t('name', $locale) }}">
                                    </td>
                                    <td class="min-w-48">
                                        <a href="{{ route('admin.menus.items', $menu) }}"
                                           @class(['font-medium link link-hover', 'opacity-60' => ! $menu->enabled])>{{ $menu->t('name', $locale) ?: $menu->code }}</a>
                                        <div class="font-mono text-xs opacity-50">{{ $menu->code }}</div>
                                    </td>
                                    <td class="text-end tabular-nums">
                                        <a href="{{ route('admin.menus.items', $menu) }}" class="link link-hover">{{ $menu->items_count }}</a>
                                    </td>
                                    <td class="text-sm">
                                        @forelse ($menuPlaces as $menuPlace)
                                            <div @class(['opacity-50' => $menuPlace['off']])>{{ $menuPlace['label'] }} <span class="opacity-60">· {{ $menuPlace['where'] }}</span></div>
                                        @empty
                                            <span class="badge badge-sm badge-warning whitespace-nowrap">{{ __('nowhere — not drawn') }}</span>
                                        @endforelse
                                    </td>
                                    @include('torgash.menus::admin._status', ['menu' => $menu])
                                    <td class="text-end whitespace-nowrap">
                                        {{-- Items, settings, and — set apart — delete. --}}
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.menus.items', $menu) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Menu items') }}" aria-label="{{ __('Menu items') }}"><x-icon name="list-tree" /></a>
                                            <a href="{{ route('admin.menus.edit', $menu) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            {{-- Deleting a menu that stands somewhere empties those places: say which. --}}
                                            <form method="POST" action="{{ route('admin.menus.destroy', $menu) }}" class="inline"
                                                  data-confirm="{{ $menuPlaces !== []
                                                      ? __('Delete :name?', ['name' => $menu->t('name', $locale) ?: $menu->code]).' '.trans_choice('It stands in :count place, which will be left empty.|It stands in :count places, which will be left empty.', count($menuPlaces), ['count' => count($menuPlaces)])
                                                      : __('Delete :name?', ['name' => $menu->t('name', $locale) ?: $menu->code]) }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                    <x-icon name="trash-2" />
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
