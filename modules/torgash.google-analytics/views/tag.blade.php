{{-- Google's tag (Torgash\GoogleAnalytics\Tag), handed to core's consent gate
     or signed as it is: either way every script here gets the page's nonce.
     gtag.js by a measurement ID, or a Tag Manager container — whose loader is
     Google's published one for a page with a nonce, passing it on to gtm.js.
     `torgashGoogleTag` says the tag is running, for the footer's script,
     which sends nothing before it. A product page says which product. --}}
@if ($googleManager)
<script>
(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;var n=d.querySelector('[nonce]');n&&j.setAttribute('nonce',n.nonce||n.getAttribute('nonce'));f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{{ $googleTag }}');
window.torgashGoogleTag = 'gtm';
@if ($googleViewItem)
window.dataLayer.push({ ecommerce: null });
window.dataLayer.push({!! json_encode(['event' => 'view_item', 'ecommerce' => $googleViewItem], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!});
@endif
</script>
@else
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $googleTag }}"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '{{ $googleTag }}');
window.torgashGoogleTag = 'gtag';
@if ($googleViewItem)
gtag('event', 'view_item', {!! json_encode($googleViewItem, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!});
@endif
</script>
@endif
