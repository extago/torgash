{{-- On every storefront page (core.footer): what a gallery inside a text
     needs — content-gallery.mjs builds it, lightbox.mjs opens it. The icons sit
     inside one inert <template>, so a page without a gallery parses them and
     draws nothing; the script itself is only fetched where there is one. --}}
<template data-content-gallery-kit data-photo-of="{{ __('Photo :n of :count') }}">
    <template data-gallery-icon="arrow-left" data-label="{{ __('Back') }}"><x-icon name="arrow-left" size="lg" /></template>
    <template data-gallery-icon="image"><x-icon name="image" size="sm" /></template>
    <template data-gallery-icon="zoom" data-label="{{ __('Zoom') }}"><x-icon name="zoom-in" size="lg" /></template>
    <template data-gallery-icon="x" data-label="{{ __('Close') }}"><x-icon name="x" size="lg" /></template>
    <template data-gallery-icon="chevron-left" data-label="{{ __('Previous photo') }}"><x-icon name="chevron-left" size="xl" /></template>
    <template data-gallery-icon="chevron-right" data-label="{{ __('Next photo') }}"><x-icon name="chevron-right" size="xl" /></template>
</template>
<script nonce="{{ csp_nonce() }}" type="module">
    const galleries = () => {
        if (document.querySelector('[data-gallery]:not([data-gallery-ready])')) {
            import(@js(module_asset('torgash.media', 'content-gallery.mjs'))).then((m) => m.enhanceAll());
        }
    };
    galleries();
    document.body.addEventListener('htmx:afterSettle', galleries);
</script>
