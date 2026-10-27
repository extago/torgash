{{-- On the customer's own order page, once CDEK has numbered the parcel:
     the number, its status, and the carrier's tracking page. --}}
<div class="mt-2 text-sm flex flex-col gap-0.5">
    <div>
        {{ __('CDEK tracking number') }}:
        <a href="{{ $waybill->trackingUrl() }}" target="_blank" rel="noopener" class="link font-mono font-semibold">{{ $waybill->cdek_number }}</a>
    </div>
    @if ($waybill->status_name)
        <div class="opacity-60">{{ $waybill->status_name }}</div>
    @endif
</div>
