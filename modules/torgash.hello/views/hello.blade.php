<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>Hello (module view)</title>
    <link rel="stylesheet" href="{{ asset_url('/assets/css/app.css') }}">
</head>
<body class="min-h-screen bg-base-200 flex items-center justify-center">
    {{-- Fallback view shipped with the module. Themes override it via
         themes/<theme>/views/modules/torgash.hello/hello.blade.php --}}
    <p>{{ $greeting }} (module fallback view)</p>
</body>
</html>
