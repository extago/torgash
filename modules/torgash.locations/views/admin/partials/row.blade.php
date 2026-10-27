<tr id="place-{{ $place->id }}" class="hover:bg-base-200/50">
    <td>
        <input type="checkbox" name="ids[]" value="{{ $place->id }}" x-model="selected" class="checkbox checkbox-sm">
    </td>
    <td>
        <a href="{{ route('admin.locations.edit', $place) }}" @class(['font-medium link link-hover', 'opacity-60' => ! $place->enabled])>{{ $place->t('name', admin_content_locale()) ?? $place->t('name', 'en') }}</a>
        <div class="text-xs opacity-50">
            {{ $place->t('district', admin_content_locale()) ?: ($place->state?->t('name', admin_content_locale()) ?? '') }}
        </div>
    </td>
    <td class="text-sm">{{ $place->state?->t('name', admin_content_locale()) ?? $place->country }}</td>
    <td class="text-sm">
        {{ $place->typeLabel() }}
        {{-- "Why is this town not offered at checkout?" answered where it is
             asked. The row is also rendered on its own by toggle(), so the
             setting is read here instead of being handed down from the list. --}}
        @if (in_array($place->type, (array) setting('torgash.locations', 'hidden_types', []), true))
            <span class="badge badge-sm badge-ghost whitespace-nowrap"
                  title="{{ __('This type is switched off in the module settings.') }}">{{ __('not offered') }}</span>
        @endif
    </td>
    <td class="text-sm text-right">{{ $place->population > 0 ? number_format($place->population, 0, '.', ' ') : '—' }}</td>
    <td class="text-xs opacity-60 whitespace-nowrap">
        {{ $place->postal_from }}{{ $place->postal_to && $place->postal_to !== $place->postal_from ? ' — '.$place->postal_to : '' }}
    </td>
    <td>
        @if ($place->source === 'manual')
            <span class="badge badge-sm badge-info">{{ __('added by hand') }}</span>
        @elseif ($place->edited)
            <span class="badge badge-sm badge-ghost">{{ __('edited') }}</span>
        @endif
        @if ($place->missing_in_source)
            <span class="badge badge-sm badge-warning">{{ __('not in the pack') }}</span>
        @endif
    </td>
    @include('torgash.locations::admin.partials.status', [
        'enabled' => $place->enabled,
        'toggleUrl' => route('admin.locations.toggle', $place),
        'target' => '#place-'.$place->id,
    ])
    <td class="text-end whitespace-nowrap">
        {{-- Edit, and — set apart — delete. --}}
        <div class="inline-flex items-center gap-1">
            <a href="{{ route('admin.locations.edit', $place) }}" class="btn btn-ghost btn-sm btn-square"
               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
            {{-- Its own form by the FORM attribute: the row sits inside the
                 bulk form, and a form inside a form leaks its _method. --}}
            <button form="place-delete" formaction="{{ route('admin.locations.destroy', $place) }}"
                    class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"
                    data-confirm="{{ __('Delete :name?', ['name' => $place->t('name', admin_content_locale()) ?? $place->t('name', 'en')]) }}">
                <x-icon name="trash-2" />
            </button>
        </div>
    </td>
</tr>
