@extends('admin.layout')

@section('title', __('Shipping zones'))
@section('title_hint', __('A zone is a set of places. Rates are assigned per zone, and the narrowest matching zone wins.'))

@section('content')
    @php
        $locale = admin_content_locale();
        $townRoute = \Illuminate\Support\Facades\Route::has('admin.locations.suggest')
            ? route('admin.locations.suggest') : null;

        $ruleLabel = fn ($rule) => match ($rule->kind) {
            'country' => $rule->value === '*' ? __('Everywhere') : ($countryNames[$rule->value] ?? $rule->value),
            'state' => $regionNames[$rule->value] ?? $rule->value,
            'place' => $placeNames[(int) $rule->value] ?? $rule->value,
            'postal_prefix' => $rule->value.'*',
            default => $rule->value,
        };
        $levels = [0 => __('Everywhere'), 1 => __('Country'), 2 => __('Region'), 3 => __('Town'), 4 => __('Postal code')];
        // How many places a row shows before "and N more": a zone of
        // two hundred postal codes must not push the list off the screen.
        $coversShown = 8;
    @endphp
    @if ($townRoute)
        @include('torgash.locations::partials.place-picker-script')
    @endif

    @include('torgash.shipping::admin._nav', ['shippingScreen' => 'zones'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- The states as one row of tabs over the card, the screen's action at
         its end (app.css, `admin-tabs`); LINKS carrying the search. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Shipping zones') }}">
            @foreach (['' => __('All'), 'enabled' => __('Enabled'), 'disabled' => __('Disabled')] as $statusKey => $statusLabel)
                <a href="{{ route('admin.shipping.zones.index', array_filter(['status' => $statusKey, 'q' => $filters['q']], fn ($v) => $v !== null && $v !== '')) }}"
                   @class(['admin-tab', 'is-active' => (string) $filters['status'] === (string) $statusKey])>
                    {{ $statusLabel }} <span class="admin-tab-count">{{ $statusCounts[$statusKey] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.shipping.zones.create') }}" class="btn btn-primary">+ {{ __('New zone') }}</a>
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
                    <a href="{{ route('admin.shipping.zones.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick zones to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.shipping.zones.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected zones? The rates assigned to them go too.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($zones->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="map" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['status'] ? __('No zones match this search.') : __('No zones yet, so no shipping method is offered anywhere.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($zones->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $zones->count() }}">
                                </th>
                                <th class="w-16">{{ __('Sort order') }}</th>
                                <th>{{ __('Zone') }}</th>
                                <th>{{ __('Covers') }}</th>
                                <th>{{ __('Offered here') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($zones as $zone)
                                @php
                                    $zoneRules = $zone->rules->sortByDesc('level')->values();
                                    $zoneRates = $offered[$zone->id] ?? collect();
                                @endphp
                                <tr class="hover:bg-base-200/50 align-top" :class="selected.includes('{{ $zone->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $zone->id }}" x-model="selected"
                                               aria-label="{{ $zone->t('name', $locale) }}">
                                    </td>
                                    <td class="tabular-nums opacity-60">{{ $zone->position }}</td>
                                    <td class="min-w-40">
                                        <a href="{{ route('admin.shipping.zones.edit', $zone) }}"
                                           @class(['font-medium link link-hover', 'opacity-60' => ! $zone->enabled])>{{ $zone->t('name', $locale) }}</a>
                                        @if ($zoneRules->isNotEmpty())
                                            <div class="text-xs opacity-50 mt-0.5">{{ __('Narrowest') }}: {{ mb_strtolower($levels[$zone->rules->max('level')] ?? '—') }}</div>
                                        @endif
                                    </td>
                                    <td class="text-sm max-w-md">
                                        <div class="flex flex-wrap gap-1">
                                            @forelse ($zoneRules->take($coversShown) as $rule)
                                                <span class="badge badge-sm badge-ghost whitespace-nowrap">{{ $ruleLabel($rule) }}</span>
                                            @empty
                                                <span class="badge badge-sm badge-warning whitespace-nowrap">{{ __('nothing — matches no one') }}</span>
                                            @endforelse
                                            @if ($zoneRules->count() > $coversShown)
                                                <a href="{{ route('admin.shipping.zones.edit', $zone) }}" class="badge badge-sm badge-ghost whitespace-nowrap">
                                                    {{ __('and :count more', ['count' => $zoneRules->count() - $coversShown]) }}
                                                </a>
                                            @endif
                                        </div>
                                    </td>
                                    {{-- The other half of "does this zone do anything": a zone
                                         no method has a rate in offers nothing at checkout. --}}
                                    <td class="text-sm">
                                        <div class="flex flex-wrap gap-1">
                                            @forelse ($zoneRates as $rate)
                                                @if ($rate->method)
                                                    <a href="{{ route('admin.shipping.methods.edit', [$rate->method, 'tab' => 'rates']) }}"
                                                       @class(['badge badge-sm badge-ghost whitespace-nowrap', 'opacity-50 line-through' => ! $rate->enabled || ! $rate->method->enabled])
                                                       title="{{ ! $rate->method->enabled ? __('The method is switched off') : (! $rate->enabled ? __('This rate is switched off') : '') }}">
                                                        {{ $rate->method->t('name', $locale) }}
                                                    </a>
                                                @endif
                                            @empty
                                                <span class="badge badge-sm badge-warning whitespace-nowrap">{{ __('no method has a rate here') }}</span>
                                            @endforelse
                                        </div>
                                    </td>
                                    @include('torgash.shipping::admin.zones._status', ['zone' => $zone])
                                    <td class="text-end whitespace-nowrap">
                                        {{-- Edit, and — set apart — delete. A zone has no page
                                             of its own, so no preview. --}}
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.shipping.zones.edit', $zone) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.shipping.zones.destroy', $zone) }}" class="inline"
                                                  data-confirm="{{ __('Delete this zone? Rates assigned to it go too.') }}">
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

    {{-- The question every shipping question starts with. --}}
    <section class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div>
                <h3 class="font-semibold flex items-center gap-2"><x-icon name="map-pin" size="sm" /> {{ __('Which zone would this address get?') }}</h3>
                <p class="text-sm opacity-60 mt-1">
                    {{ __('The question every shipping question starts with. Answered without placing an order.') }}
                </p>
            </div>

            @if ($result = session('zone_test'))
                <div class="alert alert-info text-sm">
                    <div>
                        <div class="font-semibold">{{ $result['zone'] }}</div>
                        <div class="opacity-70">
                            {{ collect([$result['country'], $result['state'], $result['postal']])->filter()->implode(' · ') }}
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-1">
                            @if (! empty($result['methods']))
                                {{ __('Offered there:') }}
                                @foreach ($result['methods'] as $offeredMethod)
                                    <span class="badge badge-sm">{{ $offeredMethod }}</span>
                                @endforeach
                            @else
                                <span class="font-medium">{{ __('No shipping method has a rate in that zone — a customer there is offered nothing.') }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.shipping.zones.test') }}"
                  class="flex flex-wrap gap-3 items-end"
                  @if ($townRoute) x-data="placePicker({ endpoint: '{{ $townRoute }}' })" @endif>
                @csrf
                @if ($townRoute)
                    <fieldset class="fieldset p-0 grow max-w-sm relative">
                        <legend class="fieldset-legend">{{ __('Town') }}</legend>
                        <input type="text" class="input input-bordered w-full" autocomplete="off"
                               :value="label" @input="search($event.target.value)" @focus="open = true"
                               placeholder="{{ __('Start typing') }}">
                        <input type="hidden" name="place_id" :value="placeId">
                        @include('torgash.locations::partials.place-suggestions')
                    </fieldset>
                @endif

                <fieldset class="fieldset p-0 w-28">
                    <legend class="fieldset-legend">{{ __('or country') }}</legend>
                    <input name="country" maxlength="2" class="input input-bordered w-full uppercase" placeholder="UA">
                </fieldset>

                <fieldset class="fieldset p-0 w-40">
                    <legend class="fieldset-legend">{{ __('Postal code') }}</legend>
                    <input name="postal" class="input input-bordered w-full">
                </fieldset>

                <button class="btn">{{ __('Check') }}</button>
            </form>
        </div>
    </section>
@endsection
