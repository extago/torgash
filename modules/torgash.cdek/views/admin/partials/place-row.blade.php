{{-- One place and the CDEK city it is tied to, as the list reads it and as
     every bind/unbind answers. The id stays on the outer <tr>: it is the swap
     target. --}}
@php
    $cdekMethodLabels = [
        'name_district' => __('name + region + district'),
        'coords' => __('name + coordinates'),
        'name_region' => __('name + region'),
        'name' => __('unique name'),
        'manual' => __('by hand'),
    ];
    // Handed in, never read off the Place: the mapping belongs to this module.
    $cdekMap = $map ?? null;
@endphp
<tr id="cdek-place-{{ $place->id }}" @class(['bg-success/10' => ($justBound ?? false)])>
    <td class="font-medium">{{ $place->t('name', admin_content_locale()) }}</td>
    <td class="text-sm opacity-70">
        {{ $place->state?->t('name', admin_content_locale()) }}
        @if ($place->t('district', admin_content_locale()))
            <span class="opacity-60">· {{ $place->t('district', admin_content_locale()) }}</span>
        @endif
    </td>
    <td class="text-sm">
        @if ($cdekMap && $cdekMap->city)
            <span class="font-medium">{{ $cdekMap->city->name }}</span>
            <span class="opacity-60">({{ implode(', ', array_filter([$cdekMap->city->sub_region, $cdekMap->city->region])) }})</span>
            <span class="badge badge-ghost badge-sm ml-1">{{ $cdekMethodLabels[$cdekMap->matched_by] ?? $cdekMap->matched_by }}</span>
        @else
            <span class="opacity-50">{{ __('not offered CDEK') }}</span>
        @endif
    </td>
    <td class="text-right text-sm">{{ $place->population > 0 ? number_format($place->population, 0, '.', ' ') : '—' }}</td>
    <td class="text-right whitespace-nowrap">
        <button type="button" class="btn btn-sm"
                hx-get="{{ route('admin.cdek.candidates', $place) }}"
                hx-target="#cdek-place-{{ $place->id }}" hx-swap="outerHTML">
            {{ $cdekMap ? __('Rebind') : __('Bind by hand') }}
        </button>
        @if ($cdekMap)
            <button type="button" class="btn btn-ghost btn-sm text-error"
                    hx-delete="{{ route('admin.cdek.unbind', $place) }}"
                    hx-target="#cdek-place-{{ $place->id }}" hx-swap="outerHTML"
                    hx-confirm="{{ __('Drop this mapping? The place stops being offered CDEK until the next matching run.') }}">
                {{ __('Unbind') }}
            </button>
        @endif
    </td>
</tr>
