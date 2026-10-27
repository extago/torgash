{{-- The header's comparison icon with its count (the theme's
     storefront.header.icons), re-sent out of band after every toggle
     (compareCounterOob). --}}
@php $compareCount = app(\Torgash\Compare\Comparison::class)->count(); @endphp
<a href="{{ route('compare.index') }}" id="compare-counter" class="btn btn-ghost btn-sm gap-2 px-3"
   @if ($compareCounterOob ?? false) hx-swap-oob="true" @endif
   aria-label="{{ __('Comparison') }}{{ $compareCount > 0 ? ' ('.$compareCount.')' : '' }}" title="{{ __('Comparison') }}">
    <span class="torgash-counter">
        <x-icon name="square-kanban" size="lg" />
        <span @class(['badge badge-sm', 'badge-primary' => $compareCount > 0, 'torgash-counter-zero' => $compareCount === 0])>{{ $compareCount > 9 ? '9+' : $compareCount }}</span>
    </span>
</a>
@if ($compareCounterOob ?? false)
    {{-- ...and the phone bar's count, when the shop put the comparison there. --}}
    <span id="tabbar-badge-compare" hx-swap-oob="true" @class(['badge badge-primary torgash-tabbar-badge', 'hidden' => $compareCount === 0])>{{ $compareCount ?: '' }}</span>
@endif
