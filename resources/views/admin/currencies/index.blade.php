@extends('admin.layout')

@section('title', __('Currencies'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('The currencies a customer can see prices in. Every amount is stored in the shop\'s own currency, whose rate is 1; each other rate says how much of that currency one unit of the shop\'s buys.') }}
                    @can('settings.manage')<a href="{{ url('/admin/settings#setting-rates_source') }}" class="link">{{ __('Where rates come from') }}</a>@endcan
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    {{-- Only when there is somewhere to fetch from: a button that
                         can only ever answer "configure a source first" is a
                         button that should not be drawn. --}}
                    @if ($source)
                        <form method="POST" action="{{ route('admin.currencies.refresh') }}">
                            @csrf
                            <button class="btn btn-sm gap-1.5">
                                <x-icon name="refresh-cw" size="sm" />
                                {{ __('Fetch the rates now') }}
                            </button>
                        </form>
                    @endif
                    <a href="{{ route('admin.currencies.create') }}" class="btn btn-primary btn-sm">+ {{ __('New currency') }}</a>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Currency') }}</th>
                            <th>{{ __('Exchange rate') }}</th>
                            <th>{{ __('Written as') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($currencies as $currency)
                            @php
                                $isBooks = isset($inUse[$currency->code]);
                                $isStale = isset($stale[$currency->code]);
                            @endphp
                            <tr class="hover:bg-base-200/50">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="font-mono font-semibold w-12">{{ $currency->code }}</span>
                                        <div class="min-w-0">
                                            <a href="{{ route('admin.currencies.edit', $currency) }}" class="link link-hover">{{ $currency->name }}</a>
                                            @if ($isBooks)
                                                <span class="badge badge-sm badge-primary ml-1" title="{{ __('Every amount is stored in it; its rate is 1.') }}">{{ __('Shop currency') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="tabular-nums">{{ format_rate($currency->rate) }}</span>
                                    {{-- When it last moved, and whether it moves at all. A rate
                                         with no date beside it is a number nobody can judge —
                                         and a feed that quietly stopped looks exactly like one
                                         that is working, which is why a stale one is red. --}}
                                    <div @class(['text-xs', 'text-error' => $isStale, 'opacity-50' => ! $isStale])>
                                        @if ($isBooks)
                                            {{ __('always 1') }}
                                        @elseif (! $currency->auto_rate)
                                            {{ __('by hand') }}
                                        @elseif ($currency->rate_updated_at)
                                            <span title="{{ $currency->rate_updated_at->format('Y-m-d H:i') }}">{{ __('updated :when', ['when' => $currency->rate_updated_at->diffForHumans()]) }}</span>
                                        @elseif ($source)
                                            {{ __('never fetched') }}
                                        @endif
                                    </div>
                                </td>
                                <td class="font-mono text-sm">{{ $currency->format(1299.5) }}</td>
                                @include('admin.currencies._status', ['currency' => $currency, 'inUse' => $inUse])
                                <td class="text-end whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1">
                                        <a href="{{ route('admin.currencies.edit', $currency) }}" class="btn btn-ghost btn-sm btn-square"
                                           title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                        <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                        @if ($isBooks)
                                            <span class="btn btn-ghost btn-sm btn-square btn-disabled" title="{{ __('A storefront keeps its books in :code — it cannot be deleted.', ['code' => $currency->code]) }}"><x-icon name="trash-2" /></span>
                                        @else
                                            <form method="POST" action="{{ route('admin.currencies.destroy', $currency) }}" class="inline"
                                                  data-confirm="{{ __('Delete :code? Products priced in it keep the price they last had in the shop\'s currency and stop following its rate.', ['code' => $currency->code]) }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
