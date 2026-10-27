@php
    // What this set offers right now, priced against the product the question
    // is asked on — the same rule every other answer follows.
    $rules = app(\Torgash\Options\PriceRules::class);
    $owner = $product ?? $group?->product;
    $base = $owner ? (int) $owner->effectivePrice() : 0;
    $showPrices = (bool) setting('torgash.options', 'show_price_in_label', true);
@endphp
@unless ($group?->required)
    <option value="">{{ __('Not needed') }}</option>
@endunless
@foreach ($products as $setProduct)
    @php
        $value = $choice->id.':'.$setProduct->id;
        $amount = $rules->apply($choice, $base, $setProduct);
        $orderable = $setProduct->isOrderable();
        // Built in PHP rather than by chaining directives after the label:
        // Blade does not recognise a directive written immediately after a
        // word character, so an "endif" followed straight by an "unless"
        // compiles half-open — see docs/LESSONS.md.
        $suffix = ($showPrices && $amount !== 0 ? ' '.($amount > 0 ? '+' : '−').format_price(abs($amount)) : '')
            .($orderable ? '' : ' — '.$setProduct->stockLabel());
    @endphp
    {{-- Sold out is an ANSWER: shown, refused, and saying which it is. A
         narrowed list that silently dropped it would have somebody searching
         for a processor they can see advertised on the page they came from
         (see fields.blade for the whole rule). --}}
    <option value="{{ $value }}" @selected($chosen === $value) @disabled(! $orderable)>
        {{ $choice->label($setProduct) }}{{ $suffix }}
    </option>
@endforeach
@if ($products->isEmpty())
    <option value="" disabled>{{ __('Nothing matches') }}</option>
@endif
