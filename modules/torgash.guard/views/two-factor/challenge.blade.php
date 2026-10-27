<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
{{--
    The code box, and the one page in this module that extends no layout.

    That is deliberate. Somebody who is halfway in should not be handed a
    navigation menu, because the middleware would bounce every item on it
    straight back to this page — and a shop whose every link returns you to
    where you started is one a person concludes is broken. So: the shop's name,
    one question, and the way out.
--}}
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Confirm it is you') }} · {{ $shop }}</title>
    <link rel="stylesheet" href="{{ asset_url('/assets/css/app.css') }}">
</head>
<body class="min-h-screen bg-base-200 flex items-center justify-center p-4">
    <div class="card bg-base-100 shadow-xl w-full max-w-md">
        <div class="card-body gap-4 p-8">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-lg bg-primary text-primary-content flex items-center justify-center">
                    <x-icon name="shield-check" size="md" />
                </div>
                <div>
                    <h1 class="text-lg font-bold leading-tight">{{ __('Confirm it is you') }}</h1>
                    <p class="text-xs opacity-60">{{ $email }}</p>
                </div>
            </div>

            <p class="text-sm opacity-70">
                {{ __('Open your authenticator app and enter the six digits it is showing for :shop.', ['shop' => $shop]) }}
            </p>

            @error('code')
                <div class="alert alert-error text-sm py-2">{{ $message }}</div>
            @enderror

            <form method="POST" action="{{ route('guard.two_factor.verify') }}" class="flex flex-col gap-3">
                @csrf

                {{-- One box for both answers. A separate "use a recovery code"
                     screen is a second door to keep in step with this one, and
                     the two answers are never confusable: six digits, or ten
                     letters with a dash in them. --}}
                <input name="code" required autofocus autocomplete="one-time-code"
                       inputmode="text" maxlength="64" placeholder="000000"
                       aria-label="{{ __('The code from your app, or a recovery code') }}"
                       class="input input-bordered input-lg w-full text-center font-mono tracking-[0.25em]">

                <button class="btn btn-primary w-full">{{ __('Confirm') }}</button>
            </form>

            <div class="text-xs opacity-60">
                @if ($codesLeft > 0)
                    {{ __('No phone? A recovery code goes in the same box — you have :n left.', ['n' => $codesLeft]) }}
                @else
                    {{ __('No phone and no recovery codes left? Ask an administrator to take the second factor off your account.') }}
                @endif
            </div>

            <div class="divider my-0"></div>

            <form method="POST" action="{{ $logout }}">
                @csrf
                <button class="btn btn-ghost btn-sm gap-2">
                    <x-icon name="log-out" size="sm" /> {{ __('Sign out') }}
                </button>
            </form>
        </div>
    </div>
</body>
</html>
