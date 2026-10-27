{{-- Under the price on the product page. Included taxes are the interesting
     case: the number above is final and this says what is inside it. --}}
@php
    $included = collect($taxes)->filter(fn ($tax) => $tax->price_includes_tax);
    $added = collect($taxes)->reject(fn ($tax) => $tax->price_includes_tax);
@endphp

@if ($included->isNotEmpty())
    <div class="text-xs opacity-60">
        {{ __('incl. :tax', ['tax' => $included->map(fn ($tax) => $tax->label())->implode(', ')]) }}
    </div>
@endif

@if ($added->isNotEmpty() && ! setting('torgash.taxes', 'display_with_tax'))
    <div class="text-xs opacity-60">
        {{ __(':tax added at checkout', ['tax' => $added->map(fn ($tax) => $tax->label())->implode(', ')]) }}
    </div>
@endif
