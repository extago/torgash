{{-- What the letter's link opens: the question and one button. The signature
     stays on the address, which is what the button posts to. --}}
@extends('layouts.storefront')

@section('content')
    <div class="max-w-lg mx-auto card bg-base-100 border border-base-300/60 shadow-sm mt-6">
        <div class="card-body items-center text-center gap-3 py-10">
            <x-icon name="mail-x" size="xl" class="opacity-40" />
            <h1 class="text-xl font-bold">
                @switch ($what)
                    @case ('price') {{ __('Stop the letters about price drops?') }} @break
                    @case ('stock') {{ __('Stop the letters about returns?') }} @break
                    @default {{ __('Stop the letters about your wishlist?') }}
                @endswitch
            </h1>
            <form method="POST" action="{{ request()->fullUrl() }}">
                @csrf
                <button type="submit" class="btn btn-primary">{{ __('Stop these letters') }}</button>
            </form>
        </div>
    </div>
@endsection
