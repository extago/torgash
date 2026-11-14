{{-- The e-commerce container, before the counter (Torgash\Metrika\Counter):
     the counter reads what is already in it when it starts — on this page
     even if it only starts once the visitor agrees. Nothing in it leaves the
     browser without the counter. A product page also says which product. --}}
<script nonce="{{ csp_nonce() }}">
window.dataLayerYM = window.dataLayerYM || [];
@if ($metrikaDetail)
window.dataLayerYM.push({!! json_encode($metrikaDetail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!});
@endif
</script>
