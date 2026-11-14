{{-- Metrica's own loader and the counter's start (Torgash\Metrika\Counter),
     given to core's consent gate: it adds the page's nonce, and where the shop
     asks for consent the whole of it waits until "statistics" is allowed.
     The loader is Metrica's published snippet, which also refuses to load
     tag.js twice. --}}
<script>
(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};m[i].l=1*new Date();for(var j=0;j<document.scripts.length;j++){if(document.scripts[j].src===r){return;}}k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})(window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym");
ym({{ $metrikaId }}, "init", {!! json_encode((object) $metrikaInit, JSON_UNESCAPED_SLASHES) !!});
</script>
