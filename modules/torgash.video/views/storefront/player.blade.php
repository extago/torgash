{{-- The player itself — printed inside a <template> by whoever shows the
     video and put on the page only when the visitor presses play.

     The engine draws this tag, never the code an admin pasted: the source
     gives an address and says whether it needs a sandbox (somebody's embed
     does; YouTube breaks inside one). The shop's own file is the browser's
     own player — `autoplay` because the press of play put it here, and
     `preload="auto"` for the same reason.

     Variables are named distinctively on purpose: an included view inherits
     the caller's whole scope (docs/LESSONS.md). --}}
@php($playerSource = $playerVideo->source())
@if ($playerSource instanceof \Torgash\Video\Sources\File)
    <video src="{{ $playerVideo->playerUrl() }}" title="{{ $playerTitle }}"
           class="absolute inset-0 h-full w-full bg-black"
           @if ($playerPoster ?? null) poster="{{ $playerPoster }}" @endif
           controls autoplay playsinline preload="auto"></video>
@else
    <iframe src="{{ $playerVideo->playerUrl() }}" title="{{ $playerTitle }}"
            class="absolute inset-0 h-full w-full border-0"
            allow="autoplay; fullscreen; picture-in-picture; encrypted-media; clipboard-write"
            allowfullscreen referrerpolicy="strict-origin-when-cross-origin"
            @if ($playerSource?->sandboxed()) sandbox="allow-scripts allow-same-origin allow-presentation allow-popups" @endif></iframe>
@endif
