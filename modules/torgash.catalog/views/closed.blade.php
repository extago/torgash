@extends('layouts.storefront')

{{-- A product or category closed to this visitor's group (PLAN §46), in the
     shop's `notice` mode: its name — a product's photo too — and who may see
     it, with the way in. No price, no buy button, no facts, and noindex: the
     page says it exists and nothing a price list would. --}}
@section('title', $name)

@section('content')
    <div class="max-w-xl mx-auto py-10">
        <div class="card bg-base-100 border border-base-300/60 shadow-sm">
            <div class="card-body items-center text-center gap-4">
                @if ($product && ($photo = $product->primaryImageUrl(400)))
                    <img src="{{ $photo }}" alt="{{ $name }}" class="w-40 h-40 object-contain rounded-box">
                @else
                    <span class="w-16 h-16 rounded-full bg-base-200 grid place-items-center"><x-icon name="lock" size="lg" /></span>
                @endif

                <h1 class="text-xl font-bold">{{ $name }}</h1>

                <p class="opacity-80">
                    @if ($who !== '')
                        {{ __('Available to :who.', ['who' => $who]) }}
                    @else
                        {{ __('Available to some of our customers only.') }}
                    @endif
                </p>

                @guest
                    <a href="{{ route('account.login') }}" class="btn btn-primary gap-2">
                        <x-icon name="log-in" size="sm" /> {{ __('Sign in') }}
                    </a>
                @else
                    <p class="text-sm opacity-70">{{ __('Your account does not open this range. Ask us if it should.') }}</p>
                @endguest
            </div>
        </div>
    </div>
@endsection
