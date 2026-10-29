@extends('admin.layout')

@section('title', __('Payment methods'))
@section('title_hint', __('What the customer picks at checkout: cash on delivery, a bank transfer, a card. A method with a gateway takes the money online; one without is settled by hand from the order screen.'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- The states as one row of tabs over the card, the screen's actions at
         its end (app.css, `admin-tabs`); LINKS carrying the search. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Payment methods') }}">
            @foreach (['' => __('All'), 'enabled' => __('Enabled'), 'disabled' => __('Disabled')] as $statusKey => $statusLabel)
                <a href="{{ route('admin.checkout.payment-methods.index', array_filter(['status' => $statusKey, 'q' => $filters['q']], fn ($v) => $v !== null && $v !== '')) }}"
                   @class(['admin-tab', 'is-active' => (string) $filters['status'] === (string) $statusKey])>
                    {{ $statusLabel }} <span class="admin-tab-count">{{ $statusCounts[$statusKey] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            {{-- Whoever takes the money puts its own pages here rather
                 than in the sidebar: "payments" is one place in a
                 merchant's head, and the menu has no nesting to say so. --}}
            {!! hook_filter('checkout.payment_methods.actions', '') !!}
            <a href="{{ route('admin.checkout.payment-methods.create') }}" class="btn btn-primary">+ {{ __('New method') }}</a>
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
                    <a href="{{ route('admin.checkout.payment-methods.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick methods to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.checkout.payment-methods.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected payment methods? Methods that orders were paid by are kept.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($methods->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="wallet" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['status'] ? __('No payment methods match this search.') : __('No payment methods yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($methods->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $methods->count() }}">
                                </th>
                                <th class="w-16">{{ __('Position') }}</th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Currencies') }}</th>
                                <th class="text-end">{{ __('Orders') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($methods as $method)
                                @php $inUse = (int) ($orderCounts[$method->id] ?? 0); @endphp
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $method->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $method->id }}" x-model="selected"
                                               aria-label="{{ $method->t('name') }}">
                                    </td>
                                    <td class="tabular-nums opacity-60">{{ $method->position }}</td>
                                    <td>
                                        <div class="flex items-center gap-2.5">
                                            <span class="h-8 w-12 shrink-0 flex items-center justify-center rounded bg-base-200">
                                                @if ($method->logoUrl())
                                                    <img src="{{ $method->logoUrl() }}" alt="" class="max-h-6 max-w-10 object-contain">
                                                @else
                                                    <x-icon name="wallet" size="sm" class="opacity-40" />
                                                @endif
                                            </span>
                                            <div class="min-w-0">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <a href="{{ route('admin.checkout.payment-methods.edit', $method) }}"
                                                       @class(['font-medium link link-hover', 'opacity-60' => ! $method->enabled])>{{ $method->t('name') }}</a>
                                                    @if ($oneClickId === $method->id)
                                                        <span class="badge badge-sm badge-primary whitespace-nowrap">{{ __('one click') }}</span>
                                                    @endif
                                                </div>
                                                {{-- Who takes the money: a module's answer through
                                                     checkout.payment_methods.notes, else "by hand". --}}
                                                <div class="flex items-center gap-2 flex-wrap mt-0.5">
                                                    @if (isset($notes[$method->id]))
                                                        {!! $notes[$method->id] !!}
                                                    @else
                                                        <span class="text-xs opacity-50">{{ __('Settled by hand') }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    {{-- Visible from the list because it decides where the
                                         method even appears: a shop wondering why nobody can
                                         pay in dollars should not have to open every row. --}}
                                    <td class="text-sm">
                                        @if ($method->currencies)
                                            <span class="font-mono">{{ implode(', ', $method->currencies) }}</span>
                                            <div class="text-xs opacity-50">
                                                {{ $method->charge_currency
                                                    ? __('others charged in :currency', ['currency' => $method->charge_currency])
                                                    : __('hidden for other currencies') }}
                                            </div>
                                        @else
                                            <span class="opacity-50">{{ __('any') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end tabular-nums">
                                        @if ($inUse)
                                            {{ $inUse }}
                                        @else
                                            <span class="opacity-40">0</span>
                                        @endif
                                    </td>
                                    @include('torgash.checkout::admin.payment_methods._status', ['method' => $method])
                                    <td class="text-end whitespace-nowrap">
                                        {{-- Edit, and — set apart — delete. A method has no
                                             page of its own, so there is no preview. --}}
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.checkout.payment-methods.edit', $method) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            @if ($inUse)
                                                <span class="btn btn-ghost btn-sm btn-square btn-disabled"
                                                      title="{{ __('Orders were paid by this method, so it cannot be deleted. Switch it off instead.') }}">
                                                    <x-icon name="trash-2" class="opacity-40" />
                                                </span>
                                            @else
                                                <form method="POST" action="{{ route('admin.checkout.payment-methods.destroy', $method) }}" class="inline"
                                                      data-confirm="{{ __('Delete this payment method?') }}">
                                                    @csrf @method('DELETE')
                                                    <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                        <x-icon name="trash-2" />
                                                    </button>
                                                </form>
                                            @endif
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
