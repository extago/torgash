@extends('layouts.storefront')

@section('title', ($address->exists ? __('Edit address') : __('Add address')).' · '.current_storefront()?->name)

@section('content')
    <div class="flex flex-col md:flex-row gap-6">
        @include('account._nav')

        <div class="flex-1 min-w-0 max-w-lg">
            <form method="POST"
                  action="{{ $address->exists ? route('account.addresses.update', $address) : route('account.addresses.store') }}"
                  class="card bg-base-100 shadow-sm border border-base-300/60">
                @csrf
                @if ($address->exists) @method('PUT') @endif

                <div class="card-body gap-4">
                    <h1 class="text-xl font-bold">{{ $address->exists ? __('Edit address') : __('Add address') }}</h1>

                    @if ($errors->any())
                        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
                    @endif

                    <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Address name') }}</span>
                        <input name="label" value="{{ old('label', $address->label) }}" class="input input-bordered w-full" placeholder="{{ __('e.g. Home, Work') }}">
                    </label>
                    <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Full name') }} *</span>
                        <input name="full_name" value="{{ old('full_name', $address->full_name) }}" required class="input input-bordered w-full">
                    </label>
                    <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Phone') }}</span>
                        <input name="phone" value="{{ old('phone', $address->phone) }}" class="input input-bordered w-full">
                    </label>
                    <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Address') }} *</span>
                        <input name="address_line" value="{{ old('address_line', $address->address_line) }}" required class="input input-bordered w-full">
                    </label>
                    <div class="grid grid-cols-2 gap-4">
                        <label class="fieldset p-0"><span class="fieldset-legend">{{ __('City') }} *</span>
                            <input name="city" value="{{ old('city', $address->city) }}" required class="input input-bordered w-full">
                        </label>
                        <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Postal code') }}</span>
                            <input name="postal_code" value="{{ old('postal_code', $address->postal_code) }}" class="input input-bordered w-full">
                        </label>
                    </div>

                    <label class="label cursor-pointer justify-start gap-2">
                        <input type="checkbox" name="is_default" value="1" class="toggle toggle-primary"
                               @checked(old('is_default', $address->is_default))>
                        <span class="label-text">{{ __('Use as default address') }}</span>
                    </label>

                    <div class="card-actions pt-2 border-t border-base-300 mt-2">
                        <button class="btn btn-primary">{{ $address->exists ? __('Save') : __('Add address') }}</button>
                        <a href="{{ route('account.addresses.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection
