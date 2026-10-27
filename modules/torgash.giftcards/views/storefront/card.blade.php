{{--
    A gift card's own page — the link its letter carries and the page a buyer
    prints. Addressed by a random token; the code is on the page, behind a
    button, so it is not the first thing on a screen somebody glances at.
--}}
@extends('layouts.storefront')

@section('title', __('Gift card').' · '.current_storefront()?->name)

@section('content')
    @php
        $cardState = $card->state();
        $isMine = auth()->check() && $card->owner_user_id === auth()->id();
    @endphp
    <div class="max-w-xl mx-auto flex flex-col gap-4 py-4">
        @if (session('giftcard_error'))
            <div class="alert alert-error">{{ session('giftcard_error') }}</div>
        @endif

        <div class="rounded-box p-6 sm:p-8 bg-gradient-to-br from-primary to-secondary text-primary-content shadow-lg flex flex-col gap-4">
            <div class="flex justify-between items-start gap-4">
                <div>
                    <div class="text-sm opacity-80">{{ current_storefront()?->name ?? config('app.name') }}</div>
                    <div class="text-2xl font-bold">{{ __('Gift card') }}</div>
                </div>
                <x-icon name="gift" size="xl" class="opacity-80" />
            </div>

            <div class="text-4xl sm:text-5xl font-bold tabular-nums">{{ format_price((int) $card->initial_amount, shop_currency()) }}</div>

            @if ($card->recipient_name || $card->sender_name)
                <div class="text-sm opacity-90">
                    @if ($card->recipient_name){{ __('For') }}: {{ $card->recipient_name }}@endif
                    @if ($card->recipient_name && $card->sender_name) · @endif
                    @if ($card->sender_name){{ __('From') }}: {{ $card->sender_name }}@endif
                </div>
            @endif

            @if ($card->message)
                <blockquote class="text-base italic opacity-95 whitespace-pre-line border-l-2 border-primary-content/40 pl-3">{{ $card->message }}</blockquote>
            @endif
        </div>

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <div class="flex justify-between items-center gap-3">
                    <span class="opacity-70">{{ __('Balance') }}</span>
                    <span class="text-xl font-semibold tabular-nums">{{ format_price((int) $card->balance, shop_currency()) }}</span>
                </div>
                <div class="flex justify-between items-center gap-3 text-sm">
                    <span class="opacity-70">{{ __('State') }}</span>
                    <span class="badge {{ \Torgash\Giftcards\Models\Card::stateBadge($cardState) }}">{{ \Torgash\Giftcards\Models\Card::stateLabel($cardState) }}</span>
                </div>
                @if ($card->expires_at)
                    <div class="flex justify-between items-center gap-3 text-sm">
                        <span class="opacity-70">{{ __('Valid until') }}</span>
                        <span>{{ $card->expires_at->format('d.m.Y') }}</span>
                    </div>
                @endif
                @foreach ($rulesSaid as $ruleSaid)
                    <div class="text-sm opacity-70">{{ $ruleSaid }}</div>
                @endforeach

                @if ($cardState !== 'pending')
                    <div x-data="{ shown: false }" class="flex flex-col items-center gap-3 border-t border-base-300 pt-4 mt-1">
                        <button type="button" class="btn btn-outline gap-2" x-show="!shown" @click="shown = true">
                            <x-icon name="eye" size="sm" /> {{ __('Show the code') }}
                        </button>
                        <div x-show="shown" x-cloak class="flex flex-col items-center gap-3">
                            <div class="font-mono text-2xl tracking-wider select-all">{{ $card->formattedCode() }}</div>
                            @if ($qr)
                                <div class="bg-white p-2 rounded" aria-hidden="true">{!! $qr !!}</div>
                            @endif
                            <p class="text-xs opacity-60 text-center">{{ __('Type it where the basket asks for a coupon, or show it at the till.') }}</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @if ($cardState === 'active')
            <div class="flex flex-wrap gap-2 justify-center print:hidden">
                <form method="POST" action="{{ route('giftcards.use', $card->public_token) }}">
                    @csrf
                    <button class="btn btn-primary gap-2"><x-icon name="shopping-cart" size="sm" /> {{ __('Use it in my basket') }}</button>
                </form>
                @auth
                    @unless ($isMine || $card->owner_user_id)
                        <form method="POST" action="{{ route('giftcards.claim', $card->public_token) }}">
                            @csrf
                            <button class="btn gap-2"><x-icon name="user-round-plus" size="sm" /> {{ __('Keep it in my account') }}</button>
                        </form>
                    @endunless
                @else
                    <a href="{{ route('account.login') }}" class="btn btn-ghost">{{ __('Sign in to keep it in your account') }}</a>
                @endauth
                <button type="button" class="btn btn-ghost gap-2" data-print><x-icon name="printer" size="sm" /> {{ __('Print') }}</button>
            </div>
        @endif
    </div>
@endsection
