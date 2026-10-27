@php
    // Choosing a different product for one slot, in a window rather than in
    // the slot's own 144px cell: the cell had room for a name and a price, and
    // a customer choosing between twenty trousers needs the photograph, the
    // price, and — for an outfit — the SIZE.
    //
    // One card per MODEL, not per variation: six sizes of one t-shirt are six
    // products in this engine, and listing them as six cards is a wall of the
    // same photograph. The size is chosen inside the card, which is also the
    // only place it can be — the kit card itself has no room for a selector
    // per slot.
@endphp
<div class="flex flex-col gap-4">
    <input type="search" name="q" value="{{ $search }}" autocomplete="off"
           class="input input-bordered w-full"
           placeholder="{{ __('Search by name or SKU…') }}"
           hx-get="{{ route('kits.picker', ['kit' => $kit, 'slot' => $slot, 'main' => $main->id]) }}"
           hx-include="#kit-form-{{ $kit->id }}"
           hx-trigger="input changed delay:300ms, search"
           hx-target="#kit-picker-body-{{ $kit->id }}"
           hx-swap="innerHTML">

    @if ($groups->isEmpty())
        <div class="rounded-box border border-dashed border-base-300 p-8 text-center text-sm opacity-60">
            {{ $search !== '' ? __('Nothing matches what you typed.') : __('There is nothing to choose from here right now.') }}
        </div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 max-h-[60vh] overflow-y-auto p-0.5">
            @foreach ($groups as $members)
                @php
                    $candidate = $members->first();
                    // What differs across the model's variations, and therefore
                    // what is left for the customer to say — the group's axes
                    // (Support\Variations: characteristics marked as variation
                    // axes). A slot can still insist on colour and leave size
                    // open: it MATCHES on characteristics, it does not pick.
                    $axes = app(\Torgash\Catalog\Support\Variations::class)->for($candidate);
                    $isChosen = (int) ($chosen?->id) === (int) $candidate->id
                        || $members->contains('id', $chosen?->id);
                    $price = $slot->priceFor((int) $candidate->effectivePrice()) * max(1, $slot->qty);
                    $full = (int) $candidate->effectivePrice() * max(1, $slot->qty);
                @endphp
                <div data-kit-candidate @class([
                        'rounded-box border p-2 flex flex-col gap-1.5',
                        'border-primary ring-1 ring-primary' => $isChosen,
                        'border-base-300' => ! $isChosen,
                     ])>
                    <div class="aspect-square rounded bg-base-200 overflow-hidden flex items-center justify-center relative">
                        @if ($image = $candidate->primaryImageUrl(240))
                            <img src="{{ $image }}" alt="{{ $candidate->t('name') }}"
                                 class="object-contain h-full w-full" loading="lazy" decoding="async">
                        @else
                            <x-no-image class="w-full h-full rounded" size="sm" />
                        @endif
                        @if ($full > $price)
                            <span class="badge torgash-discount badge-sm absolute top-1 start-1">
                                &minus;{{ (int) round(($full - $price) / max(1, $full) * 100) }}%
                            </span>
                        @endif
                    </div>

                    <div class="text-xs line-clamp-2 leading-snug">{{ $candidate->t('name') }}</div>

                    <div class="flex items-baseline gap-1.5">
                        <span class="text-sm font-semibold {{ $full > $price ? 'torgash-discount-text' : 'torgash-price' }}">{{ format_price($price) }}</span>
                        @if ($full > $price)
                            <span class="text-xs torgash-old-price">{{ format_price($full) }}</span>
                        @endif
                    </div>

                    {{-- One select listing the model's variations, labelled by
                         what actually differs between them ("M", "M / black").
                         One control rather than one per axis: two selects would
                         need the pair resolved back to a product on every
                         change, and this one IS the product. --}}
                    <select name="product" class="select select-bordered select-sm w-full mt-auto">
                        @foreach ($members as $member)
                            <option value="{{ $member->id }}" @selected((int) ($chosen?->id) === (int) $member->id)>
                                {{ \Torgash\Kits\Offer::variationLabel($member, $axes) }}
                            </option>
                        @endforeach
                    </select>

                    {{-- Picking swaps the slot's own block — which lives
                         OUTSIDE this window — and closes the window. The form
                         is included by id rather than by `closest form`,
                         because the dialog is a sibling of it: a <select> of
                         ours inside the buy form would otherwise ride along on
                         "add the set". --}}
                    <button type="button" class="btn btn-primary btn-sm w-full"
                            hx-get="{{ route('kits.slot', ['kit' => $kit, 'slot' => $slot, 'main' => $main->id]) }}"
                            hx-include="#kit-form-{{ $kit->id }}, closest [data-kit-candidate]"
                            hx-target="[data-kit-slot='{{ $slot->id }}']"
                            hx-swap="outerHTML"
                            data-close-dialog>
                        {{ $isChosen ? __('Chosen') : __('Choose') }}
                    </button>
                </div>
            @endforeach
        </div>
    @endif
</div>
