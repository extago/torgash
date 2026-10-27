{{-- One storefront's on/off, switched in place and answered with this same cell.
     The default storefront has no switch: every address nobody else claims
     lands on it. --}}
<td id="storefront-status-{{ $storefront->id }}">
    @if ($storefront->is_default)
        <span class="badge badge-sm badge-success" title="{{ __('The default storefront cannot be switched off') }}">{{ __('On') }}</span>
    @else
        <x-admin.status-menu :label="$storefront->enabled ? __('On') : __('Off')" :badge="$storefront->enabled ? 'badge-success' : 'badge-ghost'"
                             :options="['1' => __('On'), '0' => __('Off')]" :current="$storefront->enabled ? '1' : '0'"
                             :url="route('admin.storefronts.status', $storefront)" field="enabled" :target="'#storefront-status-'.$storefront->id" />
    @endif
    @if ($storefront->closed)
        <span class="badge badge-sm badge-warning" title="{{ __('Visitors see a notice; staff see the shop') }}">{{ __('Closed for visitors') }}</span>
    @endif
</td>
