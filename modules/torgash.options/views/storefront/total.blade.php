@php
    // The line as it would be: the product's own price plus what has been
    // answered, worked out by exactly the code that will price the cart line
    // (Configurator::parts + Cart\Lines::money), so the two cannot disagree.
    $parts = app(\Torgash\Options\Configurator::class)->parts($product, max(1, (int) $qty), (array) $config);
    $money = \Torgash\Cart\Lines::money((int) $product->effectivePrice(), $parts, max(1, (int) $qty));
    $priced = array_values(array_filter($parts, fn (array $part) => $part['amount'] !== 0));
@endphp
<div class="flex flex-col gap-1">
    @if ($priced !== [])
        <ul class="flex flex-col gap-0.5 text-xs opacity-70">
            @foreach ($priced as $part)
                <li class="flex justify-between gap-2">
                    <span>
                        {{ $part['label'] }}@if ($part['qty'] > 1) ×{{ $part['qty'] }}@endif
                        @unless ($part['per_unit'])
                            <span class="opacity-60">({{ __('once for the order line') }})</span>
                        @endunless
                    </span>
                    <span class="whitespace-nowrap">
                        {{ $part['amount'] > 0 ? '+' : '−' }}{{ format_price(abs($part['amount'] * $part['qty'])) }}
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="flex justify-between items-baseline gap-2 pt-1 border-t border-base-300">
        <span class="text-sm opacity-70">{{ __('Configured price') }}</span>
        <span class="text-xl font-bold">{{ format_price($money['unit_price'] + $money['extra_total']) }}</span>
    </div>
</div>
