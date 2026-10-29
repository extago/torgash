@extends('admin.layout')

@section('title', $method->exists ? __('Edit shipping method') : __('New shipping method'))
@section('own_heading', '1')

@section('content')
    @php
        $locale = admin_content_locale();

        // Zones and price are a tab, not a sub-section: "where do I deliver by
        // courier, and for how much" is one question. Carrier settings belong
        // to the method too — they are this method's variant of that carrier,
        // never a shop-wide setting.
        $tabs = ['general' => __('General')];
        if ($method->exists) {
            $tabs['rates'] = __('Zones and price');
        }
        $tabs['conditions'] = __('Conditions');
        if (! empty($providers)) {
            $tabs['carrier'] = __('Carrier');
        }

        // The tab a save came from is posted back, so saving does not throw the
        // admin back to the first tab; a validation error wins over it.
        $initialTab = request('tab');
        $initialTab = isset($tabs[$initialTab]) ? $initialTab : 'general';
        if ($errors->hasAny(['name', 'position'])) {
            $initialTab = 'general';
        } elseif ($errors->has('provider') && isset($tabs['carrier'])) {
            $initialTab = 'carrier';
        }

        $panelStyle = fn (string $key) => $key === $initialTab ? '' : 'display:none';
    @endphp

    <div x-data="{ tab: '{{ $initialTab }}' }" class="flex flex-col gap-4">
        <x-admin.form-bar>
            <a href="{{ route('admin.shipping.methods.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to shipping methods') }}" aria-label="{{ __('Back to shipping methods') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">
                    {{ $method->exists ? __('Edit shipping method') : __('New shipping method') }}
                </div>
                <div class="font-semibold truncate leading-tight">
                    {{ $method->exists ? $method->t('name', $locale) : __('New shipping method') }}
                </div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" form="shipping-method-form" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($method->exists)
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="shipping-method-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    @endif
                </ul>
            </div>
            <x-slot:actions>
                <a href="{{ route('admin.shipping.methods.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                {{-- Outside the form element, so it names it: the rates tab lives
                     beside that form and may not be nested in it. --}}
                <button form="shipping-method-form" class="btn btn-primary btn-sm">
                    {{ $method->exists ? __('Save') : __('Create') }}
                </button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
        <div class="flex flex-col gap-4 min-w-0">
        <div role="tablist" class="tabs tabs-border">
            @foreach ($tabs as $key => $label)
                <a role="tab" class="tab" href="#" @click.prevent="tab = '{{ $key }}'"
                   :class="tab === '{{ $key }}' ? 'tab-active font-semibold' : ''">{{ $label }}</a>
            @endforeach
        </div>

        <form id="shipping-method-form" method="POST" enctype="multipart/form-data"
              action="{{ $method->exists ? route('admin.shipping.methods.update', $method) : route('admin.shipping.methods.store') }}"
              class="flex flex-col gap-4">
            @csrf
            @if ($method->exists) @method('PUT') @endif
            <input type="hidden" name="tab" :value="tab">

            {{-- ── General ───────────────────────────────────────────────── --}}
            <div x-show="tab === 'general'" style="{{ $panelStyle('general') }}">
                <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Basics') }}</h3>
                        <x-admin.translatable-field name="name" :label="__('Name')" :value="$method->name ?? []" required autocomplete="off"
                                                    :placeholder="__('e.g. Courier delivery')" />

                        {{-- Collection, "we will call you": nothing is driven
                             anywhere, and demanding a street only gets a
                             made-up one. A carrier answers this per variant
                             itself, so the box is for methods without one. --}}
                        <label class="label cursor-pointer justify-start gap-2 p-0">
                            <input type="hidden" name="needs_address" value="0">
                            <input type="checkbox" name="needs_address" value="1" class="checkbox checkbox-sm"
                                   @checked(old('needs_address', $method->needs_address ?? true))>
                            <span class="label-text">{{ __('The customer gives a delivery address') }}</span>
                        </label>
                        <div class="text-xs opacity-60 -mt-2">{{ __('Off for collection from the shop: checkout then asks only for a name and a phone. A method priced by a carrier ignores it — the carrier says it per variant.') }}</div>

                        {{-- A method being created has no zones tab yet (rates
                             need an id), so the pricing choice lives here until
                             it does, rather than defaulting silently. --}}
                        @unless ($method->exists)
                            <div class="border-t border-base-300 pt-4">
                                @include('torgash.shipping::admin.shipping_methods.partials.price-mode')
                            </div>
                        @endunless
                    </div>
                </section>

                {{-- Another module's fields about THIS method, each its own
                     card. It prints this and stores what comes back through
                     shipping.method.saved — the same pair as payment methods,
                     and for the same reason: the fact belongs to the method,
                     not to the module that needs it. --}}
                {!! hook_filter('shipping.method.extra', '', ['method' => $method]) !!}
                </div>
            </div>

            {{-- ── Conditions ────────────────────────────────────────────── --}}
            <div x-show="tab === 'conditions'" style="{{ $panelStyle('conditions') }}">
                @include('torgash.shipping::admin.shipping_methods.partials.conditions')
            </div>

            {{-- ── Carrier ───────────────────────────────────────────────── --}}
            @if (! empty($providers))
                <div x-show="tab === 'carrier'" style="{{ $panelStyle('carrier') }}">
                    <div class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-4">
                            <fieldset class="fieldset p-0 max-w-md">
                                <legend class="fieldset-legend">{{ __('Priced by') }}</legend>
                                <select name="provider" class="select select-bordered w-full">
                                    <option value="">{{ __('Flat rate (zone bands)') }}</option>
                                    @foreach ($providers as $key => $title)
                                        <option value="{{ $key }}" @selected(old('provider', $method->provider ?? '') === $key)>{{ $title }}</option>
                                    @endforeach
                                </select>
                                <div class="text-xs opacity-50">
                                    {{ __('One carrier, one variant per method — to branch, to locker, by courier are separate methods sharing a carrier. Change the carrier and save to see its settings.') }}
                                </div>
                            </fieldset>

                            @if (! empty($providerConfigView))
                                <div class="rounded-lg border border-base-300 bg-base-200/40 p-4">
                                    @include($providerConfigView, ['config' => $providerConfig])
                                </div>
                            @else
                                <div class="text-sm opacity-60">
                                    {{ __('A flat-rate method costs what its rate in the matching zone says — see the zones tab.') }}
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </form>

        {{-- ── Zones and price ───────────────────────────────────────────────
             Beside the method form, never inside it: every rate carries its own
             form and saves on its own over HTMX. --}}
        @if ($method->exists)
            <div x-show="tab === 'rates'" style="{{ $panelStyle('rates') }}">
                <div class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <p class="text-sm opacity-60">
                            {{ __('Prices live on the pair (method, zone): the same courier is not the same price to the next street and to the next country, and a zone with no rate does not offer this method at all. Rates save on their own — the Save button above is for the fields on the other tabs.') }}
                        </p>

                        <div class="border-b border-base-300 pb-4">
                            @include('torgash.shipping::admin.shipping_methods.partials.price-mode')
                        </div>

                        @if ($zones->isEmpty())
                            <div class="alert text-sm">
                                <x-icon name="info" size="sm" />
                                <span>
                                    {{ __('No zones yet, so this method is offered nowhere.') }}
                                    <a href="{{ route('admin.shipping.zones.index') }}" class="link">{{ __('Create a zone') }}</a>.
                                </span>
                            </div>
                        @else
                            <div class="overflow-x-auto">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Zone') }}</th>
                                            <th>{{ __('Base rate') }}</th>
                                            <th>{{ __('Bands') }}</th>
                                            <th>{{ __('Delivery time') }}</th>
                                            <th>{{ __('Status') }}</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($zones as $zone)
                                            @include('torgash.shipping::admin.shipping_rates.partials.row', [
                                                'method' => $method,
                                                'zone' => $zone,
                                                'rate' => $rates[$zone->id] ?? null,
                                            ])
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="text-xs opacity-50">
                                {{ __('Every zone you deliver to needs a rate here.') }}
                                <a href="{{ route('admin.shipping.zones.index') }}" class="link">{{ __('Manage zones') }}</a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
        </div>

        {{-- Beside every tab. Its fields belong to the method form, which it
             is not inside (the rates between them carry forms of their own),
             so each names it with form="". --}}
        <div class="flex flex-col gap-4">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Status') }}</h3>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" name="enabled" value="1" form="shipping-method-form" class="toggle toggle-sm toggle-success"
                               @checked(old('enabled', $method->enabled ?? true))>
                        <span class="label-text">{{ __('Enabled') }}</span>
                    </label>
                    <p class="text-xs opacity-60">{{ __('A disabled method is not offered at checkout; placed orders keep its name.') }}</p>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Position') }}</legend>
                        <input name="position" type="number" form="shipping-method-form" value="{{ old('position', $method->position ?? 0) }}" class="input input-bordered w-full">
                        <p class="label text-xs opacity-60 whitespace-normal">{{ __('Order at checkout, smallest first.') }}</p>
                    </fieldset>
                </div>
            </section>

            @if ($method->exists)
                {{-- "Where does the customer see this" at a glance, without
                     opening the zones tab — the question the list answers too. --}}
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-2 text-sm">
                        <h3 class="font-semibold">{{ __('Offered in') }}</h3>
                        @php $pricedZones = $zones->filter(fn ($zone) => isset($rates[$zone->id])); @endphp
                        @forelse ($pricedZones as $zone)
                            @php $zoneRate = $rates[$zone->id]; @endphp
                            {{-- Offered only where the rate AND the zone are on:
                                 a rate in a switched-off zone offers nothing. --}}
                            <div @class(['flex justify-between gap-2', 'opacity-50' => ! $zoneRate->enabled || ! $zone->enabled])>
                                <span class="truncate">{{ $zone->t('name', $locale) }}</span>
                                <span class="tabular-nums whitespace-nowrap">
                                    @if (! $zone->enabled)
                                        {{ __('zone off') }}
                                    @elseif (! $zoneRate->enabled)
                                        {{ __('off') }}
                                    @else
                                        {{ $zoneRate->base_rate > 0 ? admin_price($zoneRate->base_rate, $zoneRate->currency) : __('Free') }}
                                    @endif
                                </span>
                            </div>
                        @empty
                            <div class="alert alert-warning py-2 text-xs">{{ __('Nowhere yet: no zone has a rate for this method.') }}</div>
                        @endforelse
                        @if ($zones->count() > $pricedZones->count() && $pricedZones->isNotEmpty())
                            <p class="text-xs opacity-60">{{ trans_choice(':count zone has no rate and does not offer it.|:count zones have no rate and do not offer it.', $zones->count() - $pricedZones->count(), ['count' => $zones->count() - $pricedZones->count()]) }}</p>
                        @endif
                        <button type="button" class="btn btn-ghost btn-sm gap-1.5 self-start -ml-2" @click="tab = 'rates'">
                            <x-icon name="map-pin" size="sm" /> {{ __('Zones and price') }}
                        </button>
                    </div>
                </section>
            @endif

            {{-- The carrier's mark, shown beside the method at checkout — a
                 customer picks "Nova Poshta" by its logo long before reading
                 the row. --}}
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Logo') }}</h3>
                    @if ($method->exists && $method->logoUrl())
                        <div class="h-20 rounded-box bg-base-200 flex items-center justify-center">
                            <img src="{{ $method->logoUrl() }}" alt="" class="max-h-14 max-w-[80%] object-contain">
                        </div>
                    @endif
                    <input name="logo" type="file" accept="image/*" form="shipping-method-form" class="file-input file-input-bordered w-full">
                    @if ($method->exists && $method->logoUrl())
                        <label class="label cursor-pointer justify-start gap-2 p-0">
                            <input type="checkbox" name="logo_remove" value="1" form="shipping-method-form" class="checkbox checkbox-sm">
                            <span class="label-text text-xs">{{ __('Remove the logo') }}</span>
                        </label>
                    @endif
                    <p class="text-xs opacity-60">{{ __('Shown beside the method at checkout. A small PNG or WebP with a transparent background.') }}</p>
                </div>
            </section>
        </div>
        </div>
    </div>

    @if ($method->exists)
        <form method="POST" id="shipping-method-delete" action="{{ route('admin.shipping.methods.destroy', $method) }}"
              data-confirm="{{ __('Delete :name?', ['name' => $method->t('name', $locale)]) }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
