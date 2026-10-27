{{-- One language's on/off, switched in place and answered with this same cell.
     The base language and a storefront's own have no switch. --}}
<td id="language-status-{{ $language->id }}">
    @if (isset($inUse[$language->code]))
        <span class="badge badge-sm badge-success" title="{{ $inUse[$language->code] }}">{{ __('Enabled') }}</span>
    @else
        <x-admin.status-menu :label="$language->enabled ? __('Enabled') : __('Disabled')" :badge="$language->enabled ? 'badge-success' : 'badge-ghost'"
                             :options="['1' => __('Enabled'), '0' => __('Disabled')]" :current="$language->enabled ? '1' : '0'"
                             :url="route('admin.languages.status', $language)" field="enabled" :target="'#language-status-'.$language->id" />
    @endif
</td>
