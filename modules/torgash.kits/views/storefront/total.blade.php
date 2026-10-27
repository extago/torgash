{{-- What the set comes to, worked out by the server from what is ticked — the
     same arithmetic that will price the cart lines. --}}
@if ($offer['saving'] > 0)
    <div class="text-xs opacity-60">{{ __('Recommended total') }}</div>
    <div class="text-sm torgash-old-price">{{ format_price($offer['full_total']) }}</div>
@endif

<div class="text-xs opacity-60">{{ __('Price for the set') }}</div>
<div class="text-2xl font-bold {{ $offer['saving'] > 0 ? 'torgash-discount-text' : 'torgash-price' }}">{{ format_price($offer['kit_total']) }}</div>

@if ($offer['saving'] > 0)
    <div class="text-xs text-success">{{ __('You save :amount', ['amount' => format_price($offer['saving'])]) }}</div>
@endif

<button type="submit" class="btn btn-primary btn-block mt-2 whitespace-nowrap">
    <x-icon name="shopping-bag" /> {{ __('Buy the set') }}
</button>

{{-- The main product's price and answers beside it, out of band: a question
     answered in the buy panel changes them as much as the total. --}}
@if (request()->header('HX-Request'))
    <div hx-swap-oob="outerHTML:#kit-main-{{ $offer['kit']->id }}">
        @include('torgash.kits::storefront.main-extra', ['kitId' => $offer['kit']->id, 'mainPrice' => $offer['main_price'], 'mainParts' => $offer['main_parts']])
    </div>
@endif
