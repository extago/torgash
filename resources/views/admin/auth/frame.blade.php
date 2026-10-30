{{-- The admin's door: the two halves of the sign-in page and the pages beside it (a lost password, a new one).
     No version anywhere on it: a page anyone can open must not say which release to look up a weakness for. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Torgash</title>
    <link rel="icon" href="{{ admin_icon_url() }}">
    <link rel="stylesheet" href="{{ asset_url('/assets/css/app.css') }}">
</head>
<body class="min-h-screen bg-base-200 flex items-center justify-center p-4">
    <div class="card lg:card-side bg-base-100 shadow-xl overflow-hidden w-full max-w-3xl">
        <div class="lg:w-1/2 bg-neutral text-neutral-content p-10 flex flex-col justify-between relative overflow-hidden min-h-52">
            <div class="absolute -right-10 -top-10 h-40 w-40 rounded-full bg-primary/20 blur-2xl"></div>
            <div class="absolute -left-8 -bottom-8 h-32 w-32 rounded-full bg-primary/10 blur-2xl"></div>
            <div class="relative">
                <div class="h-10 w-10 rounded-lg bg-primary text-primary-content flex items-center justify-center font-bold mb-6">T</div>
                <h1 class="text-2xl font-bold mb-2">Torgash <span class="text-primary">CMS</span></h1>
                <p class="text-sm text-neutral-content/60 max-w-xs">{{ __('Modular e-commerce engine. Manage catalog, storefronts and modules from one panel.') }}</p>
            </div>
        </div>
        <div class="lg:w-1/2">
            @yield('form')
        </div>
    </div>
</body>
</html>
