{{-- The place of a block drawn after the page (ZoneRenderer). It asks when it
     comes into view, which a hidden tab panel never does until it is opened. --}}
<div hx-get="{{ $waitingUrl }}" hx-trigger="intersect once" hx-swap="outerHTML" class="flex items-center justify-center min-h-40">
    <span class="loading loading-spinner opacity-50" aria-hidden="true"></span>
</div>
