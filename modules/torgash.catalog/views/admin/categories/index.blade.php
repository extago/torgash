@extends('admin.layout')

@section('title', __('Categories'))
@section('title_hint', __('The catalogue\'s tree. A category is its subcategories too: its page shows the products of the whole branch, and every rule that names it takes the branch.'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php $locale = admin_content_locale(); @endphp

    {{-- The states as one row of tabs over the card, the screen's actions at
         its end (app.css, `admin-tabs`); LINKS carrying the search. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Categories') }}">
            @foreach (['' => __('All')] + \Torgash\Catalog\Models\Category::statusLabels() as $statusKey => $statusLabel)
                <a href="{{ route('admin.catalog.categories.index', array_filter(['status' => $statusKey, 'q' => $filters['q']], fn ($v) => $v !== null && $v !== '')) }}"
                   @class(['admin-tab', 'is-active' => (string) $filters['status'] === (string) $statusKey])>
                    {{ $statusLabel }} <span class="admin-tab-count">{{ $statusCounts[$statusKey] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            @if ($trashedCount)
                <a href="{{ route('admin.catalog.categories.trash') }}" class="btn btn-ghost gap-1.5">
                    <x-icon name="trash-2" /> {{ __('Trash') }} <span class="badge badge-sm">{{ $trashedCount }}</span>
                </a>
            @endif
            <a href="{{ route('admin.catalog.categories.create') }}" class="btn btn-primary">+ {{ __('New category') }}</a>
        </div>
    </div>

    {{-- One Alpine root: the bulk bar and every row's checkbox share
         `selected` — the product list's own arrangement. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                @if ($filters['status'])
                    <input type="hidden" name="status" value="{{ $filters['status'] }}">
                @endif
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by name or address…') }}">
                </label>
                @if ($filters['q'] !== '' || $filters['status'])
                    <a href="{{ route('admin.catalog.categories.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick categories to change the status of several at once, or move them to the trash.') }}
                </p>
                <form method="POST" action="{{ route('admin.catalog.categories.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    <div class="join">
                        @foreach (\Torgash\Catalog\Models\Category::statusLabels() as $statusValue => $statusText)
                            <button name="action" value="{{ $statusValue }}" class="btn btn-sm join-item">{{ $statusText }}</button>
                        @endforeach
                    </div>
                    <button name="action" value="trash" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Move the selected categories to the trash?') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Move to trash') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($categories->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="folder-tree" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['status'] ? __('No categories match this search.') : __('No categories yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($categories->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $categories->count() }}">
                                </th>
                                <th>{{ __('Name') }}</th>
                                <th class="text-end">{{ __('Products') }}</th>
                                <th class="text-end">{{ __('Sort order') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($categories as $category)
                                @php
                                    $depth = $flat ? 0 : $tree->depth($category->id);
                                    $categoryIcon = $category->iconImageUrl() ? null : $category->lucideIcon();
                                @endphp
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $category->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $category->id }}" x-model="selected"
                                               aria-label="{{ $category->t('name', $locale) }}">
                                    </td>
                                    <td>
                                        {{-- The tree drawn by indent and a rail; searching shows
                                             the path instead, since a match's parent may not match. --}}
                                        <div class="flex items-center gap-2.5" style="padding-left: {{ $depth * 1.5 }}rem">
                                            @if ($depth > 0)
                                                <x-icon name="corner-down-right" size="sm" class="opacity-30 shrink-0" />
                                            @endif
                                            <span class="w-8 h-8 rounded-md bg-base-200 flex items-center justify-center shrink-0 overflow-hidden">
                                                @if ($categoryImage = $category->iconImageUrl())
                                                    <img src="{{ $categoryImage }}" alt="" class="w-6 h-6 object-contain">
                                                @elseif ($categoryIcon)
                                                    <x-icon :name="$categoryIcon" size="sm" class="opacity-70" />
                                                @else
                                                    <x-icon name="folder" size="sm" class="opacity-30" />
                                                @endif
                                            </span>
                                            <div class="min-w-0">
                                                <a id="category-name-{{ $category->id }}" href="{{ route('admin.catalog.categories.edit', $category) }}" @class(['font-medium link link-hover', 'opacity-60' => ! $category->isListed()])>
                                                    {{ $category->t('name', $locale) ?: '—' }}
                                                </a>
                                                <div class="text-xs opacity-50 font-mono truncate">
                                                    @if ($flat && $category->parent_id)
                                                        <span class="font-sans">{{ collect(array_reverse($tree->ancestors($category->id)))->map(fn ($ancestorId) => $tree->find($ancestorId)?->t('name', $locale))->filter()->implode(' / ') }} ·</span>
                                                    @endif
                                                    /catalog/{{ $category->slug }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end tabular-nums opacity-70">{{ $productCounts[$category->id] ?? 0 }}</td>
                                    <td class="text-end tabular-nums opacity-70">{{ $category->sort }}</td>
                                    @include('torgash.catalog::admin.categories._status', ['category' => $category])
                                    <td class="text-end whitespace-nowrap">
                                        {{-- Preview, edit, and - set apart - the trash, so the
                                             harmless click and the destructive one never sit
                                             side by side. --}}
                                        <div class="inline-flex items-center gap-1">
                                            @if ($category->isReachable())
                                                <a href="{{ route('catalog.category', $category->slug) }}" target="_blank" rel="noopener"
                                                   class="btn btn-ghost btn-sm btn-square" title="{{ __('Preview') }}" aria-label="{{ __('Preview') }}">
                                                    <x-icon name="external-link" />
                                                </a>
                                            @else
                                                <span class="btn btn-ghost btn-sm btn-square btn-disabled" title="{{ __('A disabled category has no page on the storefront.') }}">
                                                    <x-icon name="external-link" class="opacity-40" />
                                                </span>
                                            @endif
                                            <a href="{{ route('admin.catalog.categories.edit', $category) }}"
                                               class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.catalog.categories.destroy', $category) }}" class="inline"
                                                  data-confirm="{{ __('Move this category to the trash?') }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Move to trash') }}" aria-label="{{ __('Move to trash') }}">
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
                <p class="text-xs opacity-50">{{ __('Products: those filed in the category itself; its page also shows the subcategories\' ones.') }}</p>
            @endif
        </div>
    </div>
@endsection
