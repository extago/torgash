@extends('admin.auth.frame')

@section('title', __('Sign in'))

@section('form')
    <form method="POST" action="{{ route('admin.login.attempt') }}" class="card-body gap-4 p-10">
        @csrf
        <div>
            <h2 class="text-xl font-bold">{{ __('Sign in') }}</h2>
            <p class="text-sm opacity-60">{{ __('Welcome back, enter your credentials.') }}</p>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm py-2">{{ session('status') }}</div>
        @endif
        @error('email')
            <div class="alert alert-error text-sm py-2">{{ $message }}</div>
        @enderror

        <fieldset class="fieldset p-0">
            <legend class="fieldset-legend">{{ __('Email') }}</legend>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="input input-bordered w-full">
        </fieldset>

        <fieldset class="fieldset p-0">
            <legend class="fieldset-legend">{{ __('Password') }}</legend>
            <input type="password" name="password" required autocomplete="current-password"
                   class="input input-bordered w-full">
        </fieldset>

        <label class="label cursor-pointer justify-start gap-2">
            <input type="checkbox" name="remember" class="checkbox checkbox-sm">
            <span class="label-text">{{ __('Remember me') }}</span>
        </label>

        <button class="btn btn-primary w-full">{{ __('Sign in') }}</button>
        <a href="{{ route('admin.password.forgot') }}" class="text-sm text-center link opacity-70">{{ __('Forgot your password?') }}</a>
    </form>
@endsection
