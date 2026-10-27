{{-- Quick view's eye in a card's corner (setting `quick_view`). The shared
     dialog's script opens the dialog once its body is filled; without script
     this is a link to the product's page. Named quick*: an @include hands a
     partial every variable its caller has. --}}
<a href="{{ route('catalog.product', $quickProduct->slug) }}"
   hx-get="{{ route('cart.quick', $quickProduct->slug) }}" hx-target="#torgash-cart-dialog-body" hx-swap="innerHTML"
   class="btn btn-ghost btn-sm btn-square btn-touch"
   title="{{ __('Quick view') }}" aria-label="{{ __('Quick view') }}: {{ $quickProduct->t('name') }}">
    <x-icon name="eye" size="lg" />
</a>
