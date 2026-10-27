{{--
    "Current promotions" — see PromotionsServiceProvider::blocks(). A card per
    sale with a page: its banner, or — without one — the discount large on a
    colour of its own (by position, like the category tiles, so neighbours
    differ); then the name and how long is left. More than a day: the days,
    large. Less: the ticking timer. Between today's windows: when it opens.
    The times are the model's (countdownEndsAt(), opensAt()), as on the sale
    pages. Prints nothing when no sale has a page. The band behind the cards
    is the block's section (zone screen), never the block's own.
--}}
@php
    $promoShowcase = app(\Torgash\Promotions\Showcase::class);
    $promoCards = $promoShowcase->current(max(1, min(20, (int) ($settings['limit'] ?? 5))), (bool) ($settings['recurring'] ?? true));
@endphp
@if ($promoCards->isNotEmpty())
    @php
        $promoHeading = trim((string) ($settings['heading'] ?? ''));
        $promoCols = ['3' => 'lg:grid-cols-3', '4' => 'lg:grid-cols-4', '5' => 'lg:grid-cols-5'][(string) ($settings['per_row'] ?? '5')] ?? 'lg:grid-cols-5';
        $promoHues = [25, 250, 150, 340, 205, 80, 295, 175];
    @endphp
    <section @class(['torgash-block torgash-block-promotion-cards', 'mb-10' => ! $inTabs])>
        @if (! $inTabs && $promoHeading !== '')
            <h2 class="text-2xl font-bold text-center mb-5">{{ $promoHeading }}</h2>
        @endif

        <div class="torgash-promos-track grid-cols-2 md:grid-cols-3 {{ $promoCols }}">
                @foreach ($promoCards as $promoCard)
                    @include('torgash.promotions::partials.promo-card', ['promoCard' => $promoCard, 'promoHue' => $promoHues[$loop->index % count($promoHues)], 'promoTeaser' => false])
                @endforeach
            </div>

        <div class="text-center mt-5">
            <a href="{{ route('promotions.index') }}" class="btn torgash-deal-cta">{{ __('All promotions') }} <x-icon name="arrow-right" size="sm" /></a>
        </div>
    </section>
@endif
