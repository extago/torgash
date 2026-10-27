{{-- The live window's script, on every storefront page (core.footer). It
     attaches to any form carrying data-live-search — the theme marks its own
     box, and the mark means nothing without this module. Settings ride on the
     tag's data attributes; no JSON in the page. --}}
<script nonce="{{ csp_nonce() }}" src="{{ module_asset('torgash.search', 'live-search.js') }}" defer
        data-url="{{ $url }}" data-forget="{{ $forget }}" data-delay="{{ $delay }}" data-min="{{ $min }}"
        data-label="{{ __('Search suggestions') }}" data-backdrop="{{ $backdrop ? '1' : '0' }}"></script>
{{-- The chosen option, whatever element carries it — the arrows set
     aria-selected, and this is the one rule that shows it. --}}
<style>
    .torgash-live-search [role="option"][aria-selected="true"],
    .torgash-live-search li:has(> [role="option"][aria-selected="true"]) {
        background: var(--color-base-200);
    }
    .torgash-live-search [role="option"][aria-selected="true"] { outline: none; }
    /* Opening and closing like the header's own dropdowns: a short fade with a
       slight grow from the top, and back. Closed is invisible and untouchable
       rather than display:none, which could not be animated. */
    .torgash-live-search {
        transform-origin: top center;
        transition: opacity .2s cubic-bezier(0, 0, .2, 1), transform .2s cubic-bezier(0, 0, .2, 1), visibility 0s;
    }
    .torgash-live-search[data-closed] {
        opacity: 0;
        transform: scale(.97) translateY(-4px);
        visibility: hidden;
        pointer-events: none;
        transition: opacity .12s ease-in, transform .12s ease-in, visibility 0s .12s;
    }
    /* Just UNDER the header (z-30): it starts below the header anyway, and a
       menu hanging out of the header (the site/catalogue switch) belongs to
       the header's stacking context — at z-40 the dimming covered it. */
    .torgash-live-search-backdrop {
        position: fixed; left: 0; right: 0; bottom: 0; z-index: 29;
        background: rgb(0 0 0 / .4);
        transition: opacity .2s ease-out, visibility 0s;
    }
    .torgash-live-search-backdrop[data-closed] {
        opacity: 0; visibility: hidden; pointer-events: none;
        transition: opacity .15s ease-in, visibility 0s .15s;
    }
    @media (prefers-reduced-motion: reduce) {
        .torgash-live-search, .torgash-live-search[data-closed],
        .torgash-live-search-backdrop, .torgash-live-search-backdrop[data-closed] { transition: none; transform: none; }
    }
    /* Room in the box for the site/catalogue switch left of the submit button. */
    form[data-live-search]:has(.torgash-search-scope) input[name="q"] { padding-right: 5rem; }
</style>
