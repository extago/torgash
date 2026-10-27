<!doctype html>
{{-- The template editor's preview: a page of its own in the editor's frame,
     with the storefront's stylesheet and the slider's script, so a frame
     turns here as it will on the shop. --}}
<html lang="{{ app()->getLocale() }}" data-theme="torgash-light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="{{ asset_url('/assets/css/app.css') }}">
    <script nonce="{{ csp_nonce() }}" src="{{ module_asset('torgash.banners', 'slider.js') }}"></script>
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/vendor/alpine.min.js') }}" defer></script>
</head>
<body class="bg-base-200 p-4">
    {!! $body !!}
</body>
</html>
