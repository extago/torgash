@extends('admin.layout')

@section('title', $customer->name)
@section('own_heading', '1')

@section('content')
    <x-admin.form-bar>
        <a href="{{ route('admin.customers.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to customers') }}" aria-label="{{ __('Back to customers') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div class="avatar avatar-placeholder shrink-0">
            <div class="bg-primary/15 text-primary w-9 rounded-full">
                <span class="text-sm font-semibold">{{ mb_strtoupper(mb_substr($customer->name, 0, 1)) }}</span>
            </div>
        </div>
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none">{{ __('Customer') }}</div>
            <div class="font-semibold truncate leading-tight">{{ $customer->name }}</div>
        </div>
        <div class="dropdown dropdown-end">
            <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                <x-icon name="settings" size="lg" />
            </div>
            <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                <li><button type="submit" form="customer-form" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                @if ($customer->email)
                    <li><a href="mailto:{{ $customer->email }}"><x-icon name="mail" /> {{ __('Write an email') }}</a></li>
                @endif
                @if ($customer->phone)
                    <li><a href="tel:{{ $customer->phone }}"><x-icon name="phone" /> {{ __('Call') }}</a></li>
                @endif
                <li class="menu-title p-0 my-1"><span class="h-px bg-base-300 block"></span></li>
                <li><button type="button" class="text-error" data-open-dialog="customer-delete"><x-icon name="trash-2" /> {{ __('Delete the customer') }}</button></li>
            </ul>
        </div>
        <x-slot:actions>
            <a href="{{ route('admin.customers.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button type="submit" form="customer-form" class="btn btn-primary btn-sm">{{ __('Save') }}</button>
        </x-slot:actions>
    </x-admin.form-bar>

    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
        <div class="flex flex-col gap-4 min-w-0">
            {{-- What modules know about this customer: their orders, and
                 whatever else answers core.admin.customer.panels. --}}
            @foreach ($panels as $panel)
                {!! $panel['html'] !!}
            @endforeach

            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold flex items-center gap-2"><x-icon name="map-pin" size="sm" /> {{ __('Addresses') }}</h3>
                    @if ($customer->addresses->isEmpty())
                        <p class="text-sm opacity-60">{{ __('No saved addresses.') }}</p>
                    @else
                        <div class="grid md:grid-cols-2 gap-3">
                            @foreach ($customer->addresses as $address)
                                <div @class(['rounded-box border p-3 text-sm flex flex-col gap-0.5', 'border-primary/50' => $address->is_default, 'border-base-300' => ! $address->is_default])>
                                    <div class="flex justify-between gap-2">
                                        <span class="font-medium">{{ $address->label ?: __('Address') }}</span>
                                        @if ($address->is_default)
                                            <span class="badge badge-primary badge-sm">{{ __('Default') }}</span>
                                        @endif
                                    </div>
                                    @if ($address->full_name)<div>{{ $address->full_name }}</div>@endif
                                    <div class="opacity-70">{{ collect([$address->address_line, $address->city, $address->postal_code])->filter()->implode(', ') }}</div>
                                    @if ($address->phone)
                                        <a href="tel:{{ preg_replace('/[^\d+]/', '', $address->phone) }}" class="link link-hover opacity-70 w-fit">{{ $address->phone }}</a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        </div>

        <div class="flex flex-col gap-4">
            <form method="POST" id="customer-form" action="{{ route('admin.customers.update', $customer) }}" class="card bg-base-100 border border-base-300">
                @csrf @method('PUT')
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Account') }}</h3>

                    @include('admin.customers._account', ['customer' => $customer, 'groups' => $groups])
                </div>
            </form>

            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-2 text-sm">
                    <h3 class="font-semibold">{{ __('About the customer') }}</h3>
                    <div class="flex justify-between gap-2">
                        <span class="opacity-60">{{ __('Registered') }}</span>
                        <span title="{{ $customer->created_at }}">{{ $customer->created_at->format('d.m.Y') }}</span>
                    </div>
                    @foreach ($facts as $fact)
                        <div class="flex justify-between gap-2">
                            <span class="opacity-60">{{ $fact['label'] }}</span>
                            <span class="font-semibold tabular-nums text-end">{{ $fact['value'] ?? '' }}</span>
                        </div>
                    @endforeach
                    @if ($customer->external_id)
                        <div class="flex justify-between gap-2">
                            <span class="opacity-60">{{ __('External ID') }}</span>
                            <span class="font-mono text-xs break-all text-end">{{ $customer->external_id }}</span>
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>

    {{-- Outside the account form: a form inside a form leaks its _method. --}}
    <x-modal name="customer-delete" :title="__('Delete :name?', ['name' => $customer->name])" icon="trash-2">
        <p class="text-sm">
            {{ __('The account is deleted and cannot be signed in to again. What they left in the shop:') }}
        </p>
        @if ($deleteNotes)
            <ul class="text-sm flex flex-col gap-1.5 mt-3">
                @foreach ($deleteNotes as $note)
                    <li class="flex items-start gap-2">
                        @if (! empty($note['deleted']))
                            <x-icon name="trash-2" size="sm" class="text-error opacity-70 mt-0.5 shrink-0" />
                        @else
                            <x-icon name="check" size="sm" class="text-success mt-0.5 shrink-0" />
                        @endif
                        <span>{{ $note['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-sm opacity-60 mt-3">{{ __('Nothing — the account holds nothing else.') }}</p>
        @endif

        <x-slot:footer>
            <form method="POST" action="{{ route('admin.customers.destroy', $customer) }}">
                @csrf @method('DELETE')
                <button class="btn btn-ghost btn-sm text-error">{{ __('Delete the customer') }}</button>
            </form>
        </x-slot:footer>
    </x-modal>
@endsection
