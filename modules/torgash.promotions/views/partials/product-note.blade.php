{{--
    What a sale says on the product page, under the price: which sale this
    price comes from, how long it lasts, and the sale itself one click away.

    Rendered through catalog's own `catalog.product.price_extra` filter — the
    extension point that exists for exactly this ("a price valid until" note),
    so catalog keeps knowing nothing about promotions.

    Only sales that are running right now reach here (PromotionCache holds the
    open ones), so there is no "ended" case to draw.

    One panel per sale, in the sale's own colour (the hue the "Current
    promotions" cards use, picked by id so a sale keeps it from page to page):
    a tile with its banner or its offer large (`Showcase::headline()`), the
    name, and "About the sale" opening a popup — the banner, the period, the
    shop's description and the way to every product in it — rather than
    sending the buyer off the page they are about to buy from. Under it the
    time left as four cells with their words, and for a sale with both ends
    known a bar of how much of it has already gone: "22 days" says little, a
    bar two-thirds full says hurry. A sale that repeats (tonight 21:00–09:00)
    has no such bar — its windows are said in words in the popup.
--}}
@php $noteShowcase = app(\Torgash\Promotions\Showcase::class); @endphp
<div class="flex flex-col gap-2 mt-3">
    @foreach ($promotions as $promotion)
        @php
            $noteHue = $promotion->hue();
            $noteUntil = $promotion->show_countdown ? $promotion->countdownEndsAt() : null;
            $noteOffer = $noteShowcase->headline($promotion);
            $noteBanner = $promotion->bannerUrl();
            $noteBadge = $promotion->tile($noteOffer);
            $noteCyclic = $promotion->show_schedule && ! $promotion->scheduleWindows()->isEmpty();
            $notePage = $promotion->published && $promotion->slug ? route('promotions.show', $promotion->slug) : null;
            $noteAbout = trim((string) $promotion->t('description')) !== '' || $noteBanner || $noteCyclic || $promotion->ends_at;
            // How much of the sale has gone, 0–100 — only where both ends are
            // dates (a repeating window would restart the bar every night).
            $noteShare = null;
            if (! $noteCyclic && $promotion->starts_at && $promotion->ends_at && $promotion->ends_at->greaterThan($promotion->starts_at)) {
                $noteShare = (int) round(100 * $promotion->starts_at->diffInSeconds(now(), true) / $promotion->starts_at->diffInSeconds($promotion->ends_at, true));
                $noteShare = max(2, min(100, $noteShare));
            }
            // The period in words: "9 – 31 October", "until 31 October", or the windows.
            $notePeriod = match (true) {
                $promotion->starts_at && $promotion->ends_at => __(':from – :to', ['from' => $promotion->starts_at->isoFormat('D MMMM'), 'to' => $promotion->ends_at->isoFormat('D MMMM YYYY')]),
                (bool) $promotion->ends_at => __('Until :date', ['date' => $promotion->ends_at->isoFormat('D MMMM YYYY')]),
                default => null,
            };
            $noteModal = 'promo-'.$promotion->id;
        @endphp
        <div class="torgash-promo-note" style="--promo-hue: {{ $noteHue }}">
            <div class="flex items-center gap-3">
                @if ($noteBadge)
                    {{-- The shop's choice (Promotion::tile()): a picture, its
                         own words, an icon — or, by default, worked out. --}}
                    <span @class(['torgash-promo-note-tile', 'is-picture' => $noteBadge[0] === 'picture']) aria-hidden="true">
                        @if ($noteBadge[0] === 'picture')
                            <img src="{{ $noteBadge[1] }}" alt="" loading="lazy" decoding="async">
                        @elseif ($noteBadge[0] === 'text')
                            <b @class(['is-long' => mb_strlen($noteBadge[1]) > 4])>{{ $noteBadge[1] }}</b>
                        @else
                            <x-icon :name="$noteBadge[1]" size="lg" />
                        @endif
                    </span>
                @endif
                <div class="min-w-0 grow">
                    <div class="font-semibold leading-snug">{{ $promotion->t('name') }}</div>
                    @if ($noteAbout)
                        <button type="button" class="torgash-promo-note-more"
                                data-open-dialog="modal-{{ $noteModal }}">
                            {{ __('About the sale') }} <x-icon name="chevron-right" size="sm" />
                        </button>
                    @elseif ($notePage)
                        <a href="{{ $notePage }}" class="torgash-promo-note-more">{{ __('Every product in the sale') }} <x-icon name="chevron-right" size="sm" /></a>
                    @endif
                </div>
            </div>

            @if ($noteUntil)
                {{-- Under a dashed line: the words, the cells in the sale's
                     colour, and the bar of how much has gone. --}}
                <div class="torgash-promo-note-clock">
                    <div class="torgash-promo-note-time">
                        <span class="text-sm leading-tight"><x-icon name="timer" size="sm" class="torgash-promo-note-ink shrink-0" />{{ __('Until the sale ends') }}</span>
                        @include('torgash.promotions::partials.countdown', [
                            'until' => $noteUntil,
                            'countdownBoxes' => true,
                            'class' => 'ms-auto',
                        ])
                    </div>
                    @if ($noteShare !== null)
                        <div class="torgash-promo-note-bar" role="img" aria-label="{{ __(':share% of the sale has passed', ['share' => $noteShare]) }}">
                            <span style="width: {{ $noteShare }}%"></span>
                        </div>
                    @endif
                </div>
            @elseif ($notePeriod)
                <div class="text-sm opacity-75 mt-2">{{ $notePeriod }}</div>
            @endif
        </div>

        @if ($noteAbout)
            <x-modal :name="$noteModal" :title="$promotion->t('name')" icon="badge-percent" size="lg">
                <div class="flex flex-col gap-4" style="--promo-hue: {{ $noteHue }}">
                    @if ($noteBanner)
                        <img src="{{ $noteBanner }}" alt="{{ $promotion->t('name') }}" class="w-full max-h-64 object-cover rounded-box">
                    @elseif ($noteOffer)
                        <div class="torgash-promo-note-hero">
                            <b>{{ $noteOffer }}</b>
                            <x-icon :name="$noteCyclic ? 'repeat' : 'badge-percent'" class="torgash-promo-icon" />
                        </div>
                    @endif

                    @if ($notePeriod || $noteCyclic)
                        <div class="flex flex-col gap-1 text-sm">
                            @if ($notePeriod)
                                <span class="inline-flex items-center gap-2"><x-icon name="calendar" size="sm" class="opacity-60" /> {{ $notePeriod }}</span>
                            @endif
                            @if ($noteCyclic)
                                <span class="inline-flex items-center gap-2"><x-icon name="clock" size="sm" class="opacity-60" /> {{ $promotion->describeSchedule() }}</span>
                            @endif
                        </div>
                    @endif

                    @if (trim((string) $promotion->t('description')) !== '')
                        {{-- Admin-authored rich text, as on the sale's own page. --}}
                        <div class="torgash-rich">{{ safe_html($promotion->t('description')) }}</div>
                    @endif

                    @if ($notePage)
                        <div>
                            <a href="{{ $notePage }}" class="btn btn-primary">{{ __('Every product in the sale') }} <x-icon name="arrow-right" size="sm" /></a>
                        </div>
                    @endif
                </div>
            </x-modal>
        @endif
    @endforeach
</div>
