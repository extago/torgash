{{-- The main product's price in the set and the answers it is bought WITH —
     one element, so the kit's quote can replace it beside the total
     (`hx-swap-oob` in total.blade): before this the answers were drawn once,
     at page load, when nobody had answered anything yet, and the set never
     said which options it was about to buy.

     The questions themselves stay in the buy panel — one control each, one
     place for the answer — and the link leads there. It hides itself on a
     product with no questions (app.css, `:has(#product-options)`).

     $kitId, $mainPrice, $mainParts --}}
<div id="kit-main-{{ $kitId }}" class="flex flex-col gap-1">
    <div class="font-semibold">{{ format_price($mainPrice) }}</div>

    @if ($mainParts !== [])
        <ul class="text-xs opacity-70 leading-snug flex flex-col gap-0.5">
            @foreach ($mainParts as $part)
                <li class="truncate" title="{{ $part['label'] }}">+ {{ $part['label'] }}</li>
            @endforeach
        </ul>
    @endif

    <a href="#product-options" class="torgash-kit-options-link link link-primary text-xs self-start">
        {{ $mainParts === [] ? __('Choose options') : __('Change options') }}
    </a>
</div>
