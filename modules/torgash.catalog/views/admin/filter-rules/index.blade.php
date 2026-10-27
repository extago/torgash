@extends('admin.layout')

@section('title', __('Filter pages'))

@section('content')
    @php
        $isFiltered = $filters['q'] !== '' || $filters['tab'] !== '';
        $contentLocale = admin_content_locale();
    @endphp

    @include('torgash.catalog::admin.filter-pages._nav', ['filterScreen' => 'rules'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('A rule makes one page per value of a characteristic, in the categories you name. Running it again adds what is missing and never removes a page: an address that has been published is not withdrawn because stock moved. Titles and descriptions come from the SEO templates, not from here.') }}
                </p>
                <div class="flex gap-2">
                    @if ($counts['on'] > 0)
                        <form method="POST" action="{{ route('admin.catalog.filter-rules.generate') }}" class="inline">
                            @csrf
                            <button class="btn btn-outline btn-sm gap-1.5" title="{{ __('Runs in the background; watch it under System → Background tasks.') }}">
                                <x-icon name="wand-sparkles" size="sm" /> {{ __('Run all rules') }}
                            </button>
                        </form>
                    @endif
                    <a href="{{ route('admin.catalog.filter-rules.create') }}" class="btn btn-primary btn-sm">+ {{ __('New rule') }}</a>
                </div>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by name…') }}">
                </label>
                <input type="hidden" name="tab" value="{{ $filters['tab'] }}">
                @if ($isFiltered)
                    <a href="{{ route('admin.catalog.filter-rules.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="join flex-wrap -mt-1">
                @foreach (['' => __('All'), 'on' => __('Enabled'), 'off' => __('Disabled')] as $tabKey => $tabLabel)
                    <a href="{{ request()->fullUrlWithQuery(['tab' => $tabKey ?: null]) }}"
                       @class(['btn btn-sm join-item', 'btn-active' => $filters['tab'] === $tabKey])>
                        {{ $tabLabel }} <span class="opacity-50">{{ $counts[$tabKey] }}</span>
                    </a>
                @endforeach
            </div>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick rules to switch several on or off at once, or delete them — the pages they made stay published.') }}
                </p>
                <form method="POST" action="{{ route('admin.catalog.filter-rules.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected rules? The pages they made stay published.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($rules->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="wand-sparkles" size="xl" class="opacity-40" />
                    <p class="mt-2 max-w-xl mx-auto">{{ $isFiltered ? __('No rules match this search.') : __('No rules yet. A rule is "a page per make of laptop" or "a page per town", written once and run whenever the catalogue changes.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($rules->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $rules->count() }}">
                                </th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('A page per') }}</th>
                                <th>{{ __('Categories') }}</th>
                                <th class="text-end">{{ __('Pages') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rules as $rule)
                                @php
                                    $ruleCategories = collect($rule->category_ids ?? [])->map(fn ($id) => $categoryNames[$id] ?? '#'.$id);
                                @endphp
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $rule->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $rule->id }}" x-model="selected" aria-label="{{ $rule->name }}">
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.catalog.filter-rules.edit', $rule) }}"
                                           @class(['font-medium link link-hover', 'opacity-60' => ! $rule->enabled])>{{ $rule->name }}</a>
                                        <div class="text-xs opacity-50">
                                            {{ trans_choice('{0}takes any number of products|{1}at least :count product|[2,*]at least :count products', $rule->min_products, ['count' => $rule->min_products]) }}
                                        </div>
                                    </td>
                                    <td class="text-sm whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1.5">
                                        @switch ($rule->axis())
                                            @case ('place')
                                                <x-icon name="map-pin" size="sm" class="opacity-60" />
                                                {{ trans_choice(':count town|:count towns', count($rule->placeIds()), ['count' => count($rule->placeIds())]) }}
                                                @break
                                            @case ('price')
                                                <x-icon name="banknote" size="sm" class="opacity-60" />
                                                {{ trans_choice(':count price bracket|:count price brackets', count($rule->priceBounds()), ['count' => count($rule->priceBounds())]) }}
                                                @break
                                            @default
                                                <x-icon name="list-filter" size="sm" class="opacity-60" />
                                                {{ $rule->feature?->t('name', $contentLocale) ?? '—' }}
                                        @endswitch
                                        </span>
                                    </td>
                                    <td class="text-sm max-w-xs">
                                        @if ($ruleCategories->isEmpty())
                                            <span class="opacity-70">{{ __('every category') }}</span>
                                        @else
                                            <span class="truncate block" title="{{ $ruleCategories->implode(', ') }}">
                                                {{ $ruleCategories->take(2)->implode(', ') }}@if ($ruleCategories->count() > 2)<span class="opacity-50"> +{{ $ruleCategories->count() - 2 }}</span>@endif
                                            </span>
                                        @endif
                                        @if ($rule->with_children)
                                            <div class="text-xs opacity-50">{{ __('and everything under them') }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end tabular-nums">
                                        @if ($rule->pages_count > 0)
                                            <a href="{{ route('admin.catalog.filter-pages.index', ['rule' => $rule->id]) }}" class="link link-hover" title="{{ __('Show the pages it made') }}">{{ $rule->pages_count }}</a>
                                        @else
                                            <span class="opacity-40">0</span>
                                        @endif
                                    </td>
                                    @include('torgash.catalog::admin.filter-rules._status', ['rule' => $rule])
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <form method="POST" action="{{ route('admin.catalog.filter-rules.generate-one', $rule) }}" class="inline">
                                                @csrf
                                                <button class="btn btn-ghost btn-sm btn-square" title="{{ __('Generate pages') }}" aria-label="{{ __('Generate pages') }}"><x-icon name="wand-sparkles" /></button>
                                            </form>
                                            <a href="{{ route('admin.catalog.filter-rules.edit', $rule) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.catalog.filter-rules.destroy', $rule) }}" class="inline"
                                                  data-confirm="{{ __('Delete this rule? The pages it made stay published.') }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
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
