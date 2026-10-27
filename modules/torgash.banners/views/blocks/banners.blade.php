{{--
    The `banners` block: one slider, wherever the shop puts the block. The
    zone's context goes with it — a banner's own display rules ask about the
    page ("the product is in…") just as a block's do. Nothing running for
    this visitor prints nothing, heading included.
--}}
@php
    $bannersHtml = app(\Torgash\Banners\Renderer::class)->slider((int) ($settings['slider'] ?? 0), $zoneContext ?? []);
    $bannersHeading = trim((string) ($settings['heading'] ?? ''));
@endphp
@if ($bannersHtml !== '')
    <div class="torgash-block torgash-block-banners h-full flex flex-col gap-3">
        @if ($bannersHeading !== '' && ! ($inTabs ?? false))
            <h2 class="text-lg font-semibold">{{ $bannersHeading }}</h2>
        @endif
        {!! $bannersHtml !!}
    </div>
@endif
