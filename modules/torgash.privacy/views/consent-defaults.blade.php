{{-- Google Consent Mode v2 (PLAN §49): the defaults for THIS visitor, before
     any tag — a personal part of the page's head. The banner sends the update
     when they choose. --}}
<script nonce="{{ csp_nonce() }}">window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('consent','default',{!! json_encode(app(\Torgash\Privacy\ConsentState::class)->googleDefaults()) !!});</script>
