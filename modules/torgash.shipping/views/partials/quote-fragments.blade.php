{{-- One response, three regions: the method list the request targeted, the
     summary that hangs off it, and the payment methods offered with this
     delivery (PLAN §66). They sit in different parts of the page and must
     never disagree, so they are re-rendered together and the other two ride
     along as out-of-band swaps. --}}
@php $checkoutPayment = app(\Torgash\Checkout\Orders\PlaceOrder::class)->checkoutChoice(request(), $cart, $quote); @endphp
@include('torgash.shipping::partials.shipping-methods', ['quote' => $quote])
@include('torgash.checkout::partials.summary', ['cart' => $cart, 'quote' => $quote, 'oob' => true, 'summaryMethodChosen' => $checkoutPayment['chosen']])
@include('torgash.checkout::partials.payment-methods', ['cart' => $cart, 'quote' => $quote, 'oob' => true, 'checkoutPayment' => $checkoutPayment])
