{{--
    The shop's logo — the theme's pictures, or, with none, the shop's name
    written out with the theme's dot, which is what every header drew before
    a picture could be uploaded. One component, so the headers and the
    footer's "shop signature" block cannot draw it two ways.

    Which picture is a question of the background, and the page's own
    background turns with the colour scheme. On a dark BAR ($onDark: the dark
    header, the phone's header, a dark footer) it is `logo_on_dark` (empty —
    `logo`) in both schemes, the bar being dark in both. On the page's
    colours (the phone's menu, a header in the page's colours) it is `logo`
    in the light scheme and `logo_on_dark` in the dark one. The scheme is the
    VISITOR's, chosen in the browser after the page is drawn (and a guest's
    page is kept ready for everyone), so where the two differ both are
    printed and app.css (.torgash-logo-light / -dark) shows the one that fits.

    place="footer" ({logo} and {logo_dark} in a footer band): the footer's
    own pair from the theme's Footer settings goes first — `footer_logo`,
    and in the dark scheme `footer_logo_dark` (empty — the footer logo).
--}}
@props(['onDark' => false, 'height' => 'h-8', 'place' => null])
@php
    $logoForDark = theme_image('logo_on_dark') ?: theme_image('logo');
    // What stands in each scheme: a picture's address, or null for the name.
    $logoSchemes = ['light' => $onDark ? $logoForDark : theme_image('logo'), 'dark' => $logoForDark];
    if ($place === 'footer') {
        $logoFooter = theme_image('footer_logo');
        $logoSchemes = [
            'light' => $logoFooter ?: $logoSchemes['light'],
            'dark' => theme_image('footer_logo_dark') ?: ($logoFooter ?: $logoSchemes['dark']),
        ];
    }
    // The same in both: one of it, nothing to switch.
    if ($logoSchemes['light'] === $logoSchemes['dark']) {
        $logoSchemes = ['' => $logoSchemes['light']];
    }
    $logoName = current_storefront()?->name ?? 'Torgash';
@endphp
@foreach ($logoSchemes as $logoScheme => $logoUrl)
    @php $logoSwitch = $logoScheme === '' ? '' : 'torgash-logo-'.$logoScheme; @endphp
    @if ($logoUrl)
        <img src="{{ $logoUrl }}" alt="{{ $logoName }}"
             {{ $attributes->merge(['class' => trim('torgash-logo '.$height.' w-auto max-w-48 object-contain '.$logoSwitch)]) }}>
    @else
        <span {{ $logoSwitch === '' ? $attributes : $attributes->merge(['class' => $logoSwitch]) }}>{{ $logoName }}<span class="text-primary">.</span></span>
    @endif
@endforeach
