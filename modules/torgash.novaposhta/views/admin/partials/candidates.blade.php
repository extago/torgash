{{-- One unmatched place, expanded into its picker. The id stays on the outer
     <tr> because that is what every swap targets. --}}
<tr id="np-place-{{ $place->id }}" class="bg-base-200/40">
    <td colspan="5" class="p-4">
        <div class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-0">
                    <div class="text-xs uppercase tracking-wide opacity-50 leading-none">{{ __('Bind by hand') }}</div>
                    <div class="font-semibold truncate leading-tight">
                        {{ $place->t('name', admin_content_locale()) }}
                        <span class="font-normal opacity-60 text-sm">
                            {{ $place->state?->t('name', admin_content_locale()) }}
                            @if ($place->t('district', admin_content_locale()))
                                · {{ $place->t('district', admin_content_locale()) }}
                            @endif
                        </span>
                    </div>
                </div>

                {{-- The search is the fragment asking for itself again. --}}
                <div class="join">
                    <input name="q" value="{{ $query }}" class="input input-bordered input-sm join-item"
                           placeholder="{{ __('Search Nova Poshta') }}"
                           hx-get="{{ route('admin.nova-poshta.candidates', $place) }}"
                           hx-trigger="keyup changed delay:400ms"
                           hx-target="#np-place-{{ $place->id }}" hx-swap="outerHTML">
                    <span class="btn btn-sm join-item pointer-events-none opacity-60">
                        <x-icon name="search" size="sm" />
                    </span>
                </div>

                <button type="button" class="btn btn-ghost btn-sm"
                        hx-get="{{ route('admin.nova-poshta.row', $place) }}"
                        hx-target="#np-place-{{ $place->id }}" hx-swap="outerHTML">
                    {{ __('Cancel') }}
                </button>
            </div>

            @forelse ($candidates as $candidate)
                <div class="flex flex-wrap items-center gap-3 border-b border-base-200 pb-2">
                    <div class="flex-1 min-w-0">
                        <span class="font-medium">{{ $candidate->name_uk }}</span>
                        <span class="text-sm opacity-60">
                            {{ $candidate->settlement_type }} ·
                            {{ $candidate->area }}@if ($candidate->region), {{ $candidate->region }}@endif
                        </span>
                        {{-- Distance and index first: with the raions renamed,
                             these are what actually tell namesakes apart. --}}
                        @if ($candidate->distance_km !== null)
                            <span @class([
                                'badge badge-sm ml-1',
                                'badge-success' => $candidate->distance_km <= 5,
                                'badge-ghost' => $candidate->distance_km > 5,
                            ])>
                                {{ $candidate->distance_km < 1
                                    ? __('same spot')
                                    : number_format($candidate->distance_km, 0, '.', ' ').' '.__('km') }}
                            </span>
                        @endif
                        @if ($candidate->index1)
                            <span @class(['badge badge-sm', 'badge-success' => $candidate->postal_match, 'badge-ghost' => ! $candidate->postal_match])>
                                {{ $candidate->index1 }}
                            </span>
                        @endif
                        @unless ($candidate->has_warehouse)
                            <span class="badge badge-ghost badge-sm">{{ __('no branches') }}</span>
                        @endunless
                    </div>
                    <form hx-post="{{ route('admin.nova-poshta.bind', $place) }}"
                          hx-target="#np-place-{{ $place->id }}" hx-swap="outerHTML">
                        <input type="hidden" name="settlement_ref" value="{{ $candidate->ref }}">
                        <button class="btn btn-primary btn-sm">{{ __('This one') }}</button>
                    </form>
                </div>
            @empty
                <div class="text-sm opacity-60">
                    {{ __('Nothing in the carrier\'s directory matches. Try another spelling, or leave it: the place will simply not offer Nova Poshta.') }}
                </div>
            @endforelse
        </div>
    </td>
</tr>
