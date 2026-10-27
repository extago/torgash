@extends('layouts.storefront')

@section('title', __('Subscription').' · '.current_storefront()?->name)

{{-- The letter's link: a page with a button, never a GET that acts — a mail
     service's scanner opens every link in a letter by itself. --}}
@section('content')
    <div class="max-w-lg mx-auto my-10 bg-base-100 border border-base-300 rounded-box p-8 text-center flex flex-col items-center gap-4">
        <x-icon :name="$done ? 'mail-check' : 'mail'" size="xl" class="text-primary" />
        @if ($done)
            <h1 class="text-xl font-bold">{{ __('You are subscribed. Thank you!') }}</h1>
            <p class="opacity-70">{{ __('Letters will come to :email. Every one of them has a link to unsubscribe.', ['email' => $subscriber->email]) }}</p>
            <a href="{{ route('catalog.home') }}" class="btn btn-primary">{{ __('Back to the shop') }}</a>
        @else
            <h1 class="text-xl font-bold">{{ __('Confirm the subscription') }}</h1>
            <p class="opacity-70">{{ __('News and offers to :email.', ['email' => $subscriber->email]) }}</p>
            <form method="POST" action="{{ route('newsletter.confirm.do', $subscriber->token) }}">
                @csrf
                <button class="btn btn-primary btn-lg">{{ __('Confirm the subscription') }}</button>
            </form>
        @endif
    </div>
@endsection
