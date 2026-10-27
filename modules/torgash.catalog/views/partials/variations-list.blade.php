{{-- "All variants of this product": a whole-width quiet button under the buy
     box's small actions (cart's cart.buy_box.bottom) and a window with the
     group as a table — picture, code, one column per characteristic that tells
     the variants apart (Variations::for()'s axes), availability, price and the
     basket's own button, which adds THAT variant (`variation => chosen`)
     instead of opening the choice window. Sorting reorders the rows in place;
     the values to sort by are the rows' data attributes, not a JSON blob.
     Variables are named variants* on purpose: an @include hands a partial
     every variable its caller holds. --}}
@php
    $variantsPrices = app(\Torgash\Catalog\Pricing\ShownPrice::class);
    $variantsCollator = "new Intl.Collator(document.documentElement.lang || undefined, { numeric: true, sensitivity: 'base' })";
@endphp
<x-modal :name="'variants-'.$variantsOf->id"
         :title="__('All variants of this product')"
         icon="list"
         size="lg"
         :trigger="__('All variants of this product').' ('.$variantsMembers->count().')'"
         trigger-icon="list"
         trigger-class="btn btn-ghost btn-sm btn-touch w-full gap-2 font-normal border border-base-300">
    <div class="overflow-x-auto -mx-1"
         x-data="{
             key: null,
             dir: 1,
             sort(key) {
                 this.dir = this.key === key ? -this.dir : 1;
                 this.key = key;
                 const collator = {{ $variantsCollator }};
                 const rows = [...this.$refs.rows.children];
                 rows.sort((a, b) => this.dir * collator.compare(a.dataset[key] ?? '', b.dataset[key] ?? ''));
                 rows.forEach((row) => this.$refs.rows.appendChild(row));
             },
         }">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th class="w-14"><span class="sr-only">{{ __('Photo') }}</span></th>
                    @foreach ([['sku', __('SKU')], ...array_map(fn ($axis) => ['axis'.$axis['feature']->id, $axis['feature']->t('name')], $variantsAxes), ['stock', __('Availability')], ['price', __('Price')]] as [$variantsKey, $variantsHeading])
                        <th @class(['text-right' => $variantsKey === 'price'])>
                            <button type="button" class="inline-flex items-center gap-1 font-medium whitespace-nowrap hover:text-primary"
                                    x-on:click="sort('{{ $variantsKey }}')" :class="key === '{{ $variantsKey }}' && 'text-primary'">
                                {{ $variantsHeading }}
                                <x-icon name="chevrons-up-down" size="sm" class="opacity-50" />
                            </button>
                        </th>
                    @endforeach
                    <th><span class="sr-only">{{ __('Add to cart') }}</span></th>
                </tr>
            </thead>
            <tbody x-ref="rows">
                @foreach ($variantsMembers as $variantsMember)
                    @php
                        $variantsUrl = route('catalog.product', $variantsMember->slug);
                        $variantsCurrent = $variantsMember->id === $variantsOf->id;
                    @endphp
                    <tr data-sku="{{ $variantsMember->sku }}"
                        @foreach ($variantsAxes as $variantsAxis)
                            @php $variantsAnswer = $variantsValues[$variantsMember->id][$variantsAxis['feature']->id] ?? null; @endphp
                            data-axis{{ $variantsAxis['feature']->id }}="{{ $variantsAnswer ? (($variantsAnswer['sort'] ?? 0) > 0 ? sprintf('%06d', $variantsAnswer['sort']) : $variantsAnswer['label']) : '' }}"
                        @endforeach
                        data-stock="{{ $variantsMember->isOrderable() ? 0 : 1 }}"
                        data-price="{{ $variantsMember->effectivePrice() }}"
                        @class(['bg-base-200' => $variantsCurrent])>
                        <td>
                            <a href="{{ $variantsUrl }}" class="block w-12 h-12 rounded-field overflow-hidden bg-base-200" tabindex="-1" aria-hidden="true">
                                @if ($variantsImage = $variantsMember->primaryImageUrl(400))
                                    <img src="{{ $variantsImage }}" alt="" class="w-full h-full object-contain" loading="lazy" decoding="async">
                                @else
                                    <x-no-image class="w-full h-full" />
                                @endif
                            </a>
                        </td>
                        <td class="text-xs whitespace-nowrap">
                            <a href="{{ $variantsUrl }}" class="link link-hover">{{ $variantsMember->sku }}</a>
                            @if ($variantsCurrent)
                                <div class="opacity-60">{{ __('This variant') }}</div>
                            @endif
                        </td>
                        @foreach ($variantsAxes as $variantsAxis)
                            @php $variantsAnswer = $variantsValues[$variantsMember->id][$variantsAxis['feature']->id] ?? null; @endphp
                            <td class="whitespace-nowrap">
                                @if ($variantsAnswer)
                                    <span class="inline-flex items-center gap-1.5">
                                        @if ($variantsAxis['feature']->is_color && ($variantsAnswer['swatch'] ?? null))
                                            <span class="w-3.5 h-3.5 rounded-full border border-base-300 shrink-0" style="background: {{ $variantsAnswer['swatch'] }}"></span>
                                        @endif
                                        {{ $variantsAnswer['label'] }}
                                    </span>
                                @else
                                    <span class="opacity-40">—</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="text-xs whitespace-nowrap">
                            <span @class([
                                'torgash-availability torgash-availability-'.$variantsMember->effectiveAvailabilityKey(),
                                'torgash-in-stock' => $variantsMember->isOrderable(),
                                'torgash-out-of-stock' => ! $variantsMember->isOrderable(),
                            ])>{{ $variantsMember->stockLabel() }}</span>
                        </td>
                        <td class="text-right font-semibold whitespace-nowrap">{{ $variantsPrices->format($variantsMember) }}</td>
                        <td class="text-right">
                            {!! hook_render('catalog.product.card_actions', ['product' => $variantsMember, 'variation' => 'chosen']) !!}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-modal>
