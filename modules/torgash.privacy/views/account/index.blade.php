{{-- "My data" (PLAN §49): download what the shop keeps about me; ask for it
     to be erased; choose the cookies again. --}}
@extends('layouts.storefront')

@section('title', __('My data').' · '.current_storefront()?->name)

@section('content')
    <div class="flex flex-col md:flex-row gap-6">
        @include('account._nav')

        <div class="flex-1 min-w-0 flex flex-col gap-4 max-w-3xl">
            <h1 class="text-xl font-bold">{{ __('My data') }}</h1>

            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            <section class="card bg-base-100 shadow-sm border border-base-300/60">
                <div class="card-body gap-3">
                    <h2 class="font-semibold">{{ __('Download my data') }}</h2>
                    <p class="text-sm opacity-70">{{ __('Everything the shop keeps about your account — your details, addresses, orders, enquiries, reviews, consents — in one file you can open or take elsewhere.') }}</p>
                    <div><a href="{{ route('account.privacy.download') }}" class="btn btn-sm gap-1.5"><x-icon name="download" size="sm" /> {{ __('Download') }}</a></div>
                </div>
            </section>

            <section class="card bg-base-100 shadow-sm border border-base-300/60">
                <div class="card-body gap-3">
                    <h2 class="font-semibold">{{ __('Cookies') }}</h2>
                    <p class="text-sm opacity-70">{{ __('Which counters and advertising code may run while you browse the shop.') }}</p>
                    <div><a href="#cookie-settings" class="btn btn-sm gap-1.5"><x-icon name="cookie" size="sm" /> {{ __('Cookie settings') }}</a></div>
                </div>
            </section>

            <section class="card bg-base-100 shadow-sm border border-base-300/60">
                <div class="card-body gap-3">
                    <h2 class="font-semibold">{{ __('Erase my data') }}</h2>
                    @if ($open)
                        <div class="alert alert-info text-sm">
                            {{ __('Your request of :date is being handled. It will be done by :due.', ['date' => $open->created_at->translatedFormat('j F Y'), 'due' => $open->due_at?->translatedFormat('j F Y')]) }}
                        </div>
                    @else
                        <p class="text-sm opacity-70">{{ __('Your account, addresses, wishlist and enquiries are erased, and your name is taken off your reviews. Orders stay — the law asks a shop to keep them for its accounts — but without your IP address and browser. The account cannot be restored afterwards.') }}</p>
                        {{-- The password, so that neither a slip of the finger nor
                             somebody holding a signed-in phone closes the account. A
                             customer who never chose one gets one by a code. --}}
                        <form method="POST" action="{{ route('account.privacy.erase') }}" class="flex flex-col gap-3"
                              data-confirm="{{ __('Ask the shop to erase your data? Your account will be closed once it is done.') }}">
                            @csrf
                            <textarea name="note" rows="2" class="textarea w-full" placeholder="{{ __('Anything we should know (optional)') }}">{{ old('note') }}</textarea>
                            <label class="fieldset p-0">
                                <span class="fieldset-legend">{{ __('Your password') }}</span>
                                <input type="password" name="current_password" required autocomplete="current-password"
                                       @class(['input w-full sm:w-72', 'input-error' => $errors->has('current_password')])>
                                @error('current_password')
                                    <span class="text-error text-xs">{{ $message }}</span>
                                @enderror
                                <span class="text-xs opacity-60">{{ __('Do not know it? Sign out and choose "Forgot your password?" when signing in — a code brings you back with a new one.') }}</span>
                            </label>
                            <div><button class="btn btn-error btn-outline btn-sm">{{ __('Ask to erase my data') }}</button></div>
                        </form>
                    @endif
                </div>
            </section>

            @if ($requests->isNotEmpty())
                <section class="card bg-base-100 shadow-sm border border-base-300/60">
                    <div class="card-body gap-2">
                        <h2 class="font-semibold">{{ __('My requests') }}</h2>
                        <ul class="text-sm flex flex-col gap-1">
                            @foreach ($requests as $myRequest)
                                <li class="flex justify-between gap-3">
                                    <span>{{ $myRequest->typeLabel() }} · {{ $myRequest->created_at->translatedFormat('j F Y') }}</span>
                                    <span class="opacity-70">{{ $myRequest->statusLabel() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </section>
            @endif
        </div>
    </div>
@endsection
