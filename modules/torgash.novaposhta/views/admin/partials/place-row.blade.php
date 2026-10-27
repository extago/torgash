{{-- One place and what it is tied to, as the list reads it and as every
     bind/unbind answers. The id stays on the outer <tr>: it is the swap
     target. --}}
@php
    $methodLabels = [
        'name_area_district' => __('name + oblast + raion'),
        'name_area' => __('name + oblast'),
        'postal' => __('postal index'),
        'coords' => __('coordinates'),
        'manual' => __('by hand'),
    ];
    // Handed in, never read off the Place: the mapping belongs to this module
    // and the registry's model knows nothing about it.
    $map = $map ?? null;
@endphp
<tr id="np-place-{{ $place->id }}" @class(['bg-success/10' => ($justBound ?? false)])>
    <td class="font-medium">{{ $place->t('name', admin_content_locale()) }}</td>
    <td class="text-sm opacity-70">
        {{ $place->state?->t('name', admin_content_locale()) }}
        @if ($place->t('district', admin_content_locale()))
            <span class="opacity-60">· {{ $place->t('district', admin_content_locale()) }}</span>
        @endif
    </td>
    <td class="text-sm">
        @if ($map && $map->settlement)
            <span class="font-medium">{{ $map->settlement->name_uk }}</span>
            <span class="opacity-60">
                ({{ $map->settlement->area }}@if ($map->settlement->region), {{ $map->settlement->region }}@endif)
            </span>
            <span class="badge badge-ghost badge-sm ml-1">{{ $methodLabels[$map->matched_by] ?? $map->matched_by }}</span>
            @unless ($map->city_ref)
                <span class="badge badge-ghost badge-sm"
                      title="{{ __('The carrier has no courier city of this name, so only branch delivery is offered here.') }}">
                    {{ __('branches only') }}
                </span>
            @endunless
        @else
            <span class="opacity-50">{{ __('not offered Nova Poshta') }}</span>
        @endif
    </td>
    <td class="text-right text-sm">{{ $place->population > 0 ? number_format($place->population, 0, '.', ' ') : '—' }}</td>
    <td class="text-right whitespace-nowrap">
        <button type="button" class="btn btn-sm"
                hx-get="{{ route('admin.nova-poshta.candidates', $place) }}"
                hx-target="#np-place-{{ $place->id }}" hx-swap="outerHTML">
            {{ $map ? __('Rebind') : __('Bind by hand') }}
        </button>
        @if ($map)
            <button type="button" class="btn btn-ghost btn-sm text-error"
                    hx-delete="{{ route('admin.nova-poshta.unbind', $place) }}"
                    hx-target="#np-place-{{ $place->id }}" hx-swap="outerHTML"
                    hx-confirm="{{ __('Drop this mapping? The place stops being offered Nova Poshta until the next matching run.') }}">
                {{ __('Unbind') }}
            </button>
        @endif
    </td>
</tr>
