@extends('layouts.storefront')

@php
    $heading = $purpose === 'sign_in' ? __('Sign in with a code') : __('Restore access');
@endphp

@section('title', $heading.' · '.current_storefront()?->name)

{{--
    Step one of a code (App\Http\Controllers\Account\CodeController): whose
    account, and — when it can be reached more than one way — where to send
    it. The box takes a phone only when some channel can carry a code to one.
--}}
@section('content')
    <div class="max-w-md mx-auto">
        <form method="POST" action="{{ route('account.code.store') }}" class="card bg-base-100 shadow-sm border border-base-300/60">
            @csrf
            <input type="hidden" name="for" value="{{ $purpose }}">
            <x-form-guard form="account.code" />

            <div class="card-body gap-4">
                <h1 class="text-xl font-bold">{{ $heading }}</h1>
                <p class="text-sm opacity-70">
                    @if ($phones)
                        {{ $purpose === 'sign_in'
                            ? __('Type the email or the phone of your account — we will send a code to sign in with.')
                            : __('Type the email or the phone of your account — we will send a code, and you will choose a new password.') }}
                    @else
                        {{ $purpose === 'sign_in'
                            ? __('Type the email of your account — we will send a code to sign in with.')
                            : __('Type the email of your account — we will send a code, and you will choose a new password.') }}
                    @endif
                </p>

                @if ($errors->any())
                    <div class="alert alert-error text-sm">
                        <span>{{ $errors->first() }}
                            @if (session('no_account'))
                                <a href="{{ route('account.register') }}" class="link">{{ __('Create one') }}</a>
                            @endif
                        </span>
                    </div>
                @endif

                <label class="fieldset p-0">
                    <span class="fieldset-legend">{{ $phones ? __('Email or phone') : 'Email' }} *</span>
                    <input type="{{ $phones ? 'text' : 'email' }}" name="login" value="{{ old('login', $login) }}" required autofocus
                           autocomplete="{{ $phones ? 'username' : 'email' }}" class="input input-bordered w-full">
                </label>

                @if ($options)
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Where to send the code') }}</legend>
                        <div class="flex flex-col gap-2">
                            @foreach ($options as $optionKey => $optionLabel)
                                <label class="flex items-center gap-3 rounded-box border border-base-300 p-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                    <input type="radio" name="via" value="{{ $optionKey }}" class="radio radio-primary" @checked($loop->first) required>
                                    <span class="text-sm">{{ $optionLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <button class="btn btn-primary w-full">{{ __('Send the code') }}</button>

                <p class="text-sm text-center opacity-70">
                    <a href="{{ route('account.login') }}" class="link link-primary">{{ __('Sign in with a password') }}</a>
                </p>
            </div>
        </form>
    </div>
@endsection
