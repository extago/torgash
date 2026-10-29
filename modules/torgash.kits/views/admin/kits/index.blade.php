@extends('admin.layout')

@section('title', __('Kits'))
@section('title_hint', __('Separate products that are cheaper together. A kit written for one product is shown on it; a kit with no product of its own is placed by conditions — a category with everything under it, a characteristic — and the product whose page it is on becomes the main one.'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php
        $locale = admin_content_locale();
        // A tab keeps the other filters: switching "shared" must not forget the search.
        $tabUrl = fn (array $change) => route('admin.kits.index', array_filter(array_merge($filters, $change), fn ($v) => $v !== null && $v !== ''));
    @endphp

    {{-- Whose the kits are, as one row of tabs over the card, the screen's
         action at its end (app.css, `admin-tabs`); the status is a filter in
         the toolbar. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Kits') }}">
            @foreach (['' => [__('All'), $counts->all_n], 'shared' => [__('Shared'), $counts->shared_n], 'product' => [__('One product\'s'), $counts->product_n]] as $whoseKey => [$whoseText, $whoseN])
                <a href="{{ $tabUrl(['whose' => $whoseKey]) }}" @class(['admin-tab', 'is-active' => (string) $filters['whose'] === $whoseKey])>
                    {{ $whoseText }} <span class="admin-tab-count">{{ $whoseN }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.kits.create') }}" class="btn btn-primary">+ {{ __('New kit') }}</a>
        </div>
    </div>

    {{-- One Alpine root: the bulk bar and every row's checkbox share
         `selected` — the categories list's own arrangement. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                @if ($filters['whose'])
                    <input type="hidden" name="whose" value="{{ $filters['whose'] }}">
                @endif
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by name or product…') }}">
                </label>
                <select name="status" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Status') }}">
                    @foreach (['' => [__('Any status'), null], 'enabled' => [__('Enabled'), $counts->enabled_n], 'disabled' => [__('Disabled'), $counts->disabled_n]] as $statusKey => [$statusText, $statusN])
                        <option value="{{ $statusKey }}" @selected((string) $filters['status'] === $statusKey)>{{ $statusText }}@if ($statusN !== null) ({{ $statusN }})@endif</option>
                    @endforeach
                </select>
                @if ($filters['q'] !== '' || $filters['whose'] || $filters['status'])
                    <a href="{{ route('admin.kits.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick kits to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.kits.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected kits?') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($kits->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="package-plus" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['whose'] || $filters['status'] ? __('No kits match this search.') : __('No kits yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($kits->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $kits->count() }}">
                                </th>
                                <th>{{ __('Kit') }}</th>
                                <th>{{ __('Main product') }}</th>
                                <th>{{ __('What the set is') }}</th>
                                <th class="text-end">{{ __('Kit items') }}</th>
                                <th>{{ __('Shown') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($kits as $kit)
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $kit->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $kit->id }}" x-model="selected"
                                               aria-label="{{ $kit->admin_name }}">
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.kits.edit', $kit) }}" @class(['font-medium link link-hover', 'opacity-60' => ! $kit->enabled])>{{ $kit->admin_name }}</a>
                                        <div class="text-xs opacity-50">{{ __('The customer sees:') }} {{ $kit->t('name', $locale) ?: '—' }}</div>
                                    </td>
                                    <td class="text-sm">
                                        @if ($kit->mainProduct)
                                            <a href="{{ route('admin.catalog.products.edit', $kit->mainProduct) }}" class="link link-hover inline-flex items-center gap-1">
                                                <x-icon name="package" size="sm" class="opacity-50" />
                                                {{ $kit->mainProduct->t('name', $locale) ?: $kit->mainProduct->sku }}
                                            </a>
                                        @elseif (empty($kit->rules))
                                            <span class="opacity-70">{{ __('Every product') }}</span>
                                        @else
                                            <span class="opacity-70">{{ trans_choice(':count condition|:count conditions', count($kit->rules), ['count' => count($kit->rules)]) }}</span>
                                        @endif
                                    </td>
                                    <td class="text-sm opacity-70">{{ \Torgash\Kits\Models\Kit::typeLabels()[$kit->type] ?? $kit->type }}</td>
                                    <td class="text-end tabular-nums">
                                        @if ($kit->slots_count)
                                            {{ $kit->slots_count }}
                                        @else
                                            <span class="text-warning" title="{{ __('A kit with nothing in it is not shown at all.') }}">0</span>
                                        @endif
                                    </td>
                                    <td class="text-sm opacity-70">{{ \Torgash\Kits\Models\Kit::placementLabels()[$kit->placement] ?? $kit->placement }}</td>
                                    @include('torgash.kits::admin.kits._status', ['kit' => $kit])
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.kits.edit', $kit) }}"
                                               class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <button form="delete-kit-{{ $kit->id }}" class="btn btn-ghost btn-sm btn-square text-error"
                                                    title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                <x-icon name="trash-2" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $kits->links() }}
            @endif
        </div>
    </div>

    {{-- Beside the table, never inside a row's own form. --}}
    @foreach ($kits as $kit)
        <form id="delete-kit-{{ $kit->id }}" method="POST" class="hidden"
              action="{{ route('admin.kits.destroy', $kit) }}"
              data-confirm="{{ __('Delete this kit?') }}">
            @csrf @method('DELETE')
        </form>
    @endforeach
@endsection
