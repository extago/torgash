<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hello · {{ current_storefront()?->name ?? 'Torgash' }}</title>
    <link rel="stylesheet" href="{{ asset_url('/assets/css/app.css') }}">
</head>
<body class="min-h-screen bg-base-200 flex items-center justify-center">
    <div class="card bg-base-100 shadow-md max-w-md">
        <div class="card-body items-center text-center gap-4">
            <h1 class="card-title text-2xl">{{ $greeting }}</h1>
            <p class="opacity-70">
                Storefront: <span class="badge badge-primary">{{ current_storefront()?->name }}</span>
                · theme override works
            </p>
        </div>
    </div>
</body>
</html>
