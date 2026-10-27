{{--
    One sale as a tile — the "Current promotions" block and the list of all
    sales draw the same one. Its banner, or — without one — the discount
    large on a colour of its own; then the name and how long is left. More
    than a day: the days, large. Less: the ticking timer. Between today's
    windows: when it opens. The times are the model's (countdownEndsAt(),
    opensAt()), as on the sale pages.

    Given: $promoCard (the Promotion), $promoHue (the colour when the sale
    has no tint of its own — by position, so neighbours differ). Optional:
    $promoTeaser (a line of the description under the name). Names are
    distinctive on purpose: an include inherits the caller's scope.
--}}
@php
    $promoShowcase = app(\Torgash\Promotions\Showcase::class);
    $promoStatus = $promoCard->status();
    $promoUntil = $promoStatus === 'running' && $promoCard->show_countdown ? $promoCard->countdownEndsAt() : null;
    $promoDays = $promoUntil ? (int) floor(now()->diffInDays($promoUntil, true)) : 0;
    $promoBanner = $promoCard->bannerUrl();
    $promoOffer = $promoShowcase->headline($promoCard);
    // A schedule the shop chose not to tell is not hinted at by the repeat icon either.
    $promoCyclic = $promoCard->show_schedule && ! $promoCard->scheduleWindows()->isEmpty();
    $promoLine = ($promoTeaser ?? false) ? $promoShowcase->teaser($promoCard) : '';
@endphp
<a href="{{ route('promotions.show', $promoCard->slug) }}" @class(['torgash-promo-card group', 'is-ended' => $promoStatus === 'ended'])
   style="--promo-hue: {{ $promoCard->tint ? $promoCard->hue() : $promoHue }}">
    <span class="torgash-promo-pic">
        @if ($promoBanner)
            <img src="{{ $promoBanner }}" alt="" loading="lazy" decoding="async">
            @if ($promoOffer)
                <span class="torgash-promo-chip">{{ $promoOffer }}</span>
            @endif
        @else
            <span class="torgash-promo-art">
                <span class="torgash-promo-offer">{{ $promoOffer ?? '%' }}</span>
                <x-icon :name="$promoCyclic ? 'repeat' : 'badge-percent'" class="torgash-promo-icon" />
            </span>
        @endif
        @if ($promoCyclic)
            <span class="torgash-promo-when">{{ $promoCard->describeSchedule() }}</span>
        @endif
    </span>

    {{-- The name first, how long is left after it — on every tile, whichever
         of the two kinds of time it shows (a narrow tile wraps the days box
         BELOW the name, never above it). --}}
    <span class="torgash-promo-body">
        <span class="torgash-promo-name flex flex-col gap-1 min-w-0">
            <span class="font-semibold leading-snug line-clamp-2 group-hover:text-primary">{{ $promoCard->t('name') }}</span>
            @if ($promoLine !== '')
                <span class="text-xs opacity-70 line-clamp-2">{{ $promoLine }}</span>
            @endif
            @if ($promoUntil && $promoDays < 1)
                @include('torgash.promotions::partials.countdown', ['until' => $promoUntil, 'label' => null, 'class' => 'text-xs'])
            @elseif ($promoStatus === 'paused' && ($promoOpens = $promoCard->opensAt()))
                <span class="text-xs opacity-70">{{ __('Opens :date', ['date' => $promoOpens->diffForHumans()]) }}</span>
            @elseif ($promoStatus === 'scheduled' && $promoCard->starts_at)
                <span class="text-xs opacity-70">{{ __('Opens :date', ['date' => $promoCard->starts_at->diffForHumans()]) }}</span>
            @elseif ($promoStatus === 'ended')
                <span class="text-xs opacity-70">{{ __('This sale has ended.') }}</span>
            @elseif ($promoUntil)
                <span class="text-xs opacity-70">{{ __('until :date', ['date' => $promoUntil->translatedFormat('j F')]) }}</span>
            @endif
        </span>
        @if ($promoUntil && $promoDays >= 1)
            <span class="torgash-promo-days">
                <b>{{ $promoDays }}</b>
                <small>{{ trans_choice('day left|days left', $promoDays) }}</small>
            </span>
        @endif
    </span>
</a>
