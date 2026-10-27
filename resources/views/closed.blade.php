{{-- What a visitor sees of a closed storefront (App\Core\Storefronts\ClosedStorefront).
     A page of its own rather than the storefront layout: the header, the
     menu and the search would show the very shop that is not open yet. In
     the theme's colours, with its logo and, when the shop says so, its
     contacts. A theme overrides it as
     `themes/<theme>/views/closed.blade.php`. No script: nothing here needs one. --}}
@php
    $closedText = trim((string) pick_translation($storefront->closed_message ?? []));
    $closedPhone = $storefront->contact('phone');
    $closedEmail = $storefront->contact('email');
    $closedHours = $storefront->contact('hours');
    $closedMessengers = array_filter([
        'Telegram' => ['url' => $storefront->contact('telegram'), 'icon' => 'send'],
        'WhatsApp' => ['url' => $storefront->contact('whatsapp'), 'icon' => 'message-circle'],
        'Viber' => ['url' => $storefront->contact('viber'), 'icon' => 'phone-call'],
    ], fn (array $messenger) => $messenger['url'] !== '');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('We will open soon') }} · {{ $storefront->name }}</title>
    <link rel="stylesheet" href="{{ asset_url('/assets/css/app.css') }}">
    <x-theme.style />
</head>
<body class="relative min-h-screen overflow-hidden bg-base-200 text-base-content flex flex-col items-center justify-center p-4">
    {{-- Two soft patches of the shop's own colours behind the card. --}}
    <div class="pointer-events-none absolute -top-40 -left-32 size-[32rem] rounded-full bg-primary/20 blur-3xl" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -bottom-48 -right-32 size-[36rem] rounded-full bg-secondary/20 blur-3xl" aria-hidden="true"></div>

    <main class="relative w-full max-w-lg rounded-box bg-base-100 border border-base-300 shadow-xl px-6 py-10 sm:px-10 text-center flex flex-col items-center gap-5">
        <x-theme.logo height="h-10" class="text-2xl font-bold" />

        <div class="size-20 rounded-full bg-primary/10 text-primary flex items-center justify-center mt-2" aria-hidden="true">
            <x-icon name="store" size="xl" />
        </div>

        <div class="flex flex-col gap-2">
            <h1 class="text-3xl font-bold tracking-tight">{{ __('We will open soon') }}</h1>
            <p class="opacity-70 whitespace-pre-line leading-relaxed">{{ $closedText !== '' ? $closedText : __('The shop is being prepared. Please come back a little later.') }}</p>
        </div>

        {{-- Only when the shop asks for them (closed_contacts): a shop not open yet
             often has nobody to answer. --}}
        @if ($storefront->closed_contacts && ($closedPhone !== '' || $closedEmail !== '' || $closedMessengers !== []))
            <div class="w-full border-t border-base-300 pt-5 flex flex-col items-center gap-3">
                <p class="text-sm opacity-60">{{ __('Questions? We are in touch:') }}</p>
                <div class="flex flex-wrap justify-center gap-2">
                    @if ($closedPhone !== '')
                        <a href="tel:{{ preg_replace('/[^\d+]/', '', $closedPhone) }}" class="btn btn-primary gap-2"><x-icon name="phone" size="sm" /> {{ $closedPhone }}</a>
                    @endif
                    @if ($closedEmail !== '')
                        <a href="mailto:{{ $closedEmail }}" class="btn btn-outline gap-2"><x-icon name="mail" size="sm" /> {{ $closedEmail }}</a>
                    @endif
                    @foreach ($closedMessengers as $closedMessenger => $closedLink)
                        <a href="{{ $closedLink['url'] }}" class="btn btn-ghost gap-2" target="_blank" rel="noopener"><x-icon :name="$closedLink['icon']" size="sm" /> {{ $closedMessenger }}</a>
                    @endforeach
                </div>
                @if ($closedHours !== '')
                    <p class="text-sm opacity-60 inline-flex items-center gap-1.5"><x-icon name="clock" size="sm" /> {{ $closedHours }}</p>
                @endif
            </div>
        @endif
    </main>

    <p class="relative mt-6 text-xs opacity-50">© {{ now()->year }} {{ $storefront->name }}</p>
</body>
</html>
