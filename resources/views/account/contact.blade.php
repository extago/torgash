@extends('layouts.storefront')

@php
    $heading = $kind === 'email' ? __('Change the email') : ($user->phone ? __('Change the phone') : __('Add a phone'));
    $current = $kind === 'email' ? $user->email : \App\Core\Support\Phones::display($user->phone);
@endphp

@section('title', $heading.' · '.current_storefront()?->name)

{{--
    A new email or phone (App\Http\Controllers\Account\ContactController),
    after the owner's mockups: the current one, the new one, and the plain
    promise that a code goes to the NEW one.
--}}
@section('content')
    <div class="max-w-md mx-auto">
        <form method="POST" action="{{ route('account.contact.store', $kind) }}" class="card bg-base-100 shadow-sm border border-base-300/60">
            @csrf
            <div class="card-body gap-4">
                <h1 class="text-xl font-bold">{{ $heading }}</h1>

                @if ($current)
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ $kind === 'email' ? __('Current email') : __('Current phone') }}</legend>
                        <div class="input input-bordered w-full bg-base-200 font-semibold gap-2">
                            <x-icon :name="$kind === 'email' ? 'mail' : 'phone'" size="sm" class="opacity-50" /> {{ $current }}
                        </div>
                    </fieldset>
                @endif

                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ $kind === 'email' ? __('New email') : __('New phone') }} *</legend>
                    <label @class(['input input-bordered w-full gap-2', 'input-error' => $errors->has('address')])>
                        <x-icon :name="$kind === 'email' ? 'mail' : 'phone'" size="sm" class="opacity-50" />
                        <input name="address" value="{{ old('address') }}" required autofocus class="grow"
                               type="{{ $kind === 'email' ? 'email' : 'tel' }}" autocomplete="{{ $kind === 'email' ? 'email' : 'tel' }}"
                               placeholder="{{ $kind === 'email' ? 'name@example.com' : \App\Core\Support\Phones::callingCode($country).' …' }}">
                    </label>
                    @error('address')
                        <div class="flex items-start gap-2 text-sm text-warning-content mt-1">
                            <x-icon name="triangle-alert" size="sm" class="text-warning shrink-0 mt-0.5" /> <span>{{ $message }}</span>
                        </div>
                    @enderror
                </fieldset>

                <div class="rounded-box bg-warning/10 p-4 flex flex-col gap-3">
                    <div class="flex items-start gap-3 text-sm">
                        <x-icon name="info" class="text-warning shrink-0" />
                        <span>{{ $kind === 'email'
                            ? __('To use another email, type the code we send to it.')
                            : __('To use this phone, type the code we send to it.') }}</span>
                    </div>
                    <button class="btn btn-warning w-full">{{ $kind === 'email' ? __('Send the code to the email') : __('Send the code to the phone') }}</button>
                </div>

                <a href="{{ route('account.dashboard') }}" class="text-sm text-center link opacity-70">{{ __('Back to the account') }}</a>
            </div>
        </form>
    </div>
@endsection
