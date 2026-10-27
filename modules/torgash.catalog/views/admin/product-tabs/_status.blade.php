{{-- One tab's on/off, switched in place — the categories list's control.

     $tab         the row, or null for a named section no row speaks for yet
     $sectionKey  that section's key, when $tab is null: the first click makes
                  its row (setSectionStatus) and answers with this cell again,
                  now pointing at the row
     $cellId      kept across that swap, so the answer lands where it was asked --}}
@php
    $cellId ??= $tab ? 'product-tab-status-'.$tab->id : 'product-section-status-'.$sectionKey;
    $isOn = $tab ? (bool) $tab->enabled : true;
    $switchUrl = $tab
        ? route('admin.catalog.product-tabs.status', $tab)
        : route('admin.catalog.product-tabs.section-status', $sectionKey);
@endphp
<td id="{{ $cellId }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer', 'badge-success' => $isOn, 'badge-ghost' => ! $isOn])
             title="{{ __('Change status') }}">
            {{ $isOn ? __('Enabled') : __('Disabled') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Enabled'), 0 => __('Disabled')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ $switchUrl }}"
                            hx-vals='@json(['enabled' => $value, 'cell' => $cellId])'
                            hx-target="#{{ $cellId }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $isOn === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
