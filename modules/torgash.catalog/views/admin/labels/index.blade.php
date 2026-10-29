@extends('admin.layout')

@section('title', __('Labels'))
@section('title_hint', __('Badges on product cards and pages — "New", "Sale", your own. A label is put on a product by hand (on the product form), by its rules, or both: whichever applies, the badge shows.'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php $locale = admin_content_locale(); @endphp

    {{-- The states as one row of tabs over the card, the screen's action at
         its end (app.css, `admin-tabs`); LINKS carrying the search. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Labels') }}">
            @foreach (['' => __('All'), 'enabled' => __('Enabled'), 'disabled' => __('Disabled')] as $statusKey => $statusLabel)
                <a href="{{ route('admin.catalog.labels.index', array_filter(['status' => $statusKey, 'q' => $filters['q']], fn ($v) => $v !== null && $v !== '')) }}"
                   @class(['admin-tab', 'is-active' => (string) $filters['status'] === (string) $statusKey])>
                    {{ $statusLabel }} <span class="admin-tab-count">{{ $statusCounts[$statusKey] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.catalog.labels.create') }}" class="btn btn-primary">+ {{ __('New label') }}</a>
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
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by name…') }}">
                </label>
                @if ($filters['q'] !== '' || $filters['status'])
                    <a href="{{ route('admin.catalog.labels.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick labels to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.catalog.labels.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected labels? They are removed from every product.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($labels->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="tag" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['status'] ? __('No labels match this search.') : __('No labels yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($labels->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $labels->count() }}">
                                </th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Rules') }}</th>
                                <th class="text-end">{{ __('Put on by hand') }}</th>
                                <th class="text-end">{{ __('Sort order') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($labels as $label)
                                @php
                                    $ruleCount = $label->hasRules() ? count($label->conditions['rules']) : 0;
                                    $shownName = $label->plainName($locale);
                                @endphp
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $label->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $label->id }}" x-model="selected"
                                               aria-label="{{ $shownName }}">
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-2.5">
                                            {{-- Drawn by the storefront's own partial, at card size. --}}
                                            @include('torgash.catalog::partials.label-badge', [
                                                'badgeLabel' => $label, 'badgePlace' => 'list',
                                                'badgeText' => $shownName ?: '—', 'badgeHint' => false,
                                            ])
                                            @if ($label->tooltipText($locale) !== '')
                                                <x-icon name="message-square-text" size="sm" class="opacity-40" title="{{ $label->tooltipText($locale) }}" />
                                            @endif
                                            <div class="min-w-0">
                                                <a href="{{ route('admin.catalog.labels.edit', $label) }}" @class(['font-medium link link-hover', 'opacity-60' => ! $label->enabled])>
                                                    {{ $label->name_for_admin ?: $shownName }}
                                                </a>
                                                @if ($label->starts_at || $label->ends_at)
                                                    <div class="text-xs opacity-50">
                                                        {{ $label->starts_at?->format('d.m.Y') ?? '…' }} — {{ $label->ends_at?->format('d.m.Y') ?? '…' }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="opacity-70">
                                        @if ($ruleCount)
                                            {{ trans_choice(':count rule|:count rules', $ruleCount, ['count' => $ruleCount]) }},
                                            {{ ($label->conditions['match'] ?? 'all') === 'any' ? __('any of them') : __('all of them') }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-end tabular-nums opacity-70">{{ $label->products_count }}</td>
                                    <td class="text-end tabular-nums opacity-70">{{ $label->sort }}</td>
                                    @include('torgash.catalog::admin.labels._status', ['label' => $label])
                                    <td class="text-end whitespace-nowrap">
                                        {{-- Edit, and — set apart — delete: the categories list's row.
                                             A label has no page of its own, so there is no preview. --}}
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.catalog.labels.edit', $label) }}"
                                               class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.catalog.labels.destroy', $label) }}" class="inline"
                                                  data-confirm="{{ __('Delete this label? It is removed from every product.') }}">
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
