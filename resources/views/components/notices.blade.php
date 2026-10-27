{{-- The stack of corner notices, printed once per storefront page through
     core.footer (CoreServiceProvider). Filled out of band by the notice component in an
     HTMX answer; notices.js only takes them away. --}}
<div id="torgash-notices" class="torgash-notices" aria-live="polite"></div>
<script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/notices.js') }}" defer></script>
