{{-- `{video:ID}` inside an article's or a page's text: the shop's poster
     and a play button, the player in a <template> put on the page by the
     press of play and nothing else — the same promise as the product
     gallery. Without script the poster stays, which is honest: nothing
     plays that was not asked for.

     Variables are named distinctively on purpose: an included view inherits
     the caller's whole scope (docs/LESSONS.md). --}}
@php
    // The text column is wider than the gallery's stage: the smallest size
    // at least 1200 wide, or the poster itself when the shop has none.
    $inlinePoster = $inlineVideo->posterUrl(1200);
    $inlinePlatform = $inlineVideo->platform();
@endphp
<figure class="torgash-video not-prose my-6" x-data="{ on: false }">
    <div class="relative aspect-video rounded-box overflow-hidden bg-neutral text-neutral-content">
        <button type="button" x-show="! on" class="absolute inset-0 flex flex-col items-center justify-center gap-2 cursor-pointer group/play"
                aria-label="{{ __('Play the video: :title', ['title' => $inlineTitle]) }}"
                x-on:click="$refs.stage.replaceChildren($refs.player.content.cloneNode(true)); on = true; $refs.stage.querySelector('video')?.play()?.catch(() => {})">
            @if ($inlinePoster)
                <img src="{{ $inlinePoster }}" alt="{{ $inlineTitle }}" class="absolute inset-0 h-full w-full object-cover" loading="lazy" decoding="async">
                <span class="absolute inset-0 bg-black/20 group-hover/play:bg-black/10 transition-colors"></span>
            @endif
            <span class="relative flex items-center justify-center w-16 h-16 rounded-full bg-black/60 text-white group-hover/play:bg-primary transition-colors">
                <x-icon name="play" size="xl" class="fill-current translate-x-0.5" />
            </span>
            @if ($inlinePlatform !== '')
                <span class="relative rounded bg-black/60 px-2 py-0.5 text-xs text-white">{{ __('The video loads from :platform', ['platform' => $inlinePlatform]) }}</span>
            @endif
        </button>
        <div x-ref="stage" class="contents"></div>
        <template x-ref="player" data-video-player>@include('torgash.video::storefront.player', ['playerVideo' => $inlineVideo, 'playerTitle' => $inlineTitle, 'playerPoster' => $inlinePoster])</template>
    </div>
    @if ($inlineVideo->t('title'))
        <figcaption class="mt-2 text-sm opacity-70 text-center">{{ $inlineVideo->t('title') }}</figcaption>
    @endif
</figure>
