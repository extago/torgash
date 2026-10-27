@extends('layouts.storefront')

@section('title', __('Enter the code').' · '.current_storefront()?->name)

{{--
    Step two: the six digits. One input, not six boxes — a phone's "code from
    Messages/Mail" suggestion fills one field (autocomplete=one-time-code), and
    six boxes break pasting. The countdown is only a label: the server decides
    whether a new code may go (Verifier::secondsUntilResend).
--}}
@section('content')
    <div class="max-w-md mx-auto">
        <div class="card bg-base-100 shadow-sm border border-base-300/60">
            <div class="card-body gap-4">
                <h1 class="text-xl font-bold">{{ __('Enter the code') }}</h1>

                @if (session('status'))
                    <div class="alert alert-success text-sm">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
                @endif

                <p class="text-sm">
                    {{ __('The code was sent to') }} <b>{{ $masked }}</b>.
                    <span class="opacity-70">{{ $row->kind === 'phone'
                        ? __('No message within two minutes? Check that the number is right, or send it another way.')
                        : __('No letter within two minutes? Check the spam folder and that the address is right.') }}</span>
                </p>

                <form method="POST" action="{{ $verifyRoute }}" class="flex flex-col gap-3">
                    @csrf
                    <input type="hidden" name="for" value="{{ $purpose }}">
                    <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9 ]*" required autofocus
                           class="input input-bordered input-lg w-full text-center font-mono tracking-[0.5em] text-2xl" placeholder="••••••">
                    <button class="btn btn-primary w-full">{{ $purpose === 'sign_in' ? __('Sign in') : __('Continue') }}</button>
                </form>

                <form method="POST" action="{{ $resendRoute }}" class="text-center text-sm"
                      x-data="{ wait: {{ (int) $wait }} }" x-init="if (wait > 0) { const t = setInterval(() => { if (--wait <= 0) clearInterval(t) }, 1000) }">
                    @csrf
                    <input type="hidden" name="for" value="{{ $purpose }}">
                    <span x-show="wait > 0" class="opacity-60">
                        {{ __('Send again in') }} <b x-text="Math.floor(wait / 60) + ':' + String(wait % 60).padStart(2, '0')"></b>
                    </span>
                    <span x-show="wait <= 0" @if ($wait > 0) style="display:none" @endif class="flex flex-wrap justify-center gap-x-3 gap-y-1">
                        @foreach ($channels as $channelKey => $channel)
                            <button name="channel" value="{{ $channelKey }}" class="link link-primary">
                                {{ count($channels) > 1 ? __('Send again: :way', ['way' => __($channel->label())]) : __('Send again') }}
                            </button>
                        @endforeach
                    </span>
                </form>

                <p class="text-sm text-center opacity-70">
                    <a href="{{ $backRoute }}" class="link">{{ $row->kind === 'phone' ? __('Use another number') : __('Use another email') }}</a>
                </p>
            </div>
        </div>
    </div>
@endsection
