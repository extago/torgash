{{-- Beside a method's name on checkout's list, through checkout.payment_methods.notes. --}}
<span class="inline-flex items-center gap-1 text-xs opacity-70" title="{{ __('Gateway') }}">
    <x-icon name="credit-card" size="sm" class="opacity-60" /> {{ $gatewayLabel }}
</span>
@unless ($gatewayKnown)
    <span class="badge badge-sm badge-error whitespace-nowrap" title="{{ __('The module of this gateway is not installed, so the method cannot take money.') }}">{{ __('gateway missing') }}</span>
@endunless
@if ($testMode)
    <span class="badge badge-sm badge-warning whitespace-nowrap">{{ __('test mode') }}</span>
@endif
