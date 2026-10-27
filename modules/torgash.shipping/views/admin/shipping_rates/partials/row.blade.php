{{-- One zone's rate as the method screen reads it. Rendered inside the rates
     tab and, on its own, as the answer to every inline save / cancel / delete:
     the id is the swap target, so it must stay on the outermost <tr>. --}}
@php
    $bands = $rate ? $rate->rows->groupBy('axis') : collect();
@endphp
<tr id="rate-zone-{{ $zone->id }}" class="{{ $rate && ! $rate->enabled ? 'opacity-50' : '' }}">
    <td class="font-medium">
        {{ $zone->t('name', admin_content_locale()) }}
        @unless ($zone->enabled)
            <span class="badge badge-ghost badge-sm ml-1" title="{{ __('The zone itself is switched off, so nothing is offered in it.') }}">
                {{ __('zone off') }}
            </span>
        @endunless
    </td>

    @if ($rate)
        {{-- "Free above" reads as a footnote to the price, not as a column of
             its own: it is empty on most rows and would only widen the table.

             Amounts are shown in the currency the rate is WRITTEN in and are
             not converted: the admin typed 45.50 ₴ and must read 45.50 ₴ back.
             A rate in the shop's own currency renders exactly as before. --}}
        <td>
            @if ($rate->base_rate > 0)
                @if ($rate->currency)
                    {{ \Torgash\Shipping\Money::toMajor($rate->base_rate, $rate->currency) }}
                    {{ \Torgash\Shipping\Money::symbol($rate->currency) }}
                @else
                    {{ admin_price($rate->base_rate, $rate->currency) }}
                @endif
            @else
                {{ __('Free') }}
            @endif
            @if ($rate->free_over !== null)
                <div class="text-xs opacity-50 whitespace-nowrap">
                    {{ __('free above') }}
                    @if ($rate->currency)
                        {{ \Torgash\Shipping\Money::toMajor($rate->free_over, $rate->currency) }}
                        {{ \Torgash\Shipping\Money::symbol($rate->currency) }}
                    @else
                        {{ admin_price($rate->free_over, $rate->currency) }}
                    @endif
                </div>
            @endif
        </td>
        <td class="text-sm">
            @forelse ($bands as $axis => $rows)
                <span class="badge badge-ghost badge-sm mr-1">
                    {{ __(\Torgash\Shipping\Models\ShippingRateRow::AXES[$axis] ?? $axis) }}: {{ $rows->count() }}
                </span>
            @empty
                <span class="opacity-50">—</span>
            @endforelse
        </td>
        <td class="text-sm">{{ $rate->delivery_time ? $rate->t('delivery_time', admin_content_locale()) : '—' }}</td>
        <td>
            <span @class(['badge badge-sm', 'badge-success' => $rate->enabled, 'badge-ghost' => ! $rate->enabled])>
                {{ $rate->enabled ? __('Enabled') : __('Disabled') }}
            </span>
        </td>
        <td class="text-end whitespace-nowrap">
            {{-- Edit, and — set apart — remove: the lists' own row actions. --}}
            <div class="inline-flex items-center gap-1">
                <button type="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"
                        hx-get="{{ route('admin.shipping.methods.rates.edit', [$method, $zone]) }}"
                        hx-target="#rate-zone-{{ $zone->id }}" hx-swap="outerHTML">
                    <x-icon name="pencil" />
                </button>
                <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                <button type="button" class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}"
                        hx-delete="{{ route('admin.shipping.methods.rates.destroy', [$method, $zone]) }}"
                        hx-target="#rate-zone-{{ $zone->id }}" hx-swap="outerHTML"
                        hx-confirm="{{ __('Remove this rate? The method stops being offered in that zone.') }}">
                    <x-icon name="trash-2" />
                </button>
            </div>
        </td>
    @else
        <td colspan="4" class="text-sm opacity-50">{{ __('Not offered here.') }}</td>
        <td class="text-end whitespace-nowrap">
            <button type="button" class="btn btn-sm"
                    hx-get="{{ route('admin.shipping.methods.rates.edit', [$method, $zone]) }}"
                    hx-target="#rate-zone-{{ $zone->id }}" hx-swap="outerHTML">
                + {{ __('Set a rate') }}
            </button>
        </td>
    @endif
</tr>
