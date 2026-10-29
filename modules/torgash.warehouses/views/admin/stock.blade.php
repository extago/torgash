@extends('admin.layout')

@section('title', __('Stock').': '.$warehouse->t('name', admin_content_locale()))
@section('own_heading', '1')

@section('content')
    @php $locale = admin_content_locale(); @endphp

    <x-admin.form-bar>
        <a href="{{ route('admin.warehouses.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to warehouses') }}" aria-label="{{ __('Back to warehouses') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none">{{ __('Stock') }}</div>
            <div class="font-semibold truncate leading-tight">{{ $warehouse->t('name', $locale) }}</div>
        </div>
        <a href="{{ route('admin.warehouses.edit', $warehouse) }}" class="btn btn-ghost btn-sm">{{ __('Warehouse settings') }}</a>
        <x-slot:actions>
            @if ($products->isNotEmpty())
                <button form="stock-form" class="btn btn-primary btn-sm">{{ __('Save quantities') }}</button>
            @endif
        </x-slot:actions>
    </x-admin.form-bar>

    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif

    {{-- This shelf or every product, as one row of tabs over the card
         (app.css, `admin-tabs`); LINKS carrying the search. --}}
    <nav class="admin-tabs" aria-label="{{ __('Stock') }}">
        <a href="{{ request()->fullUrlWithQuery(['here' => 1, 'page' => null]) }}" @class(['admin-tab', 'is-active' => $filters['here']])>
            {{ __('At this warehouse') }} <span class="admin-tab-count">{{ $hereCount }}</span>
        </a>
        <a href="{{ request()->fullUrlWithQuery(['here' => 0, 'page' => null]) }}" @class(['admin-tab', 'is-active' => ! $filters['here']])>
            {{ __('All products') }}
        </a>
    </nav>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <p class="text-sm opacity-70 max-w-3xl">
                {{ __('What is on this shelf right now. Only the boxes you change are saved. A product whose stock is not split by warehouse yet shows an empty box: type a number and it starts counting per warehouse — what it had so far goes to the default warehouse, so nothing disappears.') }}
            </p>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="here" value="{{ $filters['here'] ? 1 : 0 }}">
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by SKU or name…') }}">
                </label>
                @if ($filters['q'] !== '')
                    <a href="{{ route('admin.warehouses.stock', [$warehouse, 'here' => $filters['here'] ? 1 : null]) }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            @if ($products->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="package" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['here'] && $filters['q'] === '' ? __('Nothing is counted at this warehouse yet. Open "All products" to put some here.') : __('No products found.') }}</p>
                </div>
            @else
                <form method="POST" id="stock-form" action="{{ route('admin.warehouses.stock.update', $warehouse) }}">
                    @csrf
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th colspan="2">{{ __('Product') }}</th>
                                    <th class="w-40">{{ __('Here') }}</th>
                                    <th class="text-end">{{ __('Other warehouses') }}</th>
                                    <th class="text-end">{{ __('Total stock') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($products as $product)
                                    @php
                                        $isSplit = isset($split[$product->id]);
                                        // Empty for a product not split yet: a 0 in that box
                                        // would read as "there are none", which is not what
                                        // the product form says.
                                        $hereQty = $isSplit ? (string) ($stock[$product->id] ?? 0) : '';
                                    @endphp
                                    <tr class="hover:bg-base-200/50">
                                        <td class="w-14">
                                            @if ($image = $product->primaryImageUrl(120))
                                                <div class="h-10 w-10 rounded bg-base-200 overflow-hidden">
                                                    <img src="{{ $image }}" alt="" class="h-full w-full object-contain" loading="lazy">
                                                </div>
                                            @else
                                                <div class="h-10 w-10 rounded bg-base-200 flex items-center justify-center"><x-icon name="image" class="opacity-30" /></div>
                                            @endif
                                        </td>
                                        <td class="min-w-0">
                                            <a href="{{ route('admin.catalog.products.edit', $product) }}" class="link link-hover font-medium">{{ $product->t('name', $locale) ?: '—' }}</a>
                                            <div class="font-mono text-xs opacity-50">{{ $product->sku }}</div>
                                        </td>
                                        <td>
                                            <input type="hidden" name="was[{{ $product->id }}]" value="{{ $hereQty }}">
                                            <input type="number" step="1" name="qty[{{ $product->id }}]" value="{{ $hereQty }}"
                                                   @unless ($isSplit) placeholder="—" title="{{ __('Not split by warehouse yet') }}" @endunless
                                                   class="input input-bordered w-full tabular-nums">
                                        </td>
                                        <td class="text-end tabular-nums">
                                            @if ($isSplit)
                                                <span @class(['opacity-40' => ! ($elsewhere[$product->id] ?? 0)])>{{ (int) ($elsewhere[$product->id] ?? 0) }}</span>
                                            @else
                                                <span class="text-xs opacity-50">{{ __('not split') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end tabular-nums font-semibold">{{ $product->stock }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </form>

                {{ $products->links() }}
            @endif
        </div>
    </div>
@endsection
