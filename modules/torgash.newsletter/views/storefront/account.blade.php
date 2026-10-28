@extends('layouts.storefront')

@section('title', __('Newsletter').' · '.current_storefront()?->name)

@section('content')
    <div class="flex flex-col md:flex-row gap-6">
        @include('account._nav')

        <div class="flex-1 min-w-0 flex flex-col gap-6">
            <h1 class="text-xl font-bold">{{ __('Newsletter') }}</h1>

            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('account.newsletter.save') }}" class="bg-base-100 border border-base-300 rounded-box p-6 flex flex-col gap-4">
                @csrf
                <input type="hidden" name="subscribed" value="0">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="subscribed" value="1" class="toggle toggle-primary mt-0.5" @checked($subscriber?->isSubscribed())>
                    <span>
                        <span class="font-medium">{{ __('News and offers by email') }}</span>
                        <span class="block text-sm opacity-70">{{ __('To :email. Letters about your orders come either way.', ['email' => auth()->user()->email]) }}</span>
                    </span>
                </label>
                @if ($subscriber && $subscriber->status === \Torgash\Newsletter\Models\Subscriber::PENDING)
                    <p class="text-sm opacity-70">{{ __('Waiting for the confirmation: follow the link in the letter we sent.') }}</p>
                @endif
                <div><button class="btn btn-primary">{{ __('Save') }}</button></div>
            </form>
        </div>
    </div>
@endsection
