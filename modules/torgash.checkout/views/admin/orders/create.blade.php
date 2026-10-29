@extends('admin.layout')

@section('title', __('New order'))
@section('own_heading', '1')

{{-- A manager's order (PLAN §63.8): what the checkout asks, on one page.
     The lines live in Alpine and are posted as lines[i][…]; the customers,
     the products and the deliveries are fetched by HTMX as the manager
     types — the delivery fragment re-asks the shop whenever the basket or
     the address changes, so the price it shows is the price the order gets. --}}
@section('content')
    @php
        $oldLines = collect(old('lines', []))->values()->map(fn ($line) => [
            'product_id' => (int) ($line['product_id'] ?? 0),
            'name' => (string) ($line['name'] ?? ''),
            'sku' => (string) ($line['sku'] ?? ''),
            'qty' => max(1, (int) ($line['qty'] ?? 1)),
            'unit_price' => (string) ($line['unit_price'] ?? ''),
            'list_price' => (string) ($line['list_price'] ?? ''),
        ])->all();
    @endphp

    <form method="POST" action="{{ route('admin.orders.store') }}" id="new-order-form" class="flex flex-col gap-4"
          x-data="{
              lines: @js($oldLines),
              userId: @js(old('user_id', '')),
              customer: @js(old('customer_name', '')),
              buyerType: @js(old('buyer_type', 'person')),
              add(line) {
                  const found = this.lines.find((l) => l.product_id === line.product_id);
                  if (found) { found.qty++; } else { this.lines.push({ ...line, qty: 1, unit_price: '' }); }
                  this.$nextTick(() => document.body.dispatchEvent(new CustomEvent('lines-changed')));
              },
              drop(i) { this.lines.splice(i, 1); this.$nextTick(() => document.body.dispatchEvent(new CustomEvent('lines-changed'))); },
              pick(user) {
                  this.userId = user.id; this.customer = user.name || '';
                  for (const [field, value] of Object.entries(user.fields)) {
                      const input = this.$root.querySelector('[name=&quot;' + field + '&quot;]');
                      if (input && value !== null && value !== undefined) { input.value = value; }
                  }
                  this.$root.querySelector('#new-order-customers').innerHTML = '';
                  this.$nextTick(() => document.body.dispatchEvent(new CustomEvent('lines-changed')));
              },
              unpick() { this.userId = ''; },
          }">
        @csrf
        <input type="hidden" name="user_id" :value="userId">

        <x-admin.form-bar>
            <a href="{{ route('admin.orders.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to orders') }}" aria-label="{{ __('Back to orders') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ __('Orders') }}</div>
                <div class="font-semibold truncate leading-tight">{{ __('New order') }}</div>
            </div>
            <x-slot:actions>
                <a href="{{ route('admin.orders.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm" :disabled="! lines.length">{{ __('Create the order') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if ($errors->any())
            <div class="alert alert-error flex-col items-start gap-1 text-sm">
                @foreach ($errors->all() as $createError)
                    <span>{{ $createError }}</span>
                @endforeach
            </div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                {{-- Who. An account found by name, email or phone fills the
                     contacts and the address; nobody found is a guest order. --}}
                <section class="card bg-base-100 border border-base-300"><div class="card-body gap-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <h3 class="font-semibold flex-1">{{ __('Customer') }}</h3>
                        <template x-if="userId">
                            <span class="badge badge-sm badge-primary gap-1">
                                <x-icon name="user-check" size="sm" /> <span x-text="customer"></span>
                                <button type="button" class="opacity-70 hover:opacity-100" x-on:click="unpick()" aria-label="{{ __('Remove') }}">&times;</button>
                            </span>
                        </template>
                        <template x-if="! userId"><span class="badge badge-sm badge-ghost">{{ __('Guest order') }}</span></template>
                    </div>
                    <div class="relative">
                        <label class="input input-bordered w-full gap-2">
                            <x-icon name="search" size="sm" class="opacity-50" />
                            <input type="search" name="customer_q" autocomplete="off" class="grow" placeholder="{{ __('Find an account by name, email or phone…') }}"
                                   hx-get="{{ route('admin.orders.create.customers') }}" hx-trigger="input changed delay:300ms, search" hx-target="#new-order-customers">
                        </label>
                        <div id="new-order-customers"></div>
                    </div>

                    @if ($takesCompanies)
                        <div class="flex flex-wrap gap-4">
                            @foreach (['person' => __('A private person'), 'company' => __('A company')] as $buyerKey => $buyerLabel)
                                <label class="label cursor-pointer gap-2">
                                    <input type="radio" name="buyer_type" value="{{ $buyerKey }}" class="radio radio-sm" x-model="buyerType">
                                    <span>{{ $buyerLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="grid md:grid-cols-2 gap-4" x-show="buyerType === 'company'" x-cloak>
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Company name') }}</legend>
                                <input name="company_name" value="{{ old('company_name') }}" class="input input-bordered w-full">
                            </fieldset>
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Tax ID') }}</legend>
                                <input name="company_tax_id" value="{{ old('company_tax_id') }}" class="input input-bordered w-full">
                            </fieldset>
                        </div>
                    @endif

                    <div class="grid md:grid-cols-3 gap-4">
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Full name') }}</legend>
                            <input name="customer_name" x-model="customer" class="input input-bordered w-full">
                        </fieldset>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Email') }}</legend>
                            <input type="email" name="email" value="{{ old('email') }}" class="input input-bordered w-full">
                        </fieldset>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Phone') }}</legend>
                            <input name="phone" value="{{ old('phone') }}" class="input input-bordered w-full">
                        </fieldset>
                    </div>
                </div></section>

                {{-- What. Found by name or SKU; a line's price is the manager's
                     to type — empty means the list price. --}}
                <section class="card bg-base-100 border border-base-300"><div class="card-body gap-4">
                    <h3 class="font-semibold">{{ __('Items') }}</h3>
                    <div>
                        <label class="input input-bordered w-full gap-2">
                            <x-icon name="search" size="sm" class="opacity-50" />
                            <input type="search" name="product_q" autocomplete="off" class="grow" placeholder="{{ __('Add a product by name or SKU…') }}"
                                   hx-get="{{ route('admin.orders.create.products') }}" hx-trigger="input changed delay:300ms, search" hx-target="#new-order-products">
                        </label>
                        <div id="new-order-products"></div>
                    </div>

                    <div class="overflow-x-auto" x-show="lines.length" x-cloak>
                        <table class="table admin-cards admin-line-cards" data-editing>
                            <thead>
                                <tr>
                                    <th>{{ __('SKU') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th class="w-24">{{ __('Qty') }}</th>
                                    <th class="w-36">{{ __('Unit') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(line, i) in lines" :key="line.product_id">
                                    <tr>
                                        <td data-cell="sku" class="font-mono text-xs opacity-70" x-text="line.sku"></td>
                                        <td data-cell="name">
                                            <span x-text="line.name"></span>
                                            <input type="hidden" :name="'lines[' + i + '][product_id]'" :value="line.product_id">
                                            <input type="hidden" :name="'lines[' + i + '][name]'" :value="line.name">
                                            <input type="hidden" :name="'lines[' + i + '][sku]'" :value="line.sku">
                                            <input type="hidden" :name="'lines[' + i + '][list_price]'" :value="line.list_price">
                                        </td>
                                        <td data-cell="qty">
                                            <input type="number" min="1" max="100000" :name="'lines[' + i + '][qty]'" x-model.number="line.qty" class="input input-sm w-full" aria-label="{{ __('Qty') }}">
                                        </td>
                                        <td data-cell="unit">
                                            <input type="text" inputmode="decimal" :name="'lines[' + i + '][unit_price]'" x-model="line.unit_price" :placeholder="line.list_price" class="input input-sm w-full" aria-label="{{ __('Unit') }}">
                                        </td>
                                        <td data-cell="actions" class="text-end">
                                            <button type="button" class="btn btn-ghost btn-sm btn-square text-error" x-on:click="drop(i)" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}"><x-icon name="trash-2" /></button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-sm opacity-60" x-show="! lines.length">{{ __('An order needs at least one product — for a service, a service product that keeps no stock.') }}</p>
                    <p class="text-xs opacity-50" x-show="lines.length" x-cloak>{{ __('An empty price box means the list price. Stock is not a limit for a manager, as on an exchange.') }}</p>
                </div></section>

                {{-- Where to, and how it gets there. --}}
                <section class="card bg-base-100 border border-base-300"><div class="card-body gap-4">
                    <h3 class="font-semibold">{{ __('Delivery') }}</h3>
                    <div class="grid md:grid-cols-2 gap-4">
                        @if ($townField)
                            {{-- The checkout's own town field: a country where the
                                 shop serves several, then a type-ahead over the
                                 registry that also posts the place id — what zones
                                 and carriers match on. --}}
                            <fieldset class="fieldset p-0 md:col-span-2">
                                <legend class="fieldset-legend">{{ count($countries) > 1 ? __('Country and town') : __('City') }}</legend>
                                @include('torgash.shipping::partials.town-field', [
                                    'town' => old('city', ''),
                                    'placeId' => old('place_id', ''),
                                    'country' => old('country', $defaultCountry),
                                    'required' => false,
                                ])
                            </fieldset>
                        @else
                            @if (count($countries) > 1)
                                <fieldset class="fieldset p-0">
                                    <legend class="fieldset-legend">{{ __('Country') }}</legend>
                                    <select name="country" class="select select-bordered w-full">
                                        @foreach ($countries as $createCountry)
                                            <option value="{{ $createCountry['code'] }}" @selected(old('country', $defaultCountry) === $createCountry['code'])>{{ $createCountry['name'] }}</option>
                                        @endforeach
                                    </select>
                                </fieldset>
                            @else
                                <input type="hidden" name="country" value="{{ old('country', $countries[0]['code'] ?? $defaultCountry) }}">
                            @endif
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('City') }}</legend>
                                <input name="city" value="{{ old('city') }}" class="input input-bordered w-full">
                            </fieldset>
                        @endif
                        <fieldset class="fieldset p-0 {{ $postalMode === 'hidden' ? 'md:col-span-2' : '' }}">
                            <legend class="fieldset-legend">{{ __('Address') }}</legend>
                            <input name="address_line" value="{{ old('address_line') }}" class="input input-bordered w-full">
                        </fieldset>
                        @if ($postalMode !== 'hidden')
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Postal code') }}</legend>
                                <input name="postal_code" value="{{ old('postal_code') }}" class="input input-bordered w-full">
                            </fieldset>
                        @endif
                    </div>

                    {{-- Re-asked whenever the basket or the address changes; the
                         chosen option is kept across answers by its id. --}}
                    <div id="new-order-delivery"
                         hx-post="{{ route('admin.orders.create.delivery') }}" hx-include="#new-order-form"
                         hx-trigger="load, change from:#new-order-form delay:400ms, lines-changed from:body, destination-changed from:#new-order-form"
                         hx-target="this" hx-swap="innerHTML">
                        <p class="text-sm opacity-50">{{ __('Add a product and an address to see the deliveries.') }}</p>
                    </div>
                </div></section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300"><div class="card-body gap-4">
                    <h3 class="font-semibold">{{ __('Payment') }}</h3>
                    @forelse ($paymentMethods as $createMethod)
                        <label class="label cursor-pointer justify-start gap-3 p-0">
                            <input type="radio" name="payment_method_id" value="{{ $createMethod->id }}" class="radio radio-sm"
                                   @checked((string) old('payment_method_id', $paymentMethods->first()?->id) === (string) $createMethod->id)>
                            <span>{{ $createMethod->t('name') }}</span>
                        </label>
                    @empty
                        <p class="text-sm text-error">{{ __('There is no enabled payment method to place an order with.') }}</p>
                    @endforelse
                    <p class="text-xs opacity-50">{{ __('The status the order starts in follows the payment method, as at the checkout; change it on the order afterwards.') }}</p>
                </div></section>

                @if ($storefronts->count() > 1)
                    <section class="card bg-base-100 border border-base-300"><div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Storefront') }}</h3>
                        <select name="storefront_id" class="select select-bordered w-full">
                            @foreach ($storefronts as $createStorefront)
                                <option value="{{ $createStorefront->id }}" @selected((int) old('storefront_id', $storefronts->first()->id) === $createStorefront->id)>{{ $createStorefront->name }}</option>
                            @endforeach
                        </select>
                    </div></section>
                @endif

                <section class="card bg-base-100 border border-base-300"><div class="card-body gap-4">
                    <h3 class="font-semibold">{{ __('Notes') }}</h3>
                    <textarea name="notes" rows="4" class="textarea textarea-bordered w-full" placeholder="{{ __('What the customer asked for, in their words.') }}">{{ old('notes') }}</textarea>
                </div></section>
            </div>
        </div>
    </form>
@endsection
