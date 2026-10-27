{{-- One country, as the list draws it and as toggle() answers. --}}
<tr id="country-{{ $country->id }}" class="hover:bg-base-200/50">
    <td>
        <input type="checkbox" name="ids[]" value="{{ $country->id }}" x-model="selected" class="checkbox checkbox-sm">
    </td>
    <td>
        <a href="{{ route('admin.locations.countries.edit', $country) }}" @class(['font-medium link link-hover', 'opacity-60' => ! $country->enabled])>
            {{ $country->t('name', admin_content_locale()) }}
        </a>
        @if ($country->source === 'manual')
            <span class="badge badge-sm badge-info">{{ __('added by hand') }}</span>
        @elseif ($country->edited)
            <span class="badge badge-sm badge-ghost">{{ __('edited') }}</span>
        @endif
    </td>
    <td class="font-mono text-xs">{{ $country->code }} · {{ $country->code3 }}</td>
    <td class="text-xs opacity-60">{{ $country->continent }}</td>
    <td class="text-xs opacity-60">{{ $country->phone ? '+'.$country->phone : '' }}</td>
    <td class="text-end tabular-nums text-sm">
        @if ($placeCount > 0)
            <a href="{{ route('admin.locations.index', ['country' => $country->code]) }}" class="link link-hover">{{ number_format($placeCount, 0, '.', ' ') }}</a>
        @else
            <span class="opacity-40">—</span>
        @endif
    </td>
    @include('torgash.locations::admin.partials.status', [
        'enabled' => $country->enabled,
        'toggleUrl' => route('admin.locations.countries.toggle', $country),
        'target' => '#country-'.$country->id,
    ])
    <td class="text-end whitespace-nowrap">
        <div class="inline-flex items-center gap-1">
            <a href="{{ route('admin.locations.countries.edit', $country) }}" class="btn btn-ghost btn-sm btn-square"
               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
            @if ($placeCount > 0)
                <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                {{-- A page, not a button: deleting a hundred thousand rows
                     should never be one click away from the on/off switch
                     beside it. The page it opens spells out what goes. --}}
                <a href="{{ route('admin.locations.countries.purge.confirm', $country->code) }}"
                   class="btn btn-ghost btn-sm btn-square text-error"
                   title="{{ __('Delete data…') }}" aria-label="{{ __('Delete data…') }}"><x-icon name="trash-2" /></a>
            @endif
        </div>
    </td>
</tr>
