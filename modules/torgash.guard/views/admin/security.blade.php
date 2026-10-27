@extends('admin.layout')

@section('title', __('Security'))

{{--
    A manager's own second factor.

    Not on the admin menu and not under `guard.manage`: this is a page about the
    person looking, reached from the account dropdown in the corner through
    core's `core.admin.user_nav` filter. Permission to read what the guard
    records is a different thing from being able to secure your own account, and
    the shop's least privileged member of staff is the one whose password is
    most likely to be reused somewhere else.
--}}

@section('content')
    <div class="max-w-3xl flex flex-col gap-6">
        <div>
            <h1 class="text-xl font-bold">{{ __('Security') }}</h1>
            <p class="text-sm opacity-60 mt-1">
                {{ __('Your own sign-in. Everything on this page is about this account and nobody else’s.') }}
            </p>
        </div>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">
                <ul class="list-disc list-inside text-sm">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @include('torgash.guard::two-factor._panel')

        {{-- The way back in, printed where the person who will need it is
             standing. A shop that reads this before losing a phone does not
             open a support ticket about it. --}}
        <div class="text-xs opacity-60">
            {{ __('Lost the phone and the recovery codes? Somebody with shell access can take the second factor off an account:') }}
            <code class="font-mono">php artisan guard:two-factor {{ auth()->user()->email }} --off</code>
        </div>
    </div>
@endsection
