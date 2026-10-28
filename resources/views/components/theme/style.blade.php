{{--
    The active theme's settings, as CSS — printed once in a storefront
    layout's head, after app.css (PLAN §37, App\Core\Themes\ThemeSettings).

    Custom properties only, so nothing in a template changes: daisyUI already
    draws every btn-primary and rounded-box from them. `:root:not(#_)` is an
    ID-level selector on purpose — daisyUI switches palettes with
    `:root:has(.theme-controller:checked)`, which outranks a bare :root, and
    the shop's colour has to hold in the dark palette too.

    The default mode rides a <meta> the head's own script reads before the
    first paint: a visitor's saved choice still wins; "auto" follows the
    system, as before.

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
