@extends('layouts.storefront')

@section('title', __('Checkout').' · '.current_storefront()?->name)

@section('content')
    {{-- The way back: with the quiet header there is no basket icon to click. --}}
    @if (\Illuminate\Support\Facades\Route::has('cart.index'))
        <a href="{{ route('cart.index') }}" class="inline-flex items-center gap-1 text-sm opacity-60 hover:opacity-100">
            <x-icon name="chevron-left" size="sm" /> {{ __('Back to the cart') }}
        </a>
    @endif
    <h1 class="text-3xl font-bold mt-3 mb-6">{{ __('Checkout') }}</h1>

    {{-- Three steps, each its own card with its own number — the order is a
         real sequence (who, where, how to pay), so the numbers say something.
         One FORM across all of them; the button lives in the summary column
         (form=), under the total it commits to — on a phone too, where that
         column comes last: a button above the bill is pressed unread. --}}
    <div class="grid lg:grid-cols-[1fr_24rem] gap-6 items-start">
        <form id="checkout-form" method="POST" action="{{ route('checkout.store') }}" class="flex flex-col gap-4 min-w-0">
            @csrf

            @php
                $checkoutStep = fn (int $n, string $title) => '<header class="flex items-center gap-3">'
                    .'<span class="w-8 h-8 rounded-full bg-primary text-primary-content flex items-center justify-center font-semibold text-sm shrink-0">'.$n.'</span>'
                    .'<h2 class="text-lg font-semibold">'.e($title).'</h2></header>';
            @endphp

            @php
                $takesCompanies = (bool) setting('torgash.checkout', 'business_orders', false);
                $postalMode = (string) setting('torgash.checkout', 'postal_code', 'optional');
            @endphp
            <section class="bg-base-100 border border-base-300 rounded-box p-5 sm:p-6 flex flex-col gap-4"
                     x-data="{ buyer: @js(old('buyer_type', 'person')) }">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    {!! $checkoutStep(1, __('Recipient')) !!}
                    {{-- Who is buying (setting business_orders). Radios, so the
                         answer rides the form with no script; the company's
                         fields below are hidden, not removed, and the server
                         drops them for a private person. --}}
                    @if ($takesCompanies)
                        <div class="flex p-1 rounded-field bg-base-200 text-sm" role="radiogroup" aria-label="{{ __('Who is buying') }}">
                            @foreach (['person' => __('Private person'), 'company' => __('Company')] as $buyerKey => $buyerLabel)
                                <label class="px-3 py-1.5 rounded-field cursor-pointer has-[:checked]:bg-base-100 has-[:checked]:shadow-sm has-[:checked]:font-medium">
                                    <input type="radio" name="buyer_type" value="{{ $buyerKey }}" x-model="buyer" class="sr-only" @checked(old('buyer_type', 'person') === $buyerKey)>
                                    {{ $buyerLabel }}
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if ($takesCompanies)
                    <div class="grid md:grid-cols-[1fr_14rem] gap-4" x-show="buyer === 'company'" x-cloak>
                        <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Company name') }} *</span>
                            <input name="company_name" value="{{ old('company_name') }}" autocomplete="organization"
                                   :required="buyer === 'company'" class="input input-bordered w-full">
                        </label>
                        <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Tax code') }}</span>
                            <input name="company_tax_id" value="{{ old('company_tax_id') }}" class="input input-bordered w-full">
                        </label>
                    </div>
                @endif

                <div class="grid md:grid-cols-2 gap-4">
                    <label class="fieldset p-0 md:col-span-2"><span class="fieldset-legend">
                            @if ($takesCompanies)
                                <span x-show="buyer !== 'company'">{{ __('Full name') }}</span>
                                <span x-show="buyer === 'company'" x-cloak>{{ __('Contact person') }}</span> *
                            @else
                                {{ __('Full name') }} *
                            @endif
                        </span>
                        <input name="customer_name" value="{{ old('customer_name', $prefill['customer_name'] ?? '') }}" required autocomplete="name" class="input input-bordered w-full">
                    </label>
                    {{-- Which contact is required is the shop's (`order_contact`). --}}
                    @php
                        $placing = app(\Torgash\Checkout\Orders\PlaceOrder::class);
                        $needsPhone = $placing->contactRequired('phone');
                        $needsEmail = $placing->contactRequired('email');
                    @endphp
                    <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Phone') }}{{ $needsPhone ? ' *' : '' }}</span>
                        <input type="tel" name="phone" value="{{ old('phone', $prefill['phone'] ?? '') }}" @required($needsPhone) autocomplete="tel" class="input input-bordered w-full">
                    </label>
                    <label class="fieldset p-0"><span class="fieldset-legend">Email{{ $needsEmail ? ' *' : '' }}</span>
                        {{-- A guest's address is asked about once they leave the box:
                             an account's email opens the sign-in panel below. --}}
                        <input type="email" name="email" value="{{ old('email', $prefill['email'] ?? '') }}" @required($needsEmail) autocomplete="email" class="input input-bordered w-full"
                               @guest hx-post="{{ route('checkout.account.check') }}" hx-trigger="change, load[this.value !== '']"
                               hx-target="#checkout-account-panel" hx-swap="innerHTML" @endguest>
                        <span class="text-xs opacity-60">{{ __('The order\'s details are sent here.') }}</span>
                    </label>
                    {{-- Under the pair it is about, not floating between the name and them. --}}
                    @if ($placing->orderContact() === 'either')
                        <p class="md:col-span-2 -mt-2 text-sm opacity-70">{{ __('An email or a phone — either will do.') }}</p>
                    @endif
                    @guest
                        <div id="checkout-account-panel" class="md:col-span-2 empty:hidden"></div>
                    @endguest
                    {{-- Confirm the contact by a code, when the shop asks (PLAN §44). --}}
                    <x-contact-verify form="checkout" class="md:col-span-2"
                                      :values="['phone' => old('phone', $prefill['phone'] ?? ''), 'email' => old('email', $prefill['email'] ?? '')]" />
                </div>
            </section>

            {{-- What is being ordered, where the customer reads down the page
                 (the summary column keeps only the money). "Change" is the
                 cart: quantities are edited in one place. --}}
            <section class="bg-base-100 border border-base-300 rounded-box p-5 sm:p-6 flex flex-col gap-3">
                <header class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="w-8 h-8 rounded-full bg-base-200 flex items-center justify-center shrink-0"><x-icon name="shopping-bag" size="sm" /></span>
                        <h2 class="text-lg font-semibold">{{ __('Items') }}</h2>
                    </div>
                    @if (\Illuminate\Support\Facades\Route::has('cart.index'))
                        <a href="{{ route('cart.index') }}" class="text-sm link link-hover opacity-70">{{ __('Change') }}</a>
                    @endif
                </header>
                <ul class="flex flex-col divide-y divide-base-300">
                    @foreach ($cart->items() as $checkoutItem)
                        <li class="py-3 flex items-center gap-4">
                            <span class="w-14 h-14 rounded-lg bg-base-200 overflow-hidden flex items-center justify-center shrink-0">
                                @if ($checkoutImage = $checkoutItem->product->primaryImageUrl(120))
                                    <img src="{{ $checkoutImage }}" alt="" class="w-full h-full object-contain" loading="lazy" decoding="async">
                                @else
                                    <x-no-image class="w-full h-full" size="sm" />
                                @endif
                            </span>
                            <span class="grow min-w-0">
                                <span class="block leading-snug line-clamp-2">{{ $checkoutItem->product->t('name') }}</span>
                                {{-- What is inside this line and already in its price
                                     (PLAN §33.3): the customer is about to pay for it. --}}
                                @foreach ($checkoutItem->parts as $part)
                                    <span class="block text-xs opacity-60">↳ {{ $part['label'] }}@if ($part['qty'] > 1) ×{{ $part['qty'] }}@endif</span>
                                @endforeach
                                <span class="block text-sm opacity-60 mt-0.5 tabular-nums">{{ $checkoutItem->qty }} × {{ format_price($checkoutItem->unit_price, exact: true) }}</span>
                            </span>
                            <span class="font-semibold whitespace-nowrap tabular-nums">{{ format_price($checkoutItem->line_total, exact: true) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- Everything the quote depends on lives in here, and one htmx
                 binding covers all three ways it can change: a town picked
                 from the list, a postal code typed, a method chosen. The
                 response replaces the method list and, out of band, the
                 summary — see partials/quote-fragments.

                 Town first, because the methods on offer depend on it; the
                 street last, because whether one is needed depends on the
                 method (a pickup has nowhere to be sent). The chosen radio
                 carries that answer (data-address) and the server applies the
                 same rule, so a browser that never ran the script still posts
                 a valid order. --}}
            @php $hasShipping = \Illuminate\Support\Facades\Route::has('checkout.quote'); @endphp
            {{-- Nothing to send (PLAN §40.10/2): the step stays in the page
                 for the summary's own re-quote, holding no field at all. --}}
            <section id="checkout-delivery" @class(['bg-base-100 border border-base-300 rounded-box p-5 sm:p-6 flex flex-col gap-4', 'hidden' => ! ($needsDelivery ?? true)])
                     x-data="{ needsAddress: {{ ($quote?->selected?->needs_address ?? true) ? 'true' : 'false' }} }"
                     @change="if ($event.target.name === 'shipping_method_id')
                                  needsAddress = $event.target.dataset.address !== '0'"
                     @if ($hasShipping)
                     hx-post="{{ route('checkout.quote') }}"
                     hx-trigger="destination-changed, coupon-changed from:body, change from:#checkout-postal, change from:#checkout-shipping, change from:[name='payment_method_id']"
                     hx-include="closest form"
                     hx-target="#checkout-shipping" hx-swap="innerHTML"
                     @endif>
                {!! $checkoutStep(2, __('Delivery')) !!}

                @if ($needsDelivery ?? true)
                <div @class(['grid gap-4', 'md:grid-cols-[1fr_12rem]' => $postalMode !== 'hidden'])>
                    {{-- With a country box above the town the field asks two things. --}}
                    @php $checkoutCountries = $hasShipping ? count(hook_filter('checkout.location.countries', [])) : 0; @endphp
                    <label class="fieldset p-0"><span class="fieldset-legend">{{ $checkoutCountries > 1 ? __('Country and town') : __('City') }} *</span>
                        @includeWhen($hasShipping, 'torgash.shipping::partials.town-field', [
                            'town' => $town,
                            'placeId' => $placeId,
                            'country' => $country,
                            'required' => true,
                        ])
                        @unless ($hasShipping)
                            <input name="city" value="{{ old('city', $town) }}" required class="input input-bordered w-full">
                        @endunless
                    </label>
                    {{-- The postal code is the shop's call (setting postal_code). --}}
                    @if ($postalMode !== 'hidden')
                        <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Postal code') }}{{ $postalMode === 'required' ? ' *' : '' }}</span>
                            <input id="checkout-postal" name="postal_code" value="{{ $postal }}" autocomplete="postal-code" @required($postalMode === 'required') class="input input-bordered w-full">
                        </label>
                    @endif
                </div>

                {{-- The heading is inside the swapped region on purpose —
                     see the partial. --}}
                <div id="checkout-shipping" class="flex flex-col gap-2">
                    @includeWhen($hasShipping, 'torgash.shipping::partials.shipping-methods', ['quote' => $quote])
                </div>

                <label class="fieldset p-0" x-show="needsAddress">
                    <span class="fieldset-legend">{{ __('Address') }} *</span>
                    {{-- required is bound, not fixed: a hidden field that is
                         still required blocks the submit with a message the
                         customer cannot see. --}}
                    <input name="address_line" value="{{ old('address_line', $prefill['address_line'] ?? '') }}"
                           :required="needsAddress" @required($quote?->selected?->needs_address ?? true)
                           autocomplete="street-address" class="input input-bordered w-full">
                </label>

                @auth
                    <label class="label cursor-pointer justify-start gap-2" x-show="needsAddress">
                        <input type="checkbox" name="save_address" value="1" class="checkbox checkbox-primary">
                        <span class="label-text">{{ __('Save this address to my account') }}</span>
                    </label>
                @endauth
                @endif
            </section>

            <section class="bg-base-100 border border-base-300 rounded-box p-5 sm:p-6 flex flex-col gap-4">
                {!! $checkoutStep(3, __('Payment')) !!}
                <div class="flex flex-col gap-2">
                    @foreach ($paymentMethods as $method)
                        @php $charge = $method->chargeCurrencyFor(current_currency()); @endphp
                        <label class="flex items-center gap-3 border border-base-300 rounded-lg px-4 py-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                            <input type="radio" name="payment_method_id" value="{{ $method->id }}" class="radio radio-primary"
                                   @checked(old('payment_method_id', $paymentMethods->first()?->id) == $method->id) required>
                            @if ($method->logoUrl())
                                <img src="{{ $method->logoUrl() }}" alt="" class="h-6 w-10 object-contain shrink-0">
                            @endif
                            <span class="flex flex-col">
                                <span>{{ $method->t('name') }}</span>
                                {{-- Said before the order exists, not on the payment page: a
                                     method that cannot take the currency on screen still
                                     charges a real amount, and finding that out afterwards
                                     is what makes a customer abandon a placed order. --}}
                                @if ($charge !== current_currency())
                                    <span class="text-xs opacity-60">
                                        {{ __('The payment itself goes through in :currency, converted at the current rate.', ['currency' => $charge]) }}
                                    </span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </section>

            {{-- Not a step: most orders have nothing to add. --}}
            <section class="bg-base-100 border border-base-300 rounded-box p-5 sm:p-6">
                <label class="fieldset p-0"><span class="fieldset-legend text-base font-semibold">{{ __('Order notes') }}</span>
                    <textarea name="notes" rows="2" class="textarea textarea-bordered w-full">{{ old('notes') }}</textarea>
                </label>
            </section>

            {{-- What a module asks the customer to agree to, inside the form so
                 it is posted with the order (torgash.newsletter's tick). --}}
            {!! hook_render('checkout.form.consents', []) !!}

            {{-- A CAPTCHA when the shop asks for one on orders, in a card like
                 its neighbours, and nothing at all otherwise — never core's
                 stamp or trap (FormGuard::checkAdded()). --}}
            <x-form-guard form="checkout" added-only box="bg-base-100 border border-base-300 rounded-box p-5 sm:p-6" />

        </form>

        {{-- The bill is swapped on every re-quote; what is wrong and the button
             stay outside it, so a re-quote never wipes a problem just shown. --}}
        <div class="card bg-base-100 border border-base-300 h-fit lg:sticky lg:top-6">
            <div class="card-body">
                @include('torgash.checkout::partials.summary', ['cart' => $cart, 'quote' => $quote, 'summaryDefaultMethod' => old('payment_method_id', $paymentMethods->first()?->id)])
                <x-form-problems form="checkout-form" class="mt-3" />
                <button form="checkout-form" class="btn btn-primary btn-lg w-full mt-3">{{ __('Place order') }}</button>
            </div>
        </div>
    </div>
@endsection
