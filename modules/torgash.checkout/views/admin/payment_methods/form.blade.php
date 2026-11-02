@extends('admin.layout')

@section('title', $method->exists ? __('Edit payment method') : __('New payment method'))
@section('own_heading', '1')

@section('content')
    <form method="POST" enctype="multipart/form-data" class="flex flex-col gap-4"
          action="{{ $method->exists ? route('admin.checkout.payment-methods.update', $method) : route('admin.checkout.payment-methods.store') }}">
        @csrf
        @if ($method->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.checkout.payment-methods.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to payment methods') }}" aria-label="{{ __('Back to payment methods') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $method->exists ? __('Edit payment method') : __('New payment method') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $method->exists ? $method->t('name') : __('New payment method') }}</div>
            </div>
            @if ($method->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li>
                            <button type="submit" name="after_save" value="close">
                                <x-icon name="check" /> {{ __('Save and close') }}
                            </button>
                        </li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ route('admin.checkout.payment-methods.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $method->exists ? __('Save') : __('Create') }}</button>
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
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Basics') }}</h3>
                        <x-admin.translatable-field name="name" :label="__('Name')" :value="$method->name ?? []" required autocomplete="off"
                                                    :placeholder="__('e.g. Cash on delivery')" />

                        <div>
                            <x-admin.translatable-field name="instructions" :label="__('Instructions')" :value="$method->instructions ?? []" textarea :rows="3" />
                            <p class="text-xs opacity-60 mt-1">{{ __('Shown to the customer on the order confirmation page and in the order email.') }}</p>
                        </div>
                    </div>
                </section>

                {{-- Which currencies this method can take money in, and what it does
                     about the ones it cannot. A property of the METHOD, not of a
                     gateway: "the courier only takes hryvnia" is the same question, and
                     that method has no gateway at all. --}}
                <section class="card bg-base-100 border border-base-300"
                         x-data="{ limited: {{ old('limit_currencies', $method->currencies !== null) ? 'true' : 'false' }} }">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Currencies') }}</h3>

                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="limit_currencies" value="1" class="toggle toggle-sm toggle-success" x-model="limited">
                            <span class="label-text whitespace-normal">{{ __('Only accepts certain currencies') }}</span>
                        </label>
                        <p class="text-xs opacity-60 -mt-2">
                            {{ __('Off means this method takes an order in whatever the customer was shopping in — right for cash, and for an acquirer with an account in every currency you sell in.') }}
                        </p>

                        <div x-show="limited" x-cloak class="flex flex-col gap-4">
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Accepted currencies') }}</legend>
                                <div class="flex flex-wrap gap-3">
                                    @foreach ($currencies as $currency)
                                        <label class="label cursor-pointer gap-2 border border-base-300 rounded-lg px-3 py-2 has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                            <input type="checkbox" name="currencies[]" value="{{ $currency->code }}" class="checkbox checkbox-sm"
                                                   @checked(in_array($currency->code, old('currencies', $method->currencies ?? []), true))>
                                            <span class="label-text">{{ $currency->code }} <span class="opacity-50">{{ $currency->symbol }}</span></span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>

                            <fieldset class="fieldset p-0 max-w-sm">
                                <legend class="fieldset-legend">{{ __('Orders in any other currency') }}</legend>
                                <select name="charge_currency" class="select select-bordered w-full">
                                    <option value="">{{ __('Do not offer this method') }}</option>
                                    @foreach ($currencies as $currency)
                                        <option value="{{ $currency->code }}" @selected(old('charge_currency', $method->charge_currency) === $currency->code)>
                                            {{ __('Charge in :currency', ['currency' => $currency->code]) }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="text-xs opacity-60 mt-1 whitespace-normal">
                                    {{ __('The order keeps the currency the customer agreed to; only the payment is converted, at the rate frozen onto the order. Pick one of the accepted currencies above — leaving it empty simply hides the method, which is the honest answer for a courier who cannot take dollars.') }}
                                </p>
                            </fieldset>
                        </div>
                    </div>
                </section>

                {{-- Whoever takes the money configures it HERE, on the method the
                     customer picks — one screen, the way a merchant thinks about it.
                     Checkout knows nothing about gateways; torgash.payments answers
                     this and saves itself through checkout.payment_method.saved. --}}
                {!! hook_filter('checkout.payment_method.extra', '', ['method' => $method]) !!}

                {{-- Part online now, the rest on delivery (PLAN §40.10/1). A
                     property of the method; frozen onto each order at placement. --}}
                <section class="card bg-base-100 border border-base-300"
                         x-data="{ depositType: {{ \Illuminate\Support\Js::from(old('deposit_type', $method->deposit_type ?? '')) }} }">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Prepayment') }}</h3>
                        <div class="grid md:grid-cols-2 gap-4 max-w-2xl">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Online the customer pays') }}</legend>
                                <select name="deposit_type" class="select select-bordered w-full" x-model="depositType">
                                    <option value="">{{ __('The whole order') }}</option>
                                    <option value="fixed">{{ __('A fixed amount, the rest on delivery') }}</option>
                                    <option value="percent">{{ __('A share of the order, the rest on delivery') }}</option>
                                </select>
                            </fieldset>
                            <fieldset class="fieldset p-0" x-show="depositType" x-cloak>
                                <legend class="fieldset-legend">{{ __('How much') }}</legend>
                                <label class="input input-bordered w-full">
                                    <input name="deposit_value" type="number" step="0.01" min="0" class="grow"
                                           value="{{ old('deposit_value', $method->deposit_value ? ($method->deposit_type === 'fixed' ? $method->deposit_value / 100 : $method->deposit_value) : '') }}">
                                    <span class="opacity-50" x-text="depositType === 'percent' ? '%' : @js(shop_currency())"></span>
                                </label>
                            </fieldset>
                        </div>
                        <p class="text-xs opacity-60 max-w-3xl">
                            {{ __('Only for a method that takes money online. The bank is asked for this part alone; once it arrives the order goes to work as partly paid, and the rest is collected on delivery — mark the order paid when it is. An order smaller than the amount is paid in full online.') }}
                        </p>
                    </div>
                </section>

                {{-- Whom it is offered to (PLAN §66, stage 3): the buyer, the
                     customer's group, the delivery. Nothing narrowed is
                     everybody. A manager's order is only warned. --}}
                @php
                    $offeredGroups = (array) old('customer_groups', $method->customer_groups ?? []);
                    $offeredDeliveries = array_map('strval', (array) old('shipping_methods', $method->shipping_methods ?? []));
                    $deliveryChoices = (array) hook_filter('checkout.shipping_methods.choices', []);
                @endphp
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Who it is offered to') }}</h3>
                        <input type="hidden" name="availability_set" value="1">
                        <fieldset class="fieldset p-0 max-w-sm"><legend class="fieldset-legend">{{ __('Buyer') }}</legend>
                            <select name="buyers" class="select select-bordered w-full">
                                @foreach (\Torgash\Checkout\Models\PaymentMethod::BUYERS as $buyerKey => $buyerLabel)
                                    <option value="{{ $buyerKey }}" @selected(old('buyers', $method->buyers ?? 'all') === $buyerKey)>{{ __($buyerLabel) }}</option>
                                @endforeach
                            </select>
                            <p class="label whitespace-normal">{{ __('Private person or company — when the shop takes orders from companies (checkout settings).') }}</p>
                        </fieldset>
                        <div class="grid md:grid-cols-2 gap-4 max-w-3xl">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Customer groups') }}</legend>
                                <div class="flex flex-col gap-1.5">
                                    @foreach (\Torgash\Catalog\Support\Assortment::choices() as $groupToken => $groupLabel)
                                        <label class="label justify-start gap-2 p-0 cursor-pointer">
                                            <input type="checkbox" name="customer_groups[]" value="{{ $groupToken }}" class="checkbox checkbox-sm" @checked(in_array($groupToken, $offeredGroups, true))>
                                            <span class="label-text whitespace-normal">{{ $groupLabel }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="label whitespace-normal">{{ __('None ticked — everybody.') }}</p>
                            </fieldset>
                            @if ($deliveryChoices)
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Only for these deliveries') }}</legend>
                                    <div class="flex flex-col gap-1.5">
                                        @foreach ($deliveryChoices as $deliveryId => $deliveryName)
                                            <label class="label justify-start gap-2 p-0 cursor-pointer">
                                                <input type="checkbox" name="shipping_methods[]" value="{{ $deliveryId }}" class="checkbox checkbox-sm" @checked(in_array((string) $deliveryId, $offeredDeliveries, true))>
                                                <span class="label-text whitespace-normal">{{ $deliveryName }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <p class="label whitespace-normal">{{ __('None ticked — whatever the delivery.') }}</p>
                                </fieldset>
                            @endif
                        </div>
                        <p class="text-xs opacity-60 max-w-3xl">
                            {{ __('A method narrowed here is left out of the checkout for everybody else, and the list changes as the customer picks a delivery or ticks a company. A manager placing an order sees every method and is warned about this one.') }}
                        </p>
                    </div>
                </section>

                {{-- What paying this way adds to the bill (PLAN §66) — a company
                     paying by invoice, a card fee. A percent and an amount at
                     once; a discount for paying a certain way is
                     a cart promotion with the "Payment method" condition. --}}
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Payment surcharge') }}</h3>
                        <div class="grid md:grid-cols-2 gap-4 max-w-2xl">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Percent') }}</legend>
                                <label class="input input-bordered w-full">
                                    <input name="surcharge_percent" type="number" step="0.01" min="0" max="100" class="grow"
                                           value="{{ old('surcharge_percent', $method->surcharge_percent ? $method->surcharge_percent / 100 : '') }}">
                                    <span class="opacity-50">%</span>
                                </label>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Plus a fixed amount') }}</legend>
                                <label class="input input-bordered w-full">
                                    <input name="surcharge_fixed" type="number" step="0.01" min="0" class="grow"
                                           value="{{ old('surcharge_fixed', $method->surcharge_fixed ? $method->surcharge_fixed / 100 : '') }}">
                                    <span class="opacity-50">{{ shop_currency() }}</span>
                                </label>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('The percent is of') }}</legend>
                                <select name="surcharge_base" class="select select-bordered w-full">
                                    @foreach (\Torgash\Checkout\Orders\PaymentSurcharge::BASES as $surchargeBase => $surchargeBaseLabel)
                                        <option value="{{ $surchargeBase }}" @selected(old('surcharge_base', $method->surcharge_base ?? 'goods') === $surchargeBase)>{{ __($surchargeBaseLabel) }}</option>
                                    @endforeach
                                </select>
                            </fieldset>
                        </div>

                        {{-- Two places, two answers: a shop may keep the list of
                             methods quiet and name the line in the bill, or the
                             other way round. --}}
                        <h4 class="text-sm font-semibold mt-1">{{ __('Where the customer sees it') }}</h4>
                        <div class="grid md:grid-cols-2 gap-4 max-w-2xl">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('In the list of payment methods') }}</legend>
                                <select name="surcharge_display" class="select select-bordered w-full">
                                    @foreach (\Torgash\Checkout\Orders\PaymentSurcharge::DISPLAYS as $surchargeDisplay => $surchargeDisplayLabel)
                                        <option value="{{ $surchargeDisplay }}" @selected(old('surcharge_display', $method->surcharge_display ?? 'percent') === $surchargeDisplay)>{{ __($surchargeDisplayLabel) }}</option>
                                    @endforeach
                                </select>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('In the order total') }}</legend>
                                <select name="surcharge_in_totals" class="select select-bordered w-full">
                                    @foreach (\Torgash\Checkout\Orders\PaymentSurcharge::IN_TOTALS as $surchargeInTotals => $surchargeInTotalsLabel)
                                        <option value="{{ $surchargeInTotals }}" @selected(old('surcharge_in_totals', $method->surcharge_in_totals ?? 'line') === $surchargeInTotals)>{{ __($surchargeInTotalsLabel) }}</option>
                                    @endforeach
                                </select>
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('Without a line the surcharge is still in the total — the checkout, the confirmation and the invoice just do not name it. The order screen, the API and 1C always show it.') }}</p>
                            </fieldset>
                        </div>
                        <div class="max-w-2xl">
                            <x-admin.translatable-field name="surcharge_title" :label="__('Surcharge title')" :value="$method->surcharge_title ?? []" autocomplete="off"
                                                        :placeholder="__('Payment surcharge')" />
                        </div>
                        <p class="text-xs opacity-60 max-w-3xl">
                            {{ __('Added to the order as a line of its own. Taxes are not part of the base. Measured after every discount; an edit of a placed order recomputes it by the terms the order was placed under, and changing them here does not touch placed orders. For a discount for paying this way, make a cart promotion with the "Payment method" condition.') }}
                        </p>
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success"
                                   @checked(old('enabled', $method->enabled ?? true))>
                            <span class="label-text">{{ __('Enabled') }}</span>
                        </label>
                        <p class="text-xs opacity-60">{{ __('A disabled method is not offered at checkout; orders already paid by it keep it.') }}</p>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Position') }}</legend>
                            <input name="position" type="number" value="{{ old('position', $method->position ?? 0) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('The order of the methods at checkout, smaller first.') }}</p>
                        </fieldset>
                    </div>
                </section>

                {{-- The method's mark, shown beside it at checkout — the same
                     field ShippingMethod has for the carrier logo. --}}
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Logo') }}</h3>
                        @if ($method->exists && $method->logoUrl())
                            <div class="h-20 rounded-box bg-base-200 flex items-center justify-center">
                                <img src="{{ $method->logoUrl() }}" alt="" class="max-h-14 max-w-[80%] object-contain">
                            </div>
                        @endif
                        <input name="logo" type="file" accept="image/*" class="file-input file-input-bordered file-input-sm w-full">
                        @if ($method->exists && $method->logoUrl())
                            <label class="label cursor-pointer justify-start gap-2 p-0">
                                <input type="checkbox" name="logo_remove" value="1" class="checkbox checkbox-sm">
                                <span class="label-text text-xs">{{ __('Remove the logo') }}</span>
                            </label>
                        @endif
                        <p class="text-xs opacity-60">{{ __('Shown beside the method at checkout. A small PNG or WebP with a transparent background.') }}</p>
                    </div>
                </section>
            </div>
        </div>
    </form>
@endsection
