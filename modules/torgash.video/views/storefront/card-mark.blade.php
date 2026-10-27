{{-- "This product has a video", on a catalogue card. A mark, not a button:
     the card's text is one link to the product, and nothing interactive may
     sit inside a link — so the hint is the browser's own `title`. --}}
<span class="inline-flex items-center gap-1 text-xs text-base-content/60" title="{{ __('Has a video') }}">
    <x-icon name="circle-play" size="sm" />
    {{ __('Video') }}
</span>
