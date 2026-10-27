{{-- What is offered where the customer says they are — the read-only shape,
     used by the cart estimate. Checkout uses the same list as radio buttons. --}}
@forelse ($quote->options as $option)
    <div class="flex justify-between gap-3 text-sm">
        <span class="opacity-70 flex items-center gap-2">
            @if ($option->method->logoUrl())
                <img src="{{ $option->method->logoUrl() }}" alt="" class="h-5 w-8 object-contain shrink-0">
            @endif
            {{ $option->method->t('name') }}
            @if ($option->delivery_time)
                <span class="opacity-60">· {{ $option->delivery_time }}</span>
            @endif
        </span>
        {{-- Nothing here may read "Free" unless it IS free: an option waiting
             for a human costs 0 in the total and an unknown amount in reality. --}}
        <span class="whitespace-nowrap {{ $option->pending_quote ? 'text-xs opacity-70' : '' }}">
            @if ($option->pending_quote)
                {{ __('cost confirmed separately') }}
            @else
                {{ $option->cost > 0 ? format_price($option->cost) : __('Free') }}
            @endif
        </span>
    </div>
@empty
    <div class="text-sm text-warning">
        @if ($quote->isAddressed())
            {{ __('We do not deliver there yet.') }}
        @else
            {{ __('No delivery method is available yet.') }}
        @endif
    </div>
@endforelse
