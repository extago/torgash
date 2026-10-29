@extends('layouts.storefront')

@section('title', __('Sign in').' · '.current_storefront()?->name)

@section('content')
    <div class="max-w-md mx-auto">
        <form method="POST" action="{{ route('account.login.store') }}" class="card bg-base-100 shadow-sm border border-base-300/60">
            @csrf
            <div class="card-body gap-4">
                <h1 class="text-xl font-bold">{{ __('Sign in') }}</h1>

                @if ($errors->any())
                    <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
                @endif

                @php $loginByPhone = app(\App\Core\Verification\Channels::class)->offers('phone'); @endphp
                <label class="fieldset p-0"><span class="fieldset-legend">{{ $loginByPhone ? __('Email or phone') : 'Email' }} *</span>
                    <input type="{{ $loginByPhone ? 'text' : 'email' }}" name="email" value="{{ old('email') }}" required autofocus
                           autocomplete="username" class="input input-bordered w-full">
                </label>
                <label class="fieldset p-0">
                    <span class="fieldset-legend w-full flex justify-between">
                        <span>{{ __('Password') }} *</span>
                        <a href="{{ route('account.code', ['for' => 'password_reset']) }}" class="link link-primary font-normal"
                           x-data x-on:click="const e = $el.closest('form').email.value; if (e) $el.href += '&login=' + encodeURIComponent(e)">{{ __('Forgot your password?') }}</a>
                    </span>
                    <input type="password" name="password" required class="input input-bordered w-full">
                </label>

                <label class="label cursor-pointer justify-start gap-2">
                    <input type="checkbox" name="remember" class="checkbox checkbox-primary">
                    <span class="label-text">{{ __('Remember me') }}</span>
                </label>

                <button class="btn btn-primary w-full">{{ __('Sign in') }}</button>
                @if (setting('core', 'sign_in_by_code'))
                    <a href="{{ route('account.code', ['for' => 'sign_in']) }}" class="btn btn-outline w-full">{{ __('Sign in with a code') }}</a>
                @endif
                <p class="text-sm text-center opacity-70">
                    {{ __("Don't have an account?") }}
                    <a href="{{ route('account.register') }}" class="link link-primary">{{ __('Create one') }}</a>
                </p>
            </div>
        </form>
    </div>
@endsection
