@extends('layouts.storefront')

@section('title', __('Security').' · '.current_storefront()?->name)

{{--
    The customer's own second factor, beside their addresses and their orders.

    The same card as the staff screen and the same code behind it — what differs
    is the theme it is drawn in and that the shop may not be offering this at
    all, in which case the route answers 404 and the menu item does not exist to
    be clicked.
--}}

@section('content')
    <div class="flex flex-col md:flex-row gap-6">
        @include('account._nav')

        <div class="flex-1 flex flex-col gap-6">
            <h1 class="text-xl font-bold">{{ __('Security') }}</h1>

            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert alert-error text-sm">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @include('torgash.guard::two-factor._panel')
        </div>
    </div>
@endsection
