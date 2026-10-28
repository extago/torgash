{{-- The cards' "in your basket" ticks on a page kept ready for guests (PLAN
     §61): such a page is drawn with nothing in anybody's basket
     (ListBuy::inCartMap), and this personal part, at the end of the page,
     marks what is in this visitor's — before the page is shown. Nothing for
     a visitor with an empty basket. --}}
@php $inCartIds = array_keys(app(\Torgash\Cart\ListBuy::class)->inCartMap()); @endphp
@if ($inCartIds !== [])
    <script nonce="{{ csp_nonce() }}" data-in-cart="{{ implode(' ', $inCartIds) }}">
        (() => {
            const ids = document.currentScript.dataset.inCart.split(' ');
            document.querySelectorAll('form[data-list-buy-add][data-product]').forEach((form) => {
                if (ids.includes(form.dataset.product)) form.setAttribute('data-in-cart', '');
            });
        })();
    </script>
@endif
