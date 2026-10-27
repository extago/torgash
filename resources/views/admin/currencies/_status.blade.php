{{-- One currency's on/off, switched in place and answered with this same cell.
     A currency a storefront keeps its books in has no switch at all. --}}
<td id="currency-status-{{ $currency->id }}">
    @if (isset($inUse[$currency->code]))
        <span class="badge badge-sm badge-success" title="{{ __('A storefront keeps its books in it') }}">{{ __('On') }}</span>
    @else
        <x-admin.status-menu :label="$currency->enabled ? __('On') : __('Off')" :badge="$currency->enabled ? 'badge-success' : 'badge-ghost'"
                             :options="['1' => __('On'), '0' => __('Off')]" :current="$currency->enabled ? '1' : '0'"
                             :url="route('admin.currencies.status', $currency)" field="enabled" :target="'#currency-status-'.$currency->id" />
    @endif
</td>
