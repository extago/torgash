{{--
    "Deal with a countdown" — see PromotionsServiceProvider::blocks(). A sale's
    name, a line about it and a large timer on the left; a row of its products
    on the right. Which sale, and the minute the timer counts to, are
    Showcase::deal()'s — that is, the model's own countdownEndsAt(), the same
    minute the sale page counts to. Prints nothing when no sale is running
    with something to count and something to show. No background of its own:
    the band behind it is the block's section (zone screen), whose colour the
    text takes.
--}}
@php
    $dealShowcase = app(\Torgash\Promotions\Showcase::class);
    $deal = $dealShowcase->deal((string) ($settings['promotion'] ?? ''), max(2, min(24, (int) ($settings['limit'] ?? 10))));
@endphp
@if ($deal)
    @php
        $dealPromotion = $deal['promotion'];
        $dealHeading = trim((string) ($settings['heading'] ?? '')) ?: $dealPromotion->t('name');
        $dealText = trim((string) ($settings['text'] ?? '')) ?: $dealShowcase->teaser($dealPromotion);
        $dealUrl = $dealPromotion->published && $dealPromotion->slug ? route('promotions.show', $dealPromotion->slug) : null;
        $dealOffer = $dealShowcase->headline($dealPromotion);
    @endphp
    <section @class(['torgash-block torgash-block-deal', 'mb-10' => ! $inTabs])>
        <div class="torgash-deal">
            <div class="torgash-deal-lead">
                @if ($dealOffer)
                    <span class="torgash-deal-offer">{{ $dealOffer }}</span>
                @endif
                <h2 class="text-2xl font-bold leading-tight">{{ $dealHeading }}</h2>
                @if ($dealText !== '')
                    <p class="text-sm opacity-80 line-clamp-4">{{ $dealText }}</p>
                @endif
                <div class="flex flex-col gap-1.5">
                    <span class="text-xs font-semibold uppercase tracking-wide opacity-70">{{ __('Until the deal ends') }}</span>
                    @include('torgash.promotions::partials.countdown', ['until' => $deal['until'], 'label' => null, 'class' => '', 'countdownBoxes' => true])
                </div>
                <div class="flex flex-wrap gap-2 mt-auto pt-1">
                    @if ($dealUrl)
                        <a href="{{ $dealUrl }}" class="btn torgash-deal-cta">{{ __('Details') }} <x-icon name="arrow-right" size="sm" /></a>
                    @endif
                    <a href="{{ route('promotions.index') }}" class="btn btn-ghost">{{ __('All promotions') }}</a>
                </div>
            </div>
            <div class="min-w-0">
                @include('torgash.catalog::partials.product-carousel', [
                    'carouselProducts' => $deal['products'],
                    'carouselCols' => (int) ($settings['per_row'] ?? 4),
                    'carouselArrows' => $settings['arrows'] ?? 'side',
                    'carouselArrowsPhone' => $settings['arrows_phone'] ?? 'head',
                ])
            </div>
        </div>
    </section>
@endif
