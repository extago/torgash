@extends('admin.layout')

@section('title', __('Coupons'))
@section('title_hint', __('A code the customer types in the basket for a percent or an amount off the order. For discounts that apply by themselves — to a category, from a sum, in a window of time — use promotions.'))

@section('content')
    @php
        $stateTabs = [
            '' => __('All'),
            'active' => __('works'),
            'scheduled' => __('not started'),
            'expired' => __('expired'),
            'used_up' => __('used up'),
            'off' => __('off'),
        ];
        $isFiltered = $filters['q'] !== '' || $filters['state'] !== '';
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- The states as one row of tabs over the card, the screen's actions at
         its end (app.css, `admin-tabs`); LINKS carrying the search. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Coupons') }}">
            @foreach ($stateTabs as $stateKey => $stateLabel)
                <a href="{{ request()->fullUrlWithQuery(['state' => $stateKey ?: null, 'page' => null]) }}"
                   @class(['admin-tab', 'is-active' => $filters['state'] === $stateKey])>
                    {{ \Illuminate\Support\Str::ucfirst($stateLabel) }} <span class="admin-tab-count">{{ $stateCounts[$stateKey] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            {{-- A neighbour, not a dependency: promotions is its own module. --}}
            @if (\Illuminate\Support\Facades\Route::has('admin.promotions.index'))
                <a href="{{ route('admin.promotions.index') }}" class="btn btn-ghost gap-1.5"><x-icon name="percent" size="sm" /> {{ __('Promotions') }}</a>
            @endif
            <a href="{{ route('admin.checkout.coupons.create') }}" class="btn btn-primary">+ {{ __('New coupon') }}</a>
        </div>
    </div>

    {{-- One Alpine root: the bulk bar and every row's checkbox share
         `selected` — the categories list's own arrangement. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [], copied: '' }">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by code…') }}">
                </label>
                <input type="hidden" name="state" value="{{ $filters['state'] }}">
                @if ($isFiltered)
                    <a href="{{ route('admin.checkout.coupons.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick coupons to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.checkout.coupons.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected coupons? Orders that used them keep their discount.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($coupons->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="ticket-percent" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $isFiltered ? __('No coupons match this search.') : __('No coupons yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($coupons->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $coupons->count() }}">
                                </th>
                                <th>{{ __('Code') }}</th>
                                <th>{{ __('Discount') }}</th>
                                <th>{{ __('Minimum order') }}</th>
                                <th>{{ __('Dates') }}</th>
                                <th class="text-end">{{ __('Used') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($coupons as $coupon)
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $coupon->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $coupon->id }}" x-model="selected" aria-label="{{ $coupon->code }}">
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <div class="flex items-center gap-1">
                                            <a href="{{ route('admin.checkout.coupons.edit', $coupon) }}"
                                               @class(['font-mono font-semibold link link-hover', 'opacity-60' => $coupon->state() !== 'active'])>{{ $coupon->code }}</a>
                                            {{-- The code is what gets pasted into a newsletter;
                                                 one click puts it on the clipboard. --}}
                                            <button type="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('Copy the code') }}" aria-label="{{ __('Copy the code') }}"
                                                    @click="navigator.clipboard?.writeText(@js($coupon->code)); copied = @js($coupon->code); setTimeout(() => copied = '', 1500)">
                                                <x-icon name="copy" size="sm" x-show="copied !== {{ \Illuminate\Support\Js::from($coupon->code) }}" />
                                                <x-icon name="check" size="sm" class="text-success" x-show="copied === {{ \Illuminate\Support\Js::from($coupon->code) }}" x-cloak />
                                            </button>
                                        </div>
                                    </td>
                                    <td class="font-medium whitespace-nowrap">
                                        {{ $coupon->type === 'percent' ? '−'.$coupon->value.'%' : '−'.admin_price($coupon->value) }}
                                    </td>
                                    <td class="whitespace-nowrap">{{ $coupon->min_subtotal ? admin_price($coupon->min_subtotal) : '—' }}</td>
                                    <td class="text-sm whitespace-nowrap">
                                        @if ($coupon->starts_at || $coupon->expires_at)
                                            {{ $coupon->starts_at?->format('d.m.Y') ?? '…' }} — {{ $coupon->expires_at?->format('d.m.Y') ?? '…' }}
                                        @else
                                            <span class="opacity-50">{{ __('no limit') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end tabular-nums whitespace-nowrap">
                                        @php $couponOrders = route('admin.orders.index', ['coupon' => $coupon->code]); @endphp
                                        <a href="{{ $couponOrders }}" @class(['link link-hover', 'text-warning font-medium' => $coupon->state() === 'used_up'])
                                           title="{{ __('Orders with this coupon') }}">{{ $coupon->used_count }}{{ $coupon->usage_limit ? ' / '.$coupon->usage_limit : '' }}</a>
                                    </td>
                                    @include('torgash.checkout::admin.coupons._status', ['coupon' => $coupon])
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.checkout.coupons.edit', $coupon) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.checkout.coupons.destroy', $coupon) }}" class="inline"
                                                  data-confirm="{{ __('Delete this coupon? Orders that used it keep their discount.') }}">
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
