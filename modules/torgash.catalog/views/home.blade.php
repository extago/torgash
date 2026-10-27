@extends('layouts.storefront')

@section('content')
    @php
        // No built-in hero: the top of the home page is the shop's own —
        // a banners block (slider) or an HTML block in this zone. The
        // categories are a "Category tiles" block from the theme's
        // `default_blocks`; the products are blocks too, several side by side
        // with the same `tabs` word being the tabs.
        $homeZones = [
            zone('storefront.home.top'),
            zone('storefront.home.content'),
            zone('storefront.home.products'),
            zone('storefront.home.bottom'),
        ];
        // Every page has one heading saying what it is; a home page made only
        // of blocks had none (audit 2026-10-15). The shop's own <h1> in a
        // block wins; otherwise the storefront's title, for crawlers and
        // screen readers, without changing what the page looks like.
        $homeHeading = str_contains(strtolower(implode('', array_map('strval', $homeZones))), '<h1')
            ? null
            : (pick_translation(current_storefront()?->home_title) ?? current_storefront()?->name);
    @endphp

    @if ($homeHeading)
        <h1 class="sr-only">{{ $homeHeading }}</h1>
    @endif

    @foreach ($homeZones as $homeZone)
        {!! $homeZone !!}
    @endforeach
@endsection
