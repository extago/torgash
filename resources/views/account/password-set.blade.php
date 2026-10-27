@extends('layouts.storefront')

@section('title', __('Choose your password').' · '.current_storefront()?->name)

{{-- Where "an account was made for you" lands (SetPasswordController). --}}
@section('content')
    <div class="max-w-md mx-auto">
        <form method="POST" action="{{ route('account.password.set.update') }}" class="card bg-base-100 shadow-sm border border-base-300/60">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email }}">
            <div class="card-body gap-4">
                <h1 class="text-xl font-bold">{{ __('Choose your password') }}</h1>
                <p class="text-sm opacity-70">{{ __('Your account is :email. Choose a password and you are in.', ['email' => $email]) }}</p>

                @if ($errors->any())
                    <div class="alert alert-error text-sm">
                        <span>{{ $errors->first() }}
                            <a href="{{ route('account.code', ['for' => 'password_reset', 'login' => $email]) }}" class="link">{{ __('Restore access') }}</a>
                        </span>
                    </div>
                @endif

                <input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus
                       class="input input-bordered w-full" placeholder="{{ __('New password (at least 8 characters)') }}">
                <input type="password" name="password_confirmation" required autocomplete="new-password"
                       class="input input-bordered w-full" placeholder="{{ __('Repeat the new password') }}">

                <button class="btn btn-primary w-full">{{ __('Save and sign in') }}</button>
            </div>
        </form>
    </div>
@endsection
