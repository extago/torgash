{{-- The choice of method, re-rendered whenever the destination changes.

     Server-rendered rather than filtered in the browser: which methods exist
     at all is a property of the winning zone, so there is no complete list to
     hide rows from. The checked one is whatever the server resolved — the
     method the customer had selected may simply not be offered where they now
     say they are.

     The heading and the zone come along inside the swap. They used to sit
     above it, outside the target, and so kept naming the zone the page had
     been LOADED with: change the country and the new prices appeared under the
     old zone's name, which is exactly the kind of half-updated screen this
     out-of-band swap exists to avoid. --}}
<div class="flex items-baseline justify-between gap-2">
    <span class="text-sm font-medium">{{ __('Shipping method') }}</span>
    @if ($quote->zone && $quote->isAddressed())
        <span class="text-xs opacity-50">{{ $quote->zone->t('name') }}</span>
    @endif
</div>

@forelse ($quote->options as $option)
    <label class="flex items-center justify-between gap-3 border border-base-300 rounded-lg px-4 py-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
        <span class="flex items-center gap-3">
            {{-- data-address is read by the form around this block: an option
                 that is collected rather than sent hides the street field. It
                 rides on the radio because this whole list is re-rendered on
                 every re-quote, so a flag kept anywhere else would go stale
                 exactly when the choice changes. --}}
            <input type="radio" name="shipping_method_id" value="{{ $option->id }}" required
                   class="radio radio-primary"
                   data-address="{{ $option->needs_address ? '1' : '0' }}"
                   @checked($quote->selected?->id === $option->id)>
            {{-- The carrier's mark, when the shop uploaded one. A fixed box so
                 a row without a logo lines up with the rows that have one —
                 ragged left edges are what a logo column usually costs. --}}
            @if ($option->method->logoUrl())
                <img src="{{ $option->method->logoUrl() }}" alt="" class="h-8 w-12 object-contain shrink-0">
            @endif
            <span>
                {{ $option->label }}
                @if ($option->delivery_time)
                    <span class="block text-xs opacity-60">{{ $option->delivery_time }}</span>
                @endif
                {{-- A pending option costs 0 in the total but must not READ as
                     free: "free" is a promise, and this one is "we will tell
                     you". Said under the name — in the price column a phantom
                     sentence squeezed the name to a word per line. --}}
                @if ($option->pending_quote)
                    <span class="block text-xs opacity-60">{{ __('cost confirmed separately') }}</span>
                @endif
            </span>
        </span>
        @unless ($option->pending_quote)
            <span class="font-semibold whitespace-nowrap tabular-nums">{{ $option->cost > 0 ? format_price($option->cost, exact: true) : __('Free') }}</span>
        @endunless
    </label>

    @if ($option->pending_quote && $quote->selected?->id === $option->id)
        <div class="text-xs opacity-60 -mt-2 px-4">
            {{ __('You can place the order now — we will confirm the delivery cost with you and it is not included in the total below.') }}
        </div>
    @endif

    {{-- The selected option's carrier fields (a branch picker, say). Only the
         selected one is in the DOM: picking another radio re-quotes through
         htmx and this block comes back for the new choice. --}}
    @if ($option->fields_view && $quote->selected?->id === $option->id)
        <div class="border border-base-300 border-t-0 rounded-b-lg -mt-3 px-4 pt-4 pb-3">
            @include($option->fields_view, $option->fields_data)
        </div>
    @endif
@empty
    <div class="alert alert-warning text-sm">
        @if ($quote->isAddressed())
            {{ __('We have no delivery method for that address yet. Please contact us and we will arrange one.') }}
        @else
            {{ __('We have no delivery method for this order yet. Please contact us and we will arrange one.') }}
        @endif
    </div>
@endforelse

{{-- What a carrier has to say about this destination when it has no option to
     offer for it. A method that cannot deliver is right to be absent; a method
     that is absent with no word about why reads as a broken shop — and the
     carrier is the only one that knows the reason ("this village has no branch,
     the nearest one is eighteen kilometres away"). Rendered inside the swap,
     so it changes with the town like everything else here. --}}
{!! hook_filter('shipping.quote.notices', '', ['quote' => $quote]) !!}
