{{-- One article in a feed: cover, plates, title, lead, when and how long.
     `$cardPost` rather than `$post`: a card is included inside pages that have
     their own $post, and @include hands every variable down. --}}
@php
    $cardCover = $cardPost->coverUrl($cardWide ?? false ? 1200 : 640);
@endphp
<article @class(['group bg-base-100 border border-base-300/70 rounded-box overflow-hidden flex flex-col transition-shadow hover:shadow-lg', 'md:col-span-full md:flex-row' => $cardWide ?? false])>
    <a href="{{ $cardPost->url() }}" @class(['block overflow-hidden bg-base-200 shrink-0', 'aspect-[16/9]' => ! ($cardWide ?? false), 'aspect-[16/9] md:w-3/5' => $cardWide ?? false])>
        @if ($cardCover)
            <img src="{{ $cardCover }}" alt="{{ $cardPost->t('title') }}" loading="lazy" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-[1.03]">
        @else
            {{-- No cover: the first rubric's own colour and icon, so an article
                 without a picture still looks meant rather than missing. --}}
            @php $cardRubric = $cardPost->rubrics->first(); $cardTint = $cardRubric?->colour() ?? '#64748b'; @endphp
            <div class="w-full h-full grid place-items-center text-white"
                 style="background: linear-gradient(135deg, {{ $cardTint }}, {{ $cardTint }}99)">
                <x-icon :name="$cardRubric?->icon ?: 'newspaper'" class="w-16 h-16 opacity-80" />
            </div>
        @endif
    </a>
    <div @class(['flex flex-col gap-3 p-5 flex-1', 'md:p-8 md:justify-center' => $cardWide ?? false])>
        @if ($cardPost->rubrics->isNotEmpty())
            <div class="flex flex-wrap gap-1.5">
                @include('torgash.blog::partials.plates', ['plateRubrics' => $cardPost->rubrics])
            </div>
        @endif
        <h2 @class(['font-bold leading-snug', 'text-xl md:text-2xl' => $cardWide ?? false, 'text-lg' => ! ($cardWide ?? false)])>
            <a href="{{ $cardPost->url() }}" class="hover:text-primary">{{ $cardPost->t('title') }}</a>
        </h2>
        @if ($cardLead = trim(strip_tags((string) $cardPost->t('lead'))))
            <p class="text-sm opacity-75 line-clamp-3">{{ $cardLead }}</p>
        @endif
        <div class="mt-auto pt-2 flex items-center gap-3 text-xs opacity-60">
            <span>{{ $cardPost->published_at?->translatedFormat('j F Y') }}</span>
            <span class="inline-flex items-center gap-1"><x-icon name="clock" size="sm" /> {{ trans_choice(':count min|:count min', $cardPost->reading_minutes, ['count' => $cardPost->reading_minutes]) }}</span>
            @if ($cardPost->likes_count > 0)
                <span class="inline-flex items-center gap-1"><x-icon name="heart" size="sm" /> {{ $cardPost->likes_count }}</span>
            @endif
        </div>
    </div>
</article>
