@extends('admin.layout')

@section('title', __('Filter pages'))

@section('content')
    @php
        $isFiltered = $filters['q'] !== '' || $filters['category'] || $filters['rule'] || $filters['tab'] !== '';
        $contentLocale = admin_content_locale();
        $tabs = ['' => __('All'), 'published' => __('Published'), 'off' => __('Unpublished'), 'empty' => __('No products')];
    @endphp

    @include('torgash.catalog::admin.filter-pages._nav', ['filterScreen' => 'pages'])

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
                    {{ __('A category plus a set of characteristic values — or a town — at an address of its own. The same combination reached through the sidebar points its canonical here, so the shop has one indexable page instead of an unbounded number of query strings.') }}
                </p>
                <a href="{{ route('admin.catalog.filter-pages.create') }}" class="btn btn-primary btn-sm">+ {{ __('New filter page') }}</a>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by name or address…') }}">
                </label>
                <select name="category" class="select select-bordered select-sm w-full sm:w-56" data-submit-on-change>
                    <option value="">{{ __('All categories') }}</option>
                    @foreach ($categories as $catId => $catLabel)
                        <option value="{{ $catId }}" @selected($filters['category'] === (int) $catId)>{{ $catLabel }}</option>
                    @endforeach
                </select>
                @if ($rules->isNotEmpty())
                    <select name="rule" class="select select-bordered select-sm w-full sm:w-52" data-submit-on-change>
                        <option value="">{{ __('Any source') }}</option>
                        @foreach ($rules as $ruleId => $ruleName)
                            <option value="{{ $ruleId }}" @selected($filters['rule'] === (int) $ruleId)>{{ $ruleName }}</option>
                        @endforeach
                    </select>
                @endif
                <input type="hidden" name="tab" value="{{ $filters['tab'] }}">
                @if ($isFiltered)
                    <a href="{{ route('admin.catalog.filter-pages.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="join flex-wrap -mt-1">
                @foreach ($tabs as $tabKey => $tabLabel)
                    <a href="{{ request()->fullUrlWithQuery(['tab' => $tabKey ?: null, 'page' => null]) }}"
                       @class(['btn btn-sm join-item gap-1.5', 'btn-active' => $filters['tab'] === $tabKey])>
                        {{ $tabLabel }}
                        @if ($tabKey === 'empty' && $counts['empty'] > 0)
                            <span class="badge badge-xs badge-warning">{{ $counts['empty'] }}</span>
                        @else
                            <span class="opacity-50">{{ $counts[$tabKey] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick pages to publish or unpublish several at once, or delete them — a deleted page leaves a redirect to its category.') }}
                </p>
                <form method="POST" action="{{ route('admin.catalog.filter-pages.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    <div class="join">
                        <button name="action" value="enable" class="btn btn-sm join-item">{{ __('Publish') }}</button>
                        <button name="action" value="disable" class="btn btn-sm join-item">{{ __('Unpublish') }}</button>
                    </div>
                    <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Delete the selected pages? Their addresses will redirect to their categories.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($pages->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="filter" size="xl" class="opacity-40" />
                    <p class="mt-2 max-w-xl mx-auto">{{ $isFiltered ? __('No filter pages match this search.') : __('No filter pages yet. Write one by hand, or set up a rule and let it make them.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($pages->getCollection()->pluck('id')->map(fn ($id) => (string) $id)->values()) : []"
                                           :checked="selected.length === {{ $pages->count() }}">
                                </th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Address') }}</th>
                                <th class="text-end">{{ __('Products') }}</th>
                                <th>{{ __('Source') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pages as $page)
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $page->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $page->id }}" x-model="selected" aria-label="{{ $page->title($contentLocale) }}">
                                    </td>
                                    <td class="min-w-0">
                                        <a href="{{ route('admin.catalog.filter-pages.edit', $page) }}"
                                           @class(['font-medium link link-hover', 'opacity-60' => ! $page->enabled])>{{ $page->title($contentLocale) }}</a>
                                        <div class="text-xs opacity-60 flex items-center gap-1 flex-wrap">
                                            <x-icon name="folder" size="sm" /> {{ $page->category?->t('name', $contentLocale) }}
                                            {{-- The town is named with its district and region, which is
                                                 what tells two namesakes apart — the storefront prints the
                                                 bare name, the admin has to be able to pick the right one. --}}
                                            @if ($page->place_id)
                                                <span class="opacity-50">·</span>
                                                <x-icon name="map-pin" size="sm" /> {{ $placeNames[$page->place_id] ?? __('town #:id', ['id' => $page->place_id]) }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="font-mono text-xs opacity-70 max-w-72 truncate" title="{{ $page->path() }}">{{ $page->path() }}</td>
                                    <td class="text-end tabular-nums">
                                        @if ($page->products_count === 0)
                                            <span class="badge badge-sm badge-warning" title="{{ __('No products — the page answers noindex') }}">0</span>
                                        @else
                                            {{ $page->products_count }}
                                        @endif
                                    </td>
                                    <td class="text-sm whitespace-nowrap">
                                        @if ($page->rule)
                                            <a href="{{ route('admin.catalog.filter-rules.edit', $page->rule) }}" class="link link-hover inline-flex items-center gap-1">
                                                <x-icon name="wand-sparkles" size="sm" class="opacity-60" /> {{ $page->rule->name }}
                                            </a>
                                        @else
                                            <span class="opacity-60">{{ __('By hand') }}</span>
                                        @endif
                                    </td>
                                    @include('torgash.catalog::admin.filter-pages._status', ['page' => $page])
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            {{-- What is not the default, as small marks beside the actions. --}}
                                            @if ($page->noindex)
                                                <span class="badge badge-sm badge-ghost">noindex</span>
                                            @elseif (! $page->in_sitemap)
                                                <span class="badge badge-sm badge-ghost" title="{{ __('not in sitemap') }}"><x-icon name="map" size="sm" class="opacity-60" /></span>
                                            @endif
                                            @if (! $page->in_list)
                                                <span class="badge badge-sm badge-ghost" title="{{ __('not linked') }}"><x-icon name="unlink" size="sm" class="opacity-60" /></span>
                                            @endif
                                            @if ($page->category)
                                                <a href="{{ $page->url() }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm btn-square"
                                                   title="{{ __('Open on the storefront') }}" aria-label="{{ __('Open on the storefront') }}"><x-icon name="external-link" /></a>
                                            @endif
                                            <a href="{{ route('admin.catalog.filter-pages.edit', $page) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.catalog.filter-pages.destroy', $page) }}" class="inline"
                                                  data-confirm="{{ __('Delete this page? Its address will redirect to the category.') }}">
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

                {{ $pages->links() }}
            @endif
        </div>
    </div>
@endsection
