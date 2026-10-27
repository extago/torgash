{{-- An account row (PLAN §38, the phone menu's top): a guest gets "Sign in |
     Register" and the shop's line about why; a customer, their name and the
     way to their orders. Everything escaped. --}}
@php
    $accountNote = trim((string) ($settings['note'] ?? ''));
    $accountDark = ($settings['style'] ?? 'dark') === 'dark';
@endphp
<div @class([
    'torgash-block torgash-block-account flex items-center gap-3 px-5 py-4',
    'torgash-bar bg-neutral text-neutral-content' => $accountDark,
    'bg-base-200' => ! $accountDark,
])>
    <span class="w-11 h-11 rounded-full border-2 border-current/40 flex items-center justify-center shrink-0 opacity-90"><x-icon name="user-round" size="lg" /></span>
    <div class="min-w-0 flex flex-col">
        @auth
            <a href="{{ route('account.dashboard') }}" class="font-semibold truncate hover:underline">{{ auth()->user()->name }}</a>
            <a href="{{ route('account.orders') }}" class="text-sm opacity-70 hover:opacity-100">{{ __('My orders') }}</a>
        @else
            <div class="font-semibold flex items-center gap-2">
                <a href="{{ route('account.login') }}" class="hover:underline">{{ __('Sign in') }}</a>
                <span class="opacity-40">|</span>
                <a href="{{ route('account.register') }}" class="hover:underline">{{ __('Register') }}</a>
            </div>
            @if ($accountNote !== '')
                <div class="text-xs opacity-70 leading-snug">{{ $accountNote }}</div>
            @endif
        @endauth
    </div>
</div>
