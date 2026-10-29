@extends('admin.layout')

@section('title', __('Product tabs'))
@section('title_hint', __('Everything under the gallery on a product page, in the order it stands there: the page\'s own sections, your tabs and what modules add.'))
{{-- No states to filter by: the action stands level with the heading. --}}
@section('title_actions')
    <a href="{{ route('admin.catalog.product-tabs.create') }}" class="btn btn-primary">+ {{ __('New tab') }}</a>
@endsection

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php $locale = admin_content_locale(); @endphp

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <p class="text-xs opacity-70 flex items-center gap-1.5">
                <x-icon :name="$asSections ? 'rows-3' : 'panel-top'" size="sm" class="opacity-60 shrink-0" />
                {{ $asSections
                    ? __('The page is laid out as sections now: each is shown under its heading.')
                    : __('The page is laid out as tabs now: only the titles are shown; headings apply when you switch to sections in the catalogue settings.') }}
            </p>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                @if ($product)
                    <input type="hidden" name="product" value="{{ $product->id }}">
                @endif
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by title…') }}">
                </label>
                @if ($filters['q'] !== '' || $product)
                    <a href="{{ route('admin.catalog.product-tabs.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            @if ($product)
                <div class="alert text-sm">
                    <x-icon name="package" size="sm" />
                    <span>
                        {{ __('Only the tabs written for one product:') }}
                        <a href="{{ route('admin.catalog.products.edit', $product) }}" class="link">{{ $product->t('name', $locale) ?: $product->sku }}</a>
                    </span>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-16">{{ __('Position') }}</th>
                                <th>{{ __('Tab') }}</th>
                                <th>{{ __('Kind') }}</th>
                                <th>{{ __('On which products') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                @php $rowTab = $row['tab'] ?? null; @endphp
                                <tr class="hover:bg-base-200/50">
                                    <td class="tabular-nums opacity-60">{{ $row['sort'] }}</td>

                                        @php
                                            // A named section — the page's own or a module's — as against a tab the shop wrote.
                                            $isBuiltIn = $row['kind'] !== 'own';
                                            $known = $row['known'] ?? null;
                                            $title = $rowTab?->t('title', $locale) ?: ($known['label'] ?? __('(untitled)'));
                                            $heading = $rowTab?->t('heading', $locale);
                                            $editUrl = $rowTab
                                                ? route('admin.catalog.product-tabs.edit', $rowTab)
                                                : route('admin.catalog.product-tabs.create', ['section' => $row['section']]);
                                        @endphp
                                        <td>
                                            <a href="{{ $editUrl }}" @class(['font-medium link link-hover', 'opacity-60' => $rowTab && ! $rowTab->enabled])>{{ $title }}</a>
                                            <div class="text-xs opacity-50">
                                                {{ $heading ? __('Heading: :text', ['text' => $heading]) : __('Heading — the title') }}
                                            </div>
                                        </td>
                                        <td>
                                            @if ($row['kind'] === 'module')
                                                <span class="inline-flex items-center gap-1">
                                                    <span class="badge badge-sm badge-ghost whitespace-nowrap">{{ __('Module: :name', ['name' => $known['module']]) }}</span>
                                                    @if ($known['url'])
                                                        <a href="{{ $known['url'] }}" class="opacity-50 hover:opacity-100" title="{{ __('Open the module') }}" aria-label="{{ __('Open the module') }}">
                                                            <x-icon name="arrow-up-right" size="sm" />
                                                        </a>
                                                    @endif
                                                </span>
                                            @elseif ($isBuiltIn)
                                                <span class="badge badge-sm badge-ghost whitespace-nowrap">{{ __('The page\'s own') }}</span>
                                            @else
                                                <span class="badge badge-sm badge-ghost whitespace-nowrap">{{ __('Your tab') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-sm opacity-70">
                                            @if (empty($rowTab?->rules))
                                                {{ ($known['note'] ?? null) ?: __('Every product') }}
                                            @else
                                                {{ trans_choice(':count condition|:count conditions', count($rowTab->rules), ['count' => count($rowTab->rules)]) }}
                                            @endif
                                        </td>
                                        {{-- A named section with no row yet switches too: its first click makes the row. --}}
                                        @include('torgash.catalog::admin.product-tabs._status', ['tab' => $rowTab, 'sectionKey' => $row['section'] ?? null, 'cellId' => null])
                                        <td class="text-end whitespace-nowrap">
                                            <div class="inline-flex items-center gap-1">
                                                <a href="{{ $editUrl }}" class="btn btn-ghost btn-sm btn-square"
                                                   title="{{ $rowTab ? __('Edit') : __('Give it a heading') }}" aria-label="{{ __('Edit') }}">
                                                    <x-icon name="pencil" />
                                                </a>
                                                <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                                @if ($rowTab)
                                                    <form method="POST" action="{{ route('admin.catalog.product-tabs.destroy', $rowTab) }}" class="inline"
                                                          data-confirm="{{ $isBuiltIn ? __('Forget this section\'s own words? It goes back to the engine\'s title, position and being shown.') : __('Delete this tab?') }}">
                                                        @csrf @method('DELETE')
                                                        <button class="btn btn-ghost btn-sm btn-square text-error"
                                                                title="{{ $isBuiltIn ? __('Reset to the default') : __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                            <x-icon :name="$isBuiltIn ? 'rotate-ccw' : 'trash-2'" />
                                                        </button>
                                                    </form>
                                                @else
                                                    <span class="btn btn-ghost btn-sm btn-square btn-disabled"><x-icon name="trash-2" class="opacity-40" /></span>
                                                @endif
                                            </div>
                                        </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center opacity-50 py-6">{{ __('Nothing matches this search.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="text-xs opacity-60">{{ __('Position decides the order: Description is 10 and Characteristics 20, so a tab below 10 goes in front of them.') }}</p>
            @endif
        </div>
    </div>

    @if ($perProduct->isNotEmpty() || $product)
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <h2 class="font-semibold">{{ __('Tabs of single products') }}</h2>
                <p class="text-xs opacity-60 -mt-1">{{ __('Written on a product\'s own form and shown on that product only, among the tabs above by position.') }}</p>
                @if ($perProduct->isEmpty())
                    <p class="text-sm opacity-50 py-2">{{ __('No tabs yet.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th class="w-16">{{ __('Position') }}</th>
                                    <th>{{ __('Tab') }}</th>
                                    <th>{{ __('Product') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($perProduct as $tab)
                                    <tr class="hover:bg-base-200/50">
                                        <td class="tabular-nums opacity-60">{{ $tab->sort }}</td>
                                        <td>
                                            <a href="{{ route('admin.catalog.product-tabs.edit', $tab) }}" @class(['font-medium link link-hover', 'opacity-60' => ! $tab->enabled])>
                                                {{ $tab->t('title', $locale) ?: ($tab->section ? \Torgash\Catalog\Support\ProductSections::label($tab->section) : __('(untitled)')) }}
                                            </a>
                                            @if ($tab->section)
                                                <div class="text-xs opacity-50">{{ __('names the page’s own section') }}</div>
                                            @endif
                                        </td>
                                        <td class="text-sm">
                                            @if ($tab->product)
                                                <a href="{{ route('admin.catalog.products.edit', $tab->product) }}" class="link link-hover">
                                                    {{ $tab->product->t('name', $locale) ?: $tab->product->sku }}
                                                </a>
                                            @endif
                                        </td>
                                        @include('torgash.catalog::admin.product-tabs._status', ['tab' => $tab])
                                        <td class="text-end whitespace-nowrap">
                                            <div class="inline-flex items-center gap-1">
                                                <a href="{{ route('admin.catalog.product-tabs.edit', $tab) }}" class="btn btn-ghost btn-sm btn-square"
                                                   title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                    <x-icon name="pencil" />
                                                </a>
                                                <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                                <form method="POST" action="{{ route('admin.catalog.product-tabs.destroy', $tab) }}" class="inline"
                                                      data-confirm="{{ __('Delete this tab?') }}">
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
    @endif
@endsection
