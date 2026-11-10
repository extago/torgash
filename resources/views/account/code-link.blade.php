@php
    [$linkTitle, $linkText, $linkButton] = match ($purpose) {
        'email_change' => [__('Confirm your new email'), __('Press the button to confirm this address.'), __('Confirm it')],
        'password_reset' => [__('Get back into your account'), __('Press the button to continue and choose a new password.'), __('Continue')],
        default => [__('Sign in'), __('Press the button to sign in to your account.'), __('Sign in')],
    };
@endphp
@extends('layouts.storefront')

@section('title', $linkTitle.' · '.current_storefront()?->name)

{{--
    One button, and the reason for it: a mail filter opens every address in a
    letter before the person does, and a browser may fetch one ahead. Nothing
    is spent by looking at this page; the press is what spends the code.
--}}
@section('content')
    <div class="max-w-md mx-auto">
        <div class="card bg-base-100 shadow-sm border border-base-300/60">
            <div class="card-body gap-4">
                <h1 class="text-xl font-bold">{{ $linkTitle }}</h1>
                <p class="text-sm">{{ $linkText }}</p>
                <form method="POST" action="{{ $action }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-lg w-full">{{ $linkButton }}</button>
                </form>
            </div>
        </div>
    </div>
@endsection
