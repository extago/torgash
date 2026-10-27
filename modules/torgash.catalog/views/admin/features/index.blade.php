@extends('admin.layout')

@section('title', __('Characteristics'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php
        $locale = admin_content_locale();
        $purposeLabels = [
            'filter' => __('Plain characteristic'),
            'variation' => __('Variation axis'),
            'brand' => __('Brand'),
        ];
        $typeLabels = [
            'select' => __('A value from a list'),
            'text' => __('Free text'),
            'number' => __('Number'),
        ];
        $allCount = $purposeCounts->sum();
    @endphp

    {{-- One Alpine root: the bulk bar and every row's checkbox share
         `selected` — the product list's own arrangement. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('Shared, catalogued characteristics: filtered by, shown in the specifications, switching variations. A one-off property of a single product belongs in that product\'s Individual attributes instead.') }}
                </p>
                <a href="{{ route('admin.catalog.features.create') }}" class="btn btn-primary btn-sm">+ {{ __('New characteristic') }}</a>
            </div>

            {{-- Search + purpose. A GET form: every state is an address. --}}
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow"
                           placeholder="{{ __('Search by name or code…') }}">
                </label>
                <div class="join">
                    <button type="submit" name="purpose" value="" @class(['btn btn-sm join-item', 'btn-active' => ! $filters['purpose']])>
                        {{ __('All') }} <span class="opacity-50">{{ $allCount }}</span>
                    </button>
                    @foreach ($purposeLabels as $purposeKey => $purposeLabel)
                        <button type="submit" name="purpose" value="{{ $purposeKey }}" @class(['btn btn-sm join-item', 'btn-active' => $filters['purpose'] === $purposeKey])>
                            {{ $purposeLabel }} <span class="opacity-50">{{ $purposeCounts[$purposeKey] ?? 0 }}</span>
                        </button>
                    @endforeach
                </div>
                @if ($filters['q'] !== '' || $filters['purpose'])
                    <a href="{{ route('admin.catalog.features.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            {{-- The same always-48px strip as the product list: a hint or the
                 bulk actions, so ticking a row never moves the table. --}}
            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick characteristics to change their purpose or delete several at once.') }}
                </p>
                <form method="POST" action="{{ route('admin.catalog.features.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    <div class="join">
                        <button name="action" value="filter" class="btn btn-sm join-item">{{ __('Make plain') }}</button>
                        <button name="action" value="variation" class="btn btn-sm join-item">{{ __('Make variation axes') }}</button>
                    </div>
                    <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Delete the selected characteristics? Their values on products are removed too.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($features->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="sliders-horizontal" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['purpose'] ? __('No characteristics match this search.') : __('No characteristics yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($features->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $features->count() }}">
                                </th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Code') }}</th>
                                <th>{{ __('Purpose') }}</th>
                                <th>{{ __('Where it is shown') }}</th>
                                <th>{{ __('Input type') }}</th>
                                <th class="text-end">{{ __('Values') }}</th>
                                <th class="text-end">{{ __('Products') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($features as $feature)
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $feature->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $feature->id }}" x-model="selected"
                                               aria-label="{{ $feature->t('name', $locale) }}">
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.catalog.features.edit', $feature) }}" class="font-medium link link-hover">{{ $feature->t('name', $locale) ?: '—' }}</a>
                                        @if ($feature->group)
                                            <div class="text-xs opacity-50">{{ $feature->group->t('name', $locale) }}</div>
                                        @endif
                                    </td>
                                    <td><span class="font-mono text-xs opacity-70">{{ $feature->code }}</span></td>
                                    <td>
                                        <span @class(['badge badge-sm whitespace-nowrap', 'badge-primary badge-soft' => $feature->purpose === 'variation', 'badge-accent badge-soft' => $feature->purpose === 'brand', 'badge-ghost' => $feature->purpose === 'filter'])>
                                            {{ $purposeLabels[$feature->purpose] ?? $feature->purpose }}
                                        </span>
                                    </td>
                                    {{-- Where it is drawn, a different question from whether
                                         it can be filtered by: nothing ticked still leaves the
                                         characteristic addressable. --}}
                                    <td>
                                        @php $shownIn = array_filter($places, fn ($p) => $feature->shows($p['key'])); @endphp
                                        @if ($shownIn === [])
                                            <span class="badge badge-sm badge-warning badge-outline">{{ __('Nowhere') }}</span>
                                        @else
                                            <span class="flex flex-wrap gap-1">
                                                @foreach ($shownIn as $place)
                                                    <span class="badge badge-sm badge-ghost whitespace-nowrap">{{ label_text($place['label'] ?? null, null, $place['key']) }}</span>
                                                @endforeach
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-sm opacity-70 whitespace-nowrap">{{ $typeLabels[$feature->input_type] ?? $feature->input_type }}</td>
                                    <td class="text-end tabular-nums opacity-70">{{ $feature->isChoiceType() ? $feature->values_count : '—' }}</td>
                                    <td class="text-end tabular-nums opacity-70">{{ $feature->assignments_count }}</td>
                                    <td class="text-end whitespace-nowrap">
                                        <a href="{{ route('admin.catalog.features.edit', $feature) }}" class="btn btn-ghost btn-sm">{{ __('Edit') }}</a>
                                        <form method="POST" action="{{ route('admin.catalog.features.destroy', $feature) }}" class="inline"
                                              data-confirm="{{ __('Delete this characteristic? Its values on products are removed too.') }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-ghost btn-sm text-error">{{ __('Delete') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $features->links() }}
            @endif
        </div>
    </div>
@endsection
