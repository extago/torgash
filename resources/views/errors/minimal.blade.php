{{-- The error page with nothing around it: for a server error (the database
     may be what failed), the admin, and a request that never reached the
     storefront's session. Reads no table — only the stylesheet's file. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('error_title') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset_url('/assets/css/app.css') }}">
</head>
<body class="min-h-screen bg-base-200 text-base-content flex items-center justify-center p-4">
    @yield('content')
</body>
</html>
