@extends('layouts.storefront')

@section('title', __('Subscription').' · '.current_storefront()?->name)

{{-- The way out of the letters: a page with a button (a scanner opening the
     link must not unsubscribe anybody), no sign-in, no questions. --}}
@section('content')
    <div class="max-w-lg mx-auto my-10 bg-base-100 border border-base-300 rounded-box p-8 text-center flex flex-col items-center gap-4">
        <x-icon :name="$done ? 'mail-x' : 'mail'" size="xl" class="opacity-60" />
        @if ($done)
            <h1 class="text-xl font-bold">{{ __('You are unsubscribed.') }}</h1>
            <p class="opacity-70">{{ __('No more news and offers will come to :email. Letters about your orders still will.', ['email' => $subscriber->email]) }}</p>
            <a href="{{ route('catalog.home') }}" class="btn">{{ __('Back to the shop') }}</a>
        @else
            <h1 class="text-xl font-bold">{{ __('Unsubscribe from news and offers?') }}</h1>
            <p class="opacity-70">{{ $subscriber->email }}</p>
            <form method="POST" action="{{ route('newsletter.unsubscribe.do', $subscriber->token) }}">
                @csrf
                @if (preg_match('/^[A-Za-z0-9]{40}$/', (string) request('d')))
                    <input type="hidden" name="d" value="{{ request('d') }}">
                @endif
                <button class="btn btn-lg">{{ __('Unsubscribe') }}</button>
            </form>
        @endif
    </div>
@endsection
