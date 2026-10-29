{{-- What this order can be delivered as, where it is now going. Re-rendered on
     every change to the destination or the chosen method, so the carrier's own
     field block (a branch picker) always belongs to the option beside it. --}}
@if ($error ?? null)
    <div class="alert alert-error text-xs py-2">{{ $error }}</div>
@endif

@if ($quote->zone)
    <div class="text-xs opacity-50">{{ __('Zone') }}: {{ $quote->zone->t('name') }}</div>
@endif

@forelse ($quote->options as $option)
    <label class="flex items-start justify-between gap-2 border border-base-300 rounded-lg px-3 py-2 cursor-pointer text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5">
        <span class="flex items-start gap-2">
            <input type="radio" name="shipping_method_id" value="{{ $option->id }}"
                   class="radio radio-sm mt-0.5"
                   @checked($quote->selected?->id === $option->id)>
            <span>
                {{ $option->label }}
                @if ($option->delivery_time)
                    <span class="block text-xs opacity-60">{{ $option->delivery_time }}</span>
                @endif
            </span>
        </span>
        {{-- A pending option costs 0 in the total but must not read as free. --}}
        <span class="whitespace-nowrap {{ $option->pending_quote ? 'text-xs opacity-70' : 'font-semibold' }}">
            @if ($option->pending_quote)
                {{ __('priced by hand') }}
            @else
                {{ $option->cost > 0 ? admin_price($option->cost, $order->currency_code) : __('Free') }}
            @endif
        </span>
    </label>

    {{-- Only the selected option's carrier fields are in the DOM: choosing
         another radio re-quotes and this block comes back for that choice. --}}
    @if ($option->fields_view && $quote->selected?->id === $option->id)
        <div class="border border-base-300 rounded-lg px-3 py-3 -mt-1">
            {{-- The carrier's own fields, shared with the storefront's checkout; here
                 they are drawn at the admin form's own height. --}}
            @include($option->fields_view, $option->fields_data + ['fieldSize' => ''])
        </div>
    @endif
@empty
    <div class="alert alert-warning text-xs py-2">
        @if ($quote->isAddressed())
            {{ __('No delivery method covers that address. Set the method and price by hand below.') }}
        @else
            {{ __('Choose a town to see what can be offered.') }}
        @endif
    </div>
@endforelse

@if ($quote->options->isNotEmpty())
    <div class="flex items-end gap-2 mt-1">
        <fieldset class="fieldset p-0 grow">
            <legend class="fieldset-legend">{{ __('Cost') }}</legend>
            {{-- Empty means "whatever the quote says"; a number overrules it. --}}
            <input name="shipping_cost" type="number" step="0.01" min="0"
                   class="input input-bordered w-full"
                   placeholder="{{ $quote->selected && ! $quote->selected->pending_quote
                       ? number_format(($quote->selected->cost ?? 0) / 100, 2, '.', '')
                       : __('by hand') }}">
        </fieldset>
        {{-- On success the controller answers HX-Redirect and the card
             reloads; a refusal comes back as this block with the reason. --}}
        <button class="btn btn-primary"
                hx-patch="{{ route('admin.shipping.orders.delivery', $order) }}"
                hx-include="#order-delivery"
                hx-target="#order-delivery-methods" hx-swap="innerHTML">{{ __('Apply delivery') }}</button>
    </div>
@endif
