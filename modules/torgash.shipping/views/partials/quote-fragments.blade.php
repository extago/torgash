{{-- One response, two regions: the method list the request targeted, and the
     summary that hangs off it. They sit in different columns of the page and
     must never show different prices, so they are re-rendered together and the
     summary rides along as an out-of-band swap. --}}
@include('torgash.shipping::partials.shipping-methods', ['quote' => $quote])
@include('torgash.checkout::partials.summary', ['cart' => $cart, 'quote' => $quote, 'oob' => true])
