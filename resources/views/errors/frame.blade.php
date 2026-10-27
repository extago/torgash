{{-- The shop's own error page (audit 2026-10-15: the framework's was shown).

     In the storefront's clothes — header, menu, search, footer — whenever
     the request came through the storefront: someone who followed a dead
     link is still in the shop and should be able to go on from here. Without
     them for a server error (drawing the header asks the very database that
     may have failed), in the admin, and for a request that never got a
     session. Each code's view passes `code`, `title`, `text` and `actions`. --}}
@php
    $errorFull = ! in_array($code, [500, 503], true)
        && ! request()->is('admin', 'admin/*')
        && request()->hasSession()
        && (function () {
            // A closed storefront's header and menu are the shop it is not
            // showing yet (ClosedStorefront), so its 404 goes without them.
            try {
                return current_storefront() !== null && ! current_storefront()->isClosedFor(request());
            } catch (\Throwable) {
                return false;
            }
        })();

    if ($errorFull) {
        page_meta()->set(['title' => $title, 'robots' => 'noindex']);
    }

    $errorActions = $actions ?? ['home', 'back'];
@endphp

@extends($errorFull ? 'layouts.storefront' : 'errors.minimal')

@section('error_title', $title)

@section('content')
    <div class="max-w-xl mx-auto w-full text-center py-16 px-4 flex flex-col items-center gap-4">
        <p class="text-6xl font-bold opacity-20 tabular-nums" aria-hidden="true">{{ $code }}</p>
        <h1 class="text-2xl font-semibold">{{ $title }}</h1>
        <p class="opacity-70">{{ $text }}</p>

        @if ($errorFull && in_array('search', $errorActions, true) && \Illuminate\Support\Facades\Route::has('catalog.search'))
            <form method="GET" action="{{ route('catalog.search') }}" class="flex gap-2 w-full max-w-sm mt-2" role="search">
                <input type="search" name="q" class="input grow" placeholder="{{ __('Search the shop') }}" aria-label="{{ __('Search the shop') }}">
                <button class="btn btn-primary">{{ __('Find') }}</button>
            </form>
        @endif

        {{-- Links, not script: they work with JavaScript off and under a
             content security policy that allows no inline handlers. "Back"
             is where the browser says it came from, when it is this shop. --}}
        @php
            $errorHome = request()->is('admin', 'admin/*') ? url('/admin') : url('/');
            $errorBack = url()->previous();
            // The same address is "back" only after a form: a form posts to the page it is on.
            $errorBack = (str_starts_with($errorBack, request()->getSchemeAndHttpHost().'/') || $errorBack === request()->getSchemeAndHttpHost())
                && ($errorBack !== request()->fullUrl() || ! request()->isMethod('GET')) ? $errorBack : null;
        @endphp
        <div class="flex flex-wrap justify-center gap-2 mt-2">
            @if (in_array('form', $errorActions, true))
                <a href="{{ $errorBack ?? $errorHome }}" class="btn btn-primary">{{ __('Back to the form') }}</a>
            @endif
            @if (in_array('retry', $errorActions, true))
                <a href="{{ request()->isMethod('GET') ? request()->fullUrl() : ($errorBack ?? $errorHome) }}" class="btn btn-primary">{{ __('Try again') }}</a>
            @endif
            @if (in_array('back', $errorActions, true) && $errorBack)
                <a href="{{ $errorBack }}" class="btn">{{ __('Go back') }}</a>
            @endif
            @if (in_array('home', $errorActions, true))
                <a href="{{ $errorHome }}" class="btn {{ in_array('form', $errorActions, true) || in_array('retry', $errorActions, true) ? '' : 'btn-primary' }}">
                    {{ request()->is('admin', 'admin/*') ? __('To the dashboard') : __('To the home page') }}
                </a>
            @endif
        </div>
    </div>
@endsection
