{{--
    The shop's logo — the theme's `logo` picture, or `logo_on_dark` on a dark
    bar (falling back to the plain one), or, with neither, the shop's name
    written out with the theme's dot, which is what every header drew before
    a picture could be uploaded. One component, so the headers and the
    footer's "shop signature" block cannot draw it two ways.
--}}
@props(['onDark' => false, 'height' => 'h-8'])
@php
    $logoPath = $onDark
        ? (theme_setting('logo_on_dark') ?: theme_setting('logo'))
        : theme_setting('logo');
    $logoName = current_storefront()?->name ?? 'Torgash';
@endphp
@if ($logoPath)
    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($logoPath) }}" alt="{{ $logoName }}"
         {{ $attributes->merge(['class' => 'torgash-logo '.$height.' w-auto max-w-48 object-contain']) }}>
@else
    <span {{ $attributes }}>{{ $logoName }}<span class="text-primary">.</span></span>
@endif
