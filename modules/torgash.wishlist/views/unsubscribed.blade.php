{{-- Answered to "stop these letters" from inside one: done, said plainly,
     with the way back to switch them on again. --}}
@extends('layouts.storefront')

@section('content')
    <div class="max-w-lg mx-auto card bg-base-100 border border-base-300/60 shadow-sm mt-6">
        <div class="card-body items-center text-center gap-3 py-10">
            <x-icon name="mail-x" size="xl" class="opacity-40" />
            <h1 class="text-xl font-bold">
                @switch ($what)
                    @case ('price') {{ __('No more letters about price drops.') }} @break
                    @case ('stock') {{ __('No more letters about returns.') }} @break
                    @default {{ __('No more letters about your wishlist.') }}
                @endswitch
            </h1>
            <p class="opacity-70 text-sm">{{ __('You can turn them on again on the wishlist page.') }}</p>
            <a href="{{ route('wishlist.index') }}" class="btn btn-primary">{{ __('Open the wishlist') }}</a>
        </div>
    </div>
@endsection
