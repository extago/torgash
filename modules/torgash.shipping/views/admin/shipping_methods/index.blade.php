@extends('admin.layout')

@section('title', __('Shipping methods'))
@section('title_hint', __('What the customer picks at checkout: a courier, pickup, a carrier. A method costs what its rate in the matching zone says, and is offered only where it has one.'))

@section('content')
    @php
        use Torgash\Shipping\Conditions\Conditions;
        use Torgash\Shipping\Models\ShippingMethod;

        $locale = admin_content_locale();

        // Resolved once for the page, not per condition: the same pickers the
        // method form uses, so a rule shows "Furniture, Lighting" rather than
        // "2 selected". Keyed by kind because a category id and a group id can
        // both be 1. Data comes from whoever owns it, via a hook.
        $conditionNames = [
            'category' => (array) hook_filter('shipping.condition.categories', []),
            'customer_group' => \App\Models\CustomerGroup::orderBy('id')->get()
                ->mapWithKeys(fn ($group) => [$group->id => $group->t('name', $locale) ?? '#'.$group->id])
                ->all(),
        ];
    @endphp

    @include('torgash.shipping::admin._nav', ['shippingScreen' => 'methods'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- The states as one row of tabs over the card, the screen's action at
         its end (app.css, `admin-tabs`); LINKS carrying the search. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Shipping methods') }}">
            @foreach (['' => __('All'), 'enabled' => __('Enabled'), 'disabled' => __('Disabled')] as $statusKey => $statusLabel)
                <a href="{{ route('admin.shipping.methods.index', array_filter(['status' => $statusKey, 'q' => $filters['q']], fn ($v) => $v !== null && $v !== '')) }}"
                   @class(['admin-tab', 'is-active' => (string) $filters['status'] === (string) $statusKey])>
                    {{ $statusLabel }} <span class="admin-tab-count">{{ $statusCounts[$statusKey] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.shipping.methods.create') }}" class="btn btn-primary">+ {{ __('New method') }}</a>
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
                    <a href="{{ route('admin.shipping.methods.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick methods to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.shipping.methods.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected shipping methods with their rates? Placed orders keep the method\'s name.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($methods->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="truck" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['status'] ? __('No shipping methods match this search.') : __('No shipping methods yet.') }}</p>
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
                                <th>{{ __('Priced') }}</th>
                                <th>{{ __('Offered in') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($methods as $method)
                                <tr class="hover:bg-base-200/50 align-top" :class="selected.includes('{{ $method->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $method->id }}" x-model="selected"
                                               aria-label="{{ $method->t('name', $locale) }}">
                                    </td>
                                    <td class="tabular-nums opacity-60">{{ $method->position }}</td>
                                    <td class="min-w-56">
                                        <div class="flex items-start gap-2.5">
                                            <span class="h-8 w-12 shrink-0 flex items-center justify-center rounded bg-base-200">
                                                @if ($method->logoUrl())
                                                    <img src="{{ $method->logoUrl() }}" alt="" class="max-h-6 max-w-10 object-contain">
                                                @else
                                                    <x-icon name="truck" size="sm" class="opacity-40" />
                                                @endif
                                            </span>
                                            <div class="min-w-0">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <a href="{{ route('admin.shipping.methods.edit', $method) }}"
                                                       @class(['font-medium link link-hover', 'opacity-60' => ! $method->enabled])>{{ $method->t('name', $locale) }}</a>
                                                    {!! $notes[$method->id] ?? '' !!}
                                                </div>
                                                <div class="text-xs opacity-50 mt-0.5">
                                                    {{ $method->provider ? ($providers[$method->provider] ?? $method->provider) : __('Own delivery') }}
                                                </div>
                                                {{-- Why a method can be absent from checkout even with a rate:
                                                     it has requirements a cart must meet. Spelling them out here
                                                     is what stops "it just vanished" being a bug hunt. --}}
                                                @if ($method->conditions->isNotEmpty())
                                                    <a href="{{ route('admin.shipping.methods.edit', [$method, 'tab' => 'conditions']) }}"
                                                       class="block mt-1 text-xs opacity-60 hover:opacity-100">
                                                        <span class="font-semibold">{{ __('Only when:') }}</span>
                                                        @foreach ($method->conditions as $condition)
                                                            <span class="block">— {{ Conditions::describe($condition, $conditionNames) }}</span>
                                                        @endforeach
                                                    </a>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-sm">
                                        {{ __(ShippingMethod::MODES[$method->price_mode] ?? $method->price_mode) }}
                                        @if ($method->price_mode !== 'free' && $method->on_incalculable !== 'quote')
                                            <div class="text-xs opacity-50">
                                                {{ __('otherwise') }}: {{ __(ShippingMethod::ON_INCALCULABLE[$method->on_incalculable] ?? $method->on_incalculable) }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-sm">
                                        <div class="flex flex-wrap gap-1">
                                            @forelse ($method->rates as $rate)
                                                @php
                                                    // A rate offers nothing while it, or its zone, is off.
                                                    $rateOff = ! $rate->enabled ? __('This rate is switched off')
                                                        : ($rate->zone && ! $rate->zone->enabled ? __('The zone itself is switched off, so nothing is offered in it.') : '');
                                                @endphp
                                                <a href="{{ route('admin.shipping.methods.edit', [$method, 'tab' => 'rates']) }}"
                                                   @class(['badge badge-sm badge-ghost whitespace-nowrap', 'opacity-50 line-through' => $rateOff !== ''])
                                                   title="{{ $rateOff }}">
                                                    {{ $rate->zone?->t('name', $locale) ?? __('zone deleted') }}:
                                                    {{ $rate->base_rate > 0 ? admin_price($rate->base_rate, $rate->currency) : __('Free') }}
                                                </a>
                                            @empty
                                                <a href="{{ route('admin.shipping.methods.edit', [$method, 'tab' => 'rates']) }}"
                                                   class="badge badge-sm badge-warning whitespace-nowrap">{{ __('nowhere — no rates set') }}</a>
                                            @endforelse
                                        </div>
                                    </td>
                                    @include('torgash.shipping::admin.shipping_methods._status', ['method' => $method])
                                    <td class="text-end whitespace-nowrap">
                                        {{-- Zones and price, edit, and — set apart — delete.
                                             A method has no page of its own, so no preview. --}}
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.shipping.methods.edit', [$method, 'tab' => 'rates']) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Zones and price') }}" aria-label="{{ __('Zones and price') }}">
                                                <x-icon name="map-pin" />
                                            </a>
                                            <a href="{{ route('admin.shipping.methods.edit', $method) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.shipping.methods.destroy', $method) }}" class="inline"
                                                  data-confirm="{{ __('Delete :name?', ['name' => $method->t('name', $locale)]) }}">
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
