<tr id="state-{{ $state->id }}" class="hover:bg-base-200/50">
    <td>
        <input type="checkbox" name="ids[]" value="{{ $state->id }}" x-model="selected" class="checkbox checkbox-sm">
    </td>
    <td>
        <a href="{{ route('admin.locations.regions.edit', $state) }}" @class(['font-medium link link-hover', 'opacity-60' => ! $state->enabled])>{{ $state->t('name', admin_content_locale()) }}</a>
        @if ($state->source === 'manual')
            <span class="badge badge-sm badge-info">{{ __('added by hand') }}</span>
        @elseif ($state->edited)
            <span class="badge badge-sm badge-ghost">{{ __('edited') }}</span>
        @endif
    </td>
    <td class="font-mono text-xs">{{ $state->country }} · {{ $state->code }}</td>
    <td class="text-right text-sm">{{ $placeCount > 0 ? number_format($placeCount, 0, '.', ' ') : '—' }}</td>
    @include('torgash.locations::admin.partials.status', [
        'enabled' => $state->enabled,
        'toggleUrl' => route('admin.locations.regions.toggle', $state),
        'target' => '#state-'.$state->id,
    ])
    <td class="text-end whitespace-nowrap">
        <a href="{{ route('admin.locations.regions.edit', $state) }}" class="btn btn-ghost btn-sm btn-square"
           title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
    </td>
</tr>
