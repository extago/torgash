@extends('admin.layout')

@section('title', __('Warehouses'))

@section('content')
    @php $locale = admin_content_locale(); @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- One Alpine root: the bulk bar and every row's checkbox share
         `selected` — the categories list's own arrangement. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('Where the goods are: warehouses, shops, pickup points. A product opts into per-warehouse stock the moment it gets a quantity row on any warehouse — until then its Stock field on the product form works exactly as before.') }}
                </p>
                <a href="{{ route('admin.warehouses.create') }}" class="btn btn-primary btn-sm">+ {{ __('New warehouse') }}</a>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Name or address…') }}">
                </label>
                <div class="join">
                    @foreach (['' => __('All'), 'enabled' => __('Enabled'), 'disabled' => __('Disabled'), 'pickup' => __('Pickup points')] as $statusKey => $statusLabel)
                        <button type="submit" name="status" value="{{ $statusKey }}"
                                @class(['btn btn-sm join-item', 'btn-active' => (string) $filters['status'] === (string) $statusKey])>
                            {{ $statusLabel }} <span class="opacity-50">{{ $statusCounts[$statusKey] }}</span>
                        </button>
                    @endforeach
                </div>
                @if ($filters['q'] !== '' || $filters['status'])
                    <a href="{{ route('admin.warehouses.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick warehouses to switch several on or off at once, or delete empty ones.') }}
                </p>
                <form method="POST" action="{{ route('admin.warehouses.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected warehouses? Those still holding stock are kept.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($warehouses->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="warehouse" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['status'] ? __('No warehouses match this search.') : __('No warehouses yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($warehouses->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $warehouses->count() }}">
                                </th>
                                <th class="w-16">{{ __('Sort order') }}</th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Town') }}</th>
                                <th class="text-end">{{ __('Products') }}</th>
                                <th class="text-end">{{ __('Units') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($warehouses as $warehouse)
                                @php
                                    $holding = $holdings[$warehouse->id] ?? null;
                                    $stocked = (int) ($holding->stocked ?? 0);
                                @endphp
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $warehouse->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $warehouse->id }}" x-model="selected"
                                               aria-label="{{ $warehouse->t('name', $locale) }}">
                                    </td>
                                    <td class="tabular-nums opacity-60">{{ $warehouse->sort }}</td>
                                    <td class="min-w-56">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <a href="{{ route('admin.warehouses.edit', $warehouse) }}"
                                               @class(['font-medium link link-hover', 'opacity-60' => ! $warehouse->enabled])>{{ $warehouse->t('name', $locale) }}</a>
                                            @if ($warehouse->is_default)
                                                <span class="badge badge-sm badge-primary whitespace-nowrap"
                                                      title="{{ __('Returns and restocks that name no warehouse land here.') }}">{{ __('Default') }}</span>
                                            @endif
                                            @if ($warehouse->is_pickup)
                                                <span class="badge badge-sm badge-outline whitespace-nowrap">{{ __('Pickup') }}</span>
                                            @elseif ($warehouse->is_public)
                                                <span class="badge badge-sm badge-ghost whitespace-nowrap">{{ __('Shown to customers') }}</span>
                                            @endif
                                        </div>
                                        @if ($warehouse->address)
                                            <div class="text-xs opacity-50 mt-0.5">{{ $warehouse->address }}</div>
                                        @endif
                                    </td>
                                    <td class="text-sm opacity-70">{{ $placeNames[$warehouse->place_id] ?? '—' }}</td>
                                    <td class="text-end tabular-nums">
                                        @if ($stocked)
                                            <a href="{{ route('admin.warehouses.stock', [$warehouse, 'here' => 1]) }}" class="link link-hover">{{ $stocked }}</a>
                                        @else
                                            <span class="opacity-40">0</span>
                                        @endif
                                    </td>
                                    <td @class(['text-end tabular-nums', 'opacity-40' => ! (int) ($holding->units ?? 0)])>{{ (int) ($holding->units ?? 0) }}</td>
                                    @include('torgash.warehouses::admin._status', ['warehouse' => $warehouse])
                                    <td class="text-end whitespace-nowrap">
                                        {{-- Stock, edit, and — set apart — delete. --}}
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.warehouses.stock', $warehouse) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Stock') }}" aria-label="{{ __('Stock') }}">
                                                <x-icon name="package" />
                                            </a>
                                            <a href="{{ route('admin.warehouses.edit', $warehouse) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            @if ($stocked)
                                                <span class="btn btn-ghost btn-sm btn-square btn-disabled"
                                                      title="{{ __('Still holds stock — move or clear it before deleting.') }}">
                                                    <x-icon name="trash-2" class="opacity-40" />
                                                </span>
                                            @else
                                                <form method="POST" action="{{ route('admin.warehouses.destroy', $warehouse) }}" class="inline"
                                                      data-confirm="{{ __('Delete :name?', ['name' => $warehouse->t('name', $locale)]) }}">
                                                    @csrf @method('DELETE')
                                                    <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                        <x-icon name="trash-2" />
                                                    </button>
                                                </form>
                                            @endif
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
