@extends('admin.auth.frame')

@section('title', __('Forgot your password?'))

{{-- A lost password at the admin's door (Admin\PasswordController). --}}
@section('form')
    <form method="POST" action="{{ route('admin.password.email') }}" class="card-body gap-4 p-10">
        @csrf
        <div>
            <h2 class="text-xl font-bold">{{ __('Forgot your password?') }}</h2>
            <p class="text-sm opacity-60">{{ __('Type the address you sign in with — we will send a link to set a new password.') }}</p>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm py-2">{{ session('status') }}</div>
        @endif
        @error('email')
            <div class="alert alert-error text-sm py-2">{{ $message }}</div>
        @enderror

        <fieldset class="fieldset p-0">
            <legend class="fieldset-legend">{{ __('Email') }}</legend>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus class="input input-bordered w-full">
        </fieldset>

        <button class="btn btn-primary w-full">{{ __('Send the link') }}</button>
        <a href="{{ route('admin.login') }}" class="text-sm text-center link opacity-70">{{ __('Back to signing in') }}</a>
    </form>
@endsection
