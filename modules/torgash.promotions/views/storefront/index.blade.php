@extends('layouts.storefront')

@section('title', __('Promotions').' · '.current_storefront()?->name)

{{--
    Every sale with a page, as the tiles the "Current promotions" block draws
    (partials/promo-card) — in three groups, in the order a visitor can act on
    them: what is on now, what opens soon (between today's windows, or not
    started yet), and what has ended (a page outlives its sale; the tile says
    so and is faded). The colours run on across the groups, so no two
    neighbours share one.
--}}
@section('content')
    <x-breadcrumbs />

    <h1 class="text-2xl font-bold mb-1">{{ __('Promotions') }}</h1>
    <div class="text-sm opacity-60 mb-5">{{ __('Everything the shop is running right now.') }}</div>

    @if ($promotions->isEmpty())
        <div class="bg-base-100 border border-base-300 rounded-box p-6 text-center opacity-60">
            {{ __('No sales are running at the moment.') }}
        </div>
    @else
        @php
            $promoHues = [25, 250, 150, 340, 205, 80, 295, 175];
            $promoGroups = [
                __('On now') => $promotions->filter(fn ($p) => $p->status() === 'running'),
                __('Coming soon') => $promotions->filter(fn ($p) => in_array($p->status(), ['paused', 'scheduled'], true)),
                __('Sales that have ended') => $promotions->filter(fn ($p) => $p->status() === 'ended'),
            ];
            $promoAt = 0;
        @endphp
        @foreach ($promoGroups as $promoGroupTitle => $promoGroup)
            @continue($promoGroup->isEmpty())
            <section class="mb-8">
                <h2 class="text-lg font-semibold mb-3">{{ $promoGroupTitle }} <span class="opacity-50 font-normal">{{ $promoGroup->count() }}</span></h2>
                <div class="torgash-promos-track grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                    @foreach ($promoGroup as $promoCard)
                        @include('torgash.promotions::partials.promo-card', [
                            'promoCard' => $promoCard,
                            'promoHue' => $promoHues[$promoAt++ % count($promoHues)],
                            'promoTeaser' => true,
                        ])
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
@endsection
