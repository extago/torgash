{{-- The basket in the header — whatever theme includes it, the visitor's
     own on a page kept ready for guests (PLAN §61): drawn for each of them,
     or for one with no basket as it was first drawn. --}}
{!! personal('torgash.cart::partials.badge-part', ['cartBadgeOob' => $cartBadgeOob ?? false], reusable: true) !!}
