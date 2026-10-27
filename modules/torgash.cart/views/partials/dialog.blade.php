{{-- On every storefront page (core.footer): the ONE dialog that the choice
     window (cart.choose) and the "added to your basket" window are drawn into
     (the corner notice is core's), and the script that opens and closes it.
     Not <x-modal>: what goes in is a whole server fragment with its own
     header, and x-modal is for content a template writes — but its rules are
     kept: a native dialog, no <form method="dialog"> (a nested form is the trap
     this project has been bitten by), the backdrop a button. --}}
<dialog id="torgash-cart-dialog" class="modal" aria-labelledby="torgash-cart-dialog-body">
    <div class="modal-box p-0 max-w-xl has-[[data-dialog-wide]]:max-w-4xl" id="torgash-cart-dialog-body"></div>
    <button type="button" class="modal-backdrop" tabindex="-1" aria-hidden="true" data-cart-dialog-close></button>
</dialog>
<script nonce="{{ csp_nonce() }}" src="{{ module_asset('torgash.cart', 'cart.js') }}" defer></script>
