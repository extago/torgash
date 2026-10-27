{{--
    The one-click form itself, apart from the dialog around it.

    Its own file because the form is rendered TWICE: inside the modal on the
    product page, and again by the controller when the order was refused —
    `hx-swap="outerHTML"` replaces this form with whatever comes back, so the
    answer has to be a form, not a page.
--}}
@php
    // Distinctive names: an @include inherits every variable in the caller's
    // scope, so a shared partial must never default an ordinarily-named one.
    $oneClickError = $oneClickError ?? null;
    $oneClickValues = $oneClickValues ?? [];
    // The buy box this dialog was opened from: its answers and quantity ride
    // along (`hx-include`), so "buy in one click" orders the build on screen.
    // Only a plain id — it is written into a selector.
    $oneClickBuyForm = preg_match('/^[A-Za-z0-9_-]+$/', (string) ($oneClickBuyForm ?? '')) ? $oneClickBuyForm : null;
@endphp

{{-- Replaces itself with the confirmation, so the answer appears where
     the button was instead of reloading the product page. Without HTMX
     it is an ordinary POST and the page comes back with a flash. --}}
<form method="POST" action="{{ route('checkout.one_click') }}"
      hx-post="{{ route('checkout.one_click') }}" hx-swap="outerHTML"
      hx-disabled-elt="find button[type=submit]"
      @if ($oneClickBuyForm)
          {{-- Multipart, as the buy box is: an answer may be a file. --}}
          hx-include="#{{ $oneClickBuyForm }} [name^='config'], #{{ $oneClickBuyForm }} [name='qty']"
          hx-encoding="multipart/form-data"
      @endif
      class="flex flex-col gap-3">
    @csrf
    {{-- A product page sends its product; the cart page sends the basket. --}}
    @if ($product ?? null)
        <input type="hidden" name="product_id" value="{{ $product->id }}">
        @if ($oneClickBuyForm)
            <input type="hidden" name="buy_form" value="{{ $oneClickBuyForm }}">
        @endif
    @else
        <input type="hidden" name="scope" value="cart">
    @endif
    {{-- Everything a guarded public form carries. This one creates
         ORDERS, so it matters more here than anywhere else. --}}
    <x-form-guard form="one_click" />

    @php $oneClickMessage = $oneClickError ?? $errors->first('one_click'); @endphp
    @if ($oneClickMessage)
        <div class="alert alert-warning py-2 text-sm">{{ $oneClickMessage }}</div>
    @endif

    <label class="fieldset p-0">
        <span class="fieldset-legend text-xs">{{ __('Your name') }}</span>
        {{-- The window opens on the first thing to type, not on its close
             button (the dialog takes the first focusable it finds). Only when
             it is fresh: a form sent back with a problem keeps focus where
             <x-form-problems> puts it. --}}
        <input type="text" name="customer_name" autocomplete="name"
               value="{{ $oneClickValues['customer_name'] ?? old('customer_name') }}"
               class="input input-bordered w-full" @if (empty($oneClickValues)) autofocus @endif>
    </label>

    <fieldset class="fieldset p-0">
        <legend class="fieldset-legend text-xs">{{ __('Phone') }} <span class="text-error">*</span></legend>
        <input aria-label="{{ __('Phone') }}" type="tel" name="phone" required autocomplete="tel"
               value="{{ $oneClickValues['phone'] ?? old('phone') }}"
               class="input input-bordered w-full @if ($errors->first('phone')) input-error @endif">
        @if ($errors->first('phone'))
            <p class="label text-error text-xs">{{ $errors->first('phone') }}</p>
        @endif
    </fieldset>

    <x-contact-verify form="one_click" :values="['phone' => $oneClickValues['phone'] ?? old('phone')]" />

    <button type="submit" class="btn btn-primary">{{ __('Order now') }}</button>

    <p class="text-xs opacity-60">
        {{ __('We will call you back to agree delivery and payment.') }}
    </p>
</form>
