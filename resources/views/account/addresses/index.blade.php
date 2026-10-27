@extends('layouts.storefront')

@section('title', __('Addresses').' · '.current_storefront()?->name)

@section('content')
    <div class="flex flex-col md:flex-row gap-6">
        @include('account._nav')

        <div class="flex flex-col gap-4 flex-1">
            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            <div class="flex justify-between items-center">
                <h1 class="text-xl font-bold">{{ __('Addresses') }}</h1>
                <a href="{{ route('account.addresses.create') }}" class="btn btn-primary btn-sm gap-1">
                    <x-icon name="plus" /> {{ __('Add address') }}
                </a>
            </div>

            @if ($addresses->isEmpty())
                <div class="card bg-base-100 shadow-sm border border-base-300/60">
                    <div class="card-body text-center opacity-60 py-10">
                        {{ __("You haven't saved any addresses yet.") }}
                    </div>
                </div>
            @else
                <div class="grid md:grid-cols-2 gap-4">
                    @foreach ($addresses as $address)
                        <div class="card bg-base-100 shadow-sm border border-base-300/60">
                            <div class="card-body gap-2">
                                <div class="flex justify-between items-start">
                                    <div class="font-semibold">{{ $address->label ?: __('Address') }}</div>
                                    @if ($address->is_default)
                                        <span class="badge badge-primary badge-sm">{{ __('Default') }}</span>
                                    @endif
                                </div>
                                <div class="text-sm opacity-80">
                                    {{ $address->full_name }}<br>
                                    {{ $address->address_line }}, {{ $address->city }}
                                    @if ($address->postal_code) {{ $address->postal_code }} @endif
                                    @if ($address->phone)<br>{{ $address->phone }}@endif
                                </div>
                                <div class="card-actions pt-2 border-t border-base-300 mt-2">
                                    <a href="{{ route('account.addresses.edit', $address) }}" class="btn btn-ghost btn-sm">{{ __('Edit') }}</a>
                                    @unless ($address->is_default)
                                        <form method="POST" action="{{ route('account.addresses.default', $address) }}">
                                            @csrf @method('PATCH')
                                            <button class="btn btn-ghost btn-sm">{{ __('Make default') }}</button>
                                        </form>
                                    @endunless
                                    <form method="POST" action="{{ route('account.addresses.destroy', $address) }}"
                                          data-confirm="{{ __('Remove this address?') }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-ghost btn-sm text-error">{{ __('Delete') }}</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
