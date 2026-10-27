@extends('layouts.storefront')

@section('title', __('A new password').' · '.current_storefront()?->name)

{{--
    After getting back in by code (NewPasswordController). The generator
    fills both boxes and shows what it made, so it can be copied into a
    password manager before saving.
--}}
@section('content')
    <div class="max-w-md mx-auto">
        <form method="POST" action="{{ route('account.password.new.update') }}" class="card bg-base-100 shadow-sm border border-base-300/60"
              x-data="{ shown: false, make() {
                  const abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                  const bytes = crypto.getRandomValues(new Uint32Array(14));
                  const pass = Array.from(bytes, b => abc[b % abc.length]).join('');
                  this.$refs.password.value = pass; this.$refs.confirm.value = pass; this.shown = true;
              } }">
            @csrf
            @method('PUT')
            <div class="card-body gap-4">
                <h1 class="text-xl font-bold">{{ __('A new password') }}</h1>
                <p class="text-sm opacity-70">{{ __('You are signed in. Type or generate a new password — the old one stops working.') }}</p>

                @if ($errors->any())
                    <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
                @endif

                <div class="flex gap-2">
                    <input x-ref="password" :type="shown ? 'text' : 'password'" name="password" required minlength="8" autocomplete="new-password"
                           class="input input-bordered w-full font-mono" placeholder="{{ __('New password (at least 8 characters)') }}">
                    <button type="button" class="btn btn-square btn-neutral" @click="make()" title="{{ __('Generate a password') }}" aria-label="{{ __('Generate a password') }}">
                        <x-icon name="refresh-cw" />
                    </button>
                </div>
                <input x-ref="confirm" :type="shown ? 'text' : 'password'" name="password_confirmation" required autocomplete="new-password"
                       class="input input-bordered w-full font-mono" placeholder="{{ __('Repeat the new password') }}">

                <button class="btn btn-primary w-full gap-2"><x-icon name="save" size="sm" /> {{ __('Save') }}</button>
                <a href="{{ route('account.dashboard') }}" class="text-sm text-center link opacity-70">{{ __('Not now') }}</a>
            </div>
        </form>
    </div>
@endsection
