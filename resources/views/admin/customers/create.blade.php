@extends('admin.layout')

@section('title', __('New customer'))
@section('own_heading', '1')

@section('content')
    <x-admin.form-bar>
        <a href="{{ route('admin.customers.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to customers') }}" aria-label="{{ __('Back to customers') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none">{{ __('Customer') }}</div>
            <div class="font-semibold truncate leading-tight">{{ __('New customer') }}</div>
        </div>
        <div class="dropdown dropdown-end">
            <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                <x-icon name="settings" size="lg" />
            </div>
            <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                <li><button type="submit" form="customer-form" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
            </ul>
        </div>
        <x-slot:actions>
            <a href="{{ route('admin.customers.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button type="submit" form="customer-form" class="btn btn-primary btn-sm">{{ __('Save') }}</button>
        </x-slot:actions>
    </x-admin.form-bar>

    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
        <form method="POST" id="customer-form" action="{{ route('admin.customers.store') }}" class="card bg-base-100 border border-base-300 xl:order-2">
            @csrf
            <div class="card-body gap-3">
                <h3 class="font-semibold">{{ __('Account') }}</h3>
                @include('admin.customers._account', ['customer' => $customer, 'groups' => $groups])
                {{-- They choose their own password from a letter (PasswordLinks). --}}
                <label class="label cursor-pointer justify-start gap-3">
                    <input type="checkbox" name="send_invite" value="1" class="checkbox checkbox-sm" @checked(old('send_invite'))>
                    <span class="label-text">{{ __('Send them a letter to choose their own password') }}</span>
                </label>
            </div>
        </form>

        <section class="card bg-base-100 border border-base-300 xl:order-1">
            <div class="card-body gap-2 text-sm">
                <h3 class="font-semibold flex items-center gap-2"><x-icon name="info" size="sm" /> {{ __('An account made by the shop') }}</h3>
                <p class="opacity-70 max-w-2xl">
                    {{ __('For a customer who orders by phone or is a wholesale client: put them in a group and they see its prices as soon as they sign in. Orders they place signed in are listed on their card.') }}
                </p>
                <p class="opacity-70 max-w-2xl">
                    {{ __('Tick the letter and the customer chooses their own password from a link in it; or type a password and tell it to them yourself.') }}
                </p>
            </div>
        </section>
    </div>
@endsection
