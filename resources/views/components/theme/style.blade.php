{{--
    The active theme's settings, as CSS — printed once in a storefront
    layout's head, after app.css (PLAN §37, App\Core\Themes\ThemeSettings).

    Custom properties only, so nothing in a template changes: daisyUI already
    draws every btn-primary and rounded-box from them. `:root:not(#_)` is an
    ID-level selector on purpose — daisyUI switches palettes with
    `:root:has(.theme-controller:checked)`, which outranks a bare :root, and
    the shop's colour has to hold in the dark palette too.

    The colour scheme is written on <html> as data-theme by the script
    printed here, before the first paint: the visitor's own choice (the
    sun/moon switch, kept in the browser), else the shop's (`mode`, carried
    by the <meta>), else — "auto" — the system's, followed while the page is
    open. Always written down, never left to the media query alone: a
    `[data-theme="torgash-dark"]` rule in app.css then holds for a visitor
    whose system is dark and who never touched the switch (the brand logos'
    plates did not). Without a script the palette still follows the system
    through the media query below.

    The shop's icon (theme setting `favicon`) is printed here too: the tab,
    the bookmarks, the search results, the closed page as well. A phone's
    home screen takes a PNG only, so an SVG is left to the tab.
--}}
@php
    $themeSettings = app(\App\Core\Themes\ThemeSettings::class);
    // Colours with a dark twin (PLAN §52): the light value for both
    // palettes, then the dark one over it in the dark palette — chosen with
    // data-theme or, with none, by the system's preference, the same two
    // ways daisyUI's own `prefersdark` palette is chosen.
    $themePalettes = $themeSettings->palettes();
    $themeCss = $themeSettings->cssVariables() + $themePalettes['light'];
    $themeDark = $themePalettes['dark'];
    $themeFaces = $themeSettings->fontFaces();
    $themeRules = $themeSettings->rules();
    $themeMode = (string) $themeSettings->get('mode', 'auto');
@endphp
<meta name="torgash-mode" content="{{ in_array($themeMode, ['light', 'dark'], true) ? $themeMode : 'auto' }}">
@if ($themeIcon = $themeSettings->imageUrl('favicon'))
    @if (str_ends_with(strtolower((string) parse_url($themeIcon, PHP_URL_PATH)), '.svg'))
        <link rel="icon" href="{{ $themeIcon }}" type="image/svg+xml">
    @else
        <link rel="icon" href="{{ $themeIcon }}">
        <link rel="apple-touch-icon" href="{{ $themeIcon }}">
    @endif
@endif
<script nonce="{{ csp_nonce() }}">
    (function () {
        var root = document.documentElement, saved = null;
        try { saved = localStorage.getItem('torgash-theme'); } catch (e) {}
        if (saved === 'torgash-dark' || saved === 'torgash-light') {
            root.setAttribute('data-theme', saved);
            return;
        }
        var mode = document.querySelector('meta[name="torgash-mode"]');
        if (mode && (mode.content === 'light' || mode.content === 'dark')) {
            root.setAttribute('data-theme', 'torgash-' + mode.content);
            return;
        }
        var system = matchMedia('(prefers-color-scheme: dark)');
        var follow = function () {
            var theme = system.matches ? 'torgash-dark' : 'torgash-light';
            root.setAttribute('data-theme', theme);
            document.querySelectorAll('.torgash-mode-switch').forEach(function (box) { box.checked = theme === 'torgash-dark'; });
        };
        follow();
        // Until the visitor chooses: the switch saves a choice, and a
        // choice is never overridden.
        system.addEventListener('change', function () {
            try { if (localStorage.getItem('torgash-theme')) { return; } } catch (e) {}
            follow();
        });
    })();
</script>
@if ($themeCss !== [] || $themeDark !== [] || $themeFaces !== [] || $themeRules !== [])
    <style data-theme-settings>
        @foreach ($themeFaces as $themeFace)
        @font-face { font-family: "{{ $themeFace['family'] }}"; font-style: normal; font-weight: 100 900; font-display: swap; src: url("{{ $themeFace['url'] }}") format("woff2");@if ($themeFace['range'] !== '') unicode-range: {{ $themeFace['range'] }};@endif }
        @endforeach
        :root:not(#_) {
            @foreach ($themeCss as $themeProperty => $themeValue)
            {{ $themeProperty }}: {!! $themeValue !!};
            @endforeach
        }
        @if ($themeDark !== [])
        :root[data-theme="torgash-dark"]:not(#_) {
            @foreach ($themeDark as $themeProperty => $themeValue)
            {{ $themeProperty }}: {!! $themeValue !!};
            @endforeach
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme]):not(#_) {
                @foreach ($themeDark as $themeProperty => $themeValue)
                {{ $themeProperty }}: {!! $themeValue !!};
                @endforeach
            }
        }
        @endif
        @foreach ($themeRules as $themeRule)
        {!! $themeRule !!}
        @endforeach
    </style>
@endif
@php
    // The shop's own CSS (theme setting `custom_css`), last, so it wins. A
    // closing style tag inside it would end the block and turn the rest into
    // page content — so it cannot close one. Left out in safe mode
    // (App\Core\Support\SafeMode): a rule that hides the body is exactly
    // what that mode exists to see past.
    $themeOwnCss = safe_mode() ? '' : trim((string) $themeSettings->get('custom_css', ''));
@endphp
@if ($themeOwnCss !== '')
    <style data-theme-custom>{!! str_ireplace('</style', '<\/style', $themeOwnCss) !!}</style>
@endif
@if ($themeSettings->framed())
    {{-- The configurator's phone and tablet frames answer hover and pointer
         questions as a touch screen — the admin's mouse made them a computer.
         Here, so every theme's layout gets it without knowing. --}}
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/preview-touch.js') }}"></script>
@endif
