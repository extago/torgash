@extends('admin.layout')

@section('title', __('Stock journal'))

@section('content')
    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <p class="text-sm opacity-70 max-w-3xl">
                {{ __('Every change of stock and why: orders and returns, documents, imports and 1C, a number typed by hand. A mistake is corrected by the next movement, never by editing this one.') }}
            </p>

            @if ($product)
                <div class="alert text-sm">
                    <x-icon name="package" />
                    <span class="flex-1">{{ __('Only :product', ['product' => $product->sku.' — '.$product->t('name')]) }}</span>
                    <a href="{{ route('admin.inventory.moves', array_filter(array_diff_key($filters, ['product' => 1]))) }}" class="btn btn-ghost btn-sm">{{ __('All products') }}</a>
                </div>
            @endif

            <form method="GET" class="flex flex-wrap items-end gap-2">
                @if ($filters['product'])
                    <input type="hidden" name="product" value="{{ $filters['product'] }}">
                @endif
                <label class="input input-sm w-full sm:w-64 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('SKU or name…') }}">
                </label>
                <select name="warehouse" class="select select-sm select-bordered" aria-label="{{ __('Warehouse') }}">
                    <option value="">{{ __('Every warehouse') }}</option>
                    @foreach ($warehouses as $id => $row)
                        <option value="{{ $id }}" @selected((string) $filters['warehouse'] === (string) $id)>{{ pick_translation($row->name) }}</option>
                    @endforeach
                    <option value="none" @selected($filters['warehouse'] === 'none')>{{ __('Not kept per warehouse') }}</option>
                </select>
                <select name="type" class="select select-sm select-bordered" aria-label="{{ __('Cause') }}">
                    <option value="">{{ __('Any cause') }}</option>
                    @foreach ($types as $key => $label)
                        <option value="{{ $key }}" @selected($filters['type'] === $key)>{{ __($label) }}</option>
                    @endforeach
                </select>
                <input type="date" name="from" value="{{ $filters['from'] }}" class="input input-sm input-bordered" aria-label="{{ __('From') }}">
                <input type="date" name="to" value="{{ $filters['to'] }}" class="input input-sm input-bordered" aria-label="{{ __('To') }}">
                <button class="btn btn-sm">{{ __('Show') }}</button>
                @if (array_filter($filters))
                    <a href="{{ route('admin.inventory.moves') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            @if ($moves->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="clipboard-list" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ __('No movements yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('Product') }}</th>
                                <th>{{ __('Warehouse') }}</th>
                                <th>{{ __('Cause') }}</th>
                                <th class="text-end">{{ __('Quantity change') }}</th>
                                <th class="text-end">{{ __('After') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($moves as $move)
                                @php
                                    $moveProduct = $products->get($move->product_id);
                                    $moveWarehouse = $move->warehouse_id ? $warehouses->get($move->warehouse_id) : null;
                                @endphp
                                <tr>
                                    <td class="opacity-60 whitespace-nowrap">{{ $move->created_at?->format('d.m.Y H:i') }}</td>
                                    <td>
                                        @if ($moveProduct)
                                            <a href="{{ route('admin.inventory.moves', ['product' => $move->product_id]) }}" class="link link-hover">{{ $moveProduct->t('name') }}</a>
                                        @endif
                                        <div class="text-xs opacity-60 font-mono">{{ $move->sku }}</div>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        @if ($move->warehouse_id)
                                            {{ $moveWarehouse ? pick_translation($moveWarehouse->name) : '#'.$move->warehouse_id }}
                                        @else
                                            <span class="opacity-50">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{ \Torgash\Inventory\Models\Move::typeLabel($move->type) }}
                                        @if ($move->order_id)
                                            · <a href="{{ url('/admin/orders/'.($orders[$move->order_id] ?? '')) }}" class="link font-mono">{{ $orders[$move->order_id] ?? '#'.$move->order_id }}</a>
                                        @endif
                                        @if ($move->user_name)
                                            <div class="text-xs opacity-60">{{ $move->user_name }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end tabular-nums {{ $move->delta > 0 ? 'text-success' : 'text-error' }}">{{ $move->delta > 0 ? '+' : '−' }}{{ abs($move->delta) }}</td>
                                    <td class="text-end tabular-nums opacity-70">{{ $move->after ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $moves->links() }}
            @endif
        </div>
    </div>
@endsection
