@extends('layouts.storefront')

@section('title', __('Create account').' · '.current_storefront()?->name)

@section('content')
    <div class="max-w-md mx-auto">
        <form method="POST" action="{{ route('account.register.store') }}" class="card bg-base-100 shadow-sm border border-base-300/60">
            @csrf
            <div class="card-body gap-4">
                <h1 class="text-xl font-bold">{{ __('Create account') }}</h1>

                @if ($errors->any())
                    <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
                @endif

                <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Full name') }} *</span>
                    <input name="name" value="{{ old('name') }}" required autofocus class="input input-bordered w-full">
                </label>
                {{-- Which contacts are asked is the shop's setting (AccountContact):
                     an email, a phone, either, or both. A phone is confirmed
                     by a code right here — it is what signs in with it. --}}
                @php
                    $mark = fn (string $kind) => $contact->requires($kind) ? ' *' : '';
                @endphp
                @if ($contact->eitherWillDo())
                    <p class="text-sm opacity-70">{{ __('An email or a phone — either will do.') }}</p>
                @endif
                @if ($contact->asks('phone'))
                    <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Phone') }}{{ $mark('phone') }}</span>
                        <input type="tel" name="phone" value="{{ old('phone') }}" @required($contact->requires('phone')) autocomplete="tel" class="input input-bordered w-full">
                    </label>
                    <x-contact-verify form="account.phone" :values="['phone' => old('phone')]" />
                @endif
                <label class="fieldset p-0"><span class="fieldset-legend">Email{{ $mark('email') }}</span>
                    <input type="email" name="email" value="{{ old('email') }}" @required($contact->requires('email')) class="input input-bordered w-full">
                    @if ($contact->mode() === 'phone')
                        <span class="label text-xs opacity-60">{{ __('Optional — without it you simply get no letters from the shop.') }}</span>
                    @endif
                </label>
                <x-contact-verify form="account.register" :values="['email' => old('email')]" />
                <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Password') }} *</span>
                    <input type="password" name="password" required autocomplete="new-password" class="input input-bordered w-full">
                </label>
                <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Confirm password') }} *</span>
                    <input type="password" name="password_confirmation" required autocomplete="new-password" class="input input-bordered w-full">
                </label>

                <button class="btn btn-primary w-full">{{ __('Create account') }}</button>
                <p class="text-sm text-center opacity-70">
                    {{ __('Already have an account?') }}
                    <a href="{{ route('account.login') }}" class="link link-primary">{{ __('Sign in') }}</a>
                </p>
            </div>
        </form>
    </div>
@endsection
