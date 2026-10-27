@extends('admin.auth.frame')

@section('title', __('A new password'))

{{-- Where a reset or an invitation link lands (Admin\PasswordController). --}}
@section('form')
    <form method="POST" action="{{ route('admin.password.update') }}" class="card-body gap-4 p-10">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        @if ($invite)
            <input type="hidden" name="invite" value="1">
        @endif
        <div>
            <h2 class="text-xl font-bold">{{ $invite ? __('Welcome! Choose your password') : __('A new password') }}</h2>
            <p class="text-sm opacity-60">{{ __('Then sign in with it as usual.') }}</p>
        </div>

        @if ($errors->any())
            <div class="alert alert-error text-sm py-2">{{ $errors->first() }}</div>
        @endif

        <fieldset class="fieldset p-0">
            <legend class="fieldset-legend">{{ __('Email') }}</legend>
            <input type="email" name="email" value="{{ old('email', $email) }}" required readonly class="input input-bordered w-full bg-base-200">
        </fieldset>
        <fieldset class="fieldset p-0">
            <legend class="fieldset-legend">{{ __('New password (at least 8 characters)') }}</legend>
            <input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus class="input input-bordered w-full">
        </fieldset>
        <fieldset class="fieldset p-0">
            <legend class="fieldset-legend">{{ __('Repeat the new password') }}</legend>
            <input type="password" name="password_confirmation" required autocomplete="new-password" class="input input-bordered w-full">
        </fieldset>

        <button class="btn btn-primary w-full">{{ __('Save the password') }}</button>
    </form>
@endsection
