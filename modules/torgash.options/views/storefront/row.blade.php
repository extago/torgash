@php
    // One row of a "rows you add" question: an answer and how many of it.
    // Rendered by the server both for the rows already answered and for a row
    // added by hand, so the two cannot come to look or behave differently.
    $configurator = app(\Torgash\Options\Configurator::class);
    $rules = app(\Torgash\Options\PriceRules::class);
    $owner = $product ?? $group->product;
    $base = $owner ? (int) $owner->effectivePrice() : 0;
    $showPrices = (bool) setting('torgash.options', 'show_price_in_label', true);
    $threshold = max(2, (int) setting('torgash.options', 'search_threshold', 8));

    $chosenValue = (string) ($entry['value'] ?? '');
    $chosenQty = max(1, (int) ($entry['qty'] ?? max(1, (int) $group->min_qty)));

    // A set big enough that a list of it is a scroll gets a search box, and
    // the narrowing is the server's: the set IS a catalogue query.
    $set = $group->choices->first(fn ($choice) => $choice->isSet() && $choice->enabled);
    $searchable = $set && $configurator->setProducts($set, '', $threshold + 1)->count() > $threshold;
@endphp
{{-- Two lines in a frame: WHAT on the first, the full width of the column a
     model name needs, and HOW MANY with its shortcuts and the way out on the
     second. On one line the buy box's narrow column left the select a
     third of its width — too little for a processor's model name. --}}
<div class="flex flex-col gap-2 rounded-field border border-base-300 bg-base-100 p-2" data-options-row>
    @if ($searchable)
        {{-- Narrows the list beside it and nothing else: the answer is still
             whatever the select ends up holding. --}}
        <input type="search" name="q" autocomplete="off"
               class="input input-sm w-full"
               placeholder="{{ __('Search…') }}"
               hx-get="{{ route('options.set', ['choice' => $set, 'product' => $owner?->slug]) }}"
               hx-trigger="input changed delay:300ms, search"
               {{-- The select beside it. `next` and not `closest … select`:
                    a "closest" selector looks at ANCESTORS, and no ancestor of
                    this input is a select — so the swap silently never
                    happened (measured on the live page). --}}
               hx-target="next select"
               hx-swap="innerHTML">
    @endif

    <select name="config[options][{{ $group->id }}][{{ $index }}][choice]" class="select w-full" aria-label="{{ $group->t('name') }}">
        @unless ($group->required)
            <option value="">{{ __('Not needed') }}</option>
        @endunless
        @foreach ($group->choices as $choice)
            @continue (! $choice->enabled)

            @if ($choice->isSet())
                @foreach ($configurator->setProducts($choice, '', $threshold) as $setProduct)
                    @php
                        $value = $choice->id.':'.$setProduct->id;
                        $amount = $rules->apply($choice, $base, $setProduct);
                        $orderable = $setProduct->isOrderable();
                        // See set-options.blade.php: a directive written right
                        // after a word character is not a directive.
                        $suffix = ($showPrices && $amount !== 0 ? ' '.($amount > 0 ? '+' : '−').format_price(abs($amount)) : '')
                            .($orderable ? '' : ' — '.$setProduct->stockLabel());
                    @endphp
                    {{-- Shown and refused rather than quietly dropped — the same
                         answer the other controls give (see fields.blade). --}}
                    <option value="{{ $value }}" @selected($chosenValue === $value) @disabled(! $orderable)>
                        {{ $choice->label($setProduct) }}{{ $suffix }}
                    </option>
                @endforeach
            @else
                @php
                    $chosenProduct = $choice->kind === 'product' ? $configurator->productFor($choice, []) : null;
                @endphp
                @continue ($choice->kind === 'product' && ! $chosenProduct)
                @php
                    $amount = $rules->apply($choice, $base, $chosenProduct);
                    $orderable = ! $chosenProduct || $chosenProduct->isOrderable();
                    $suffix = ($showPrices && $amount !== 0 ? ' '.($amount > 0 ? '+' : '−').format_price(abs($amount)) : '')
                        .($orderable ? '' : ' — '.$chosenProduct->stockLabel());
                @endphp
                <option value="{{ $choice->id }}" @selected($chosenValue === (string) $choice->id) @disabled(! $orderable)>
                    {{ $choice->label($chosenProduct) }}{{ $suffix }}
                </option>
            @endif
        @endforeach
    </select>

    <div class="flex items-center gap-1">
        @include('torgash.options::storefront.stepper', [
            'stepperName' => 'config[options]['.$group->id.']['.$index.'][qty]',
            'stepperValue' => $chosenQty,
            'stepperMin' => max(1, (int) $group->min_qty),
            'stepperMax' => $group->qtyCeiling(),
        ])

        @foreach ((array) ($group->qty_steps ?? []) as $step)
            {{-- The owner's own "1x 2x" buttons. Plain JavaScript on the button
                 rather than a framework: it sets the number beside it and fires
                 the change the price recalculation already listens for. --}}
            <button type="button" class="btn btn-ghost btn-sm btn-touch px-2 tabular-nums"
                    x-data x-on:click="const i = $el.closest('[data-options-row]').querySelector('input[type=number]'); i.value = {{ (int) $step }}; i.dispatchEvent(new Event('change', { bubbles: true }));">
                {{ $step }}×
            </button>
        @endforeach

        <button type="button" class="btn btn-ghost btn-sm btn-touch btn-square text-error ms-auto"
                aria-label="{{ __('Remove') }}"
                x-data x-on:click="const row = $el.closest('[data-options-row]'); const rows = row.parentElement; row.remove(); rows.dispatchEvent(new Event('change', { bubbles: true }));">
            <x-icon name="trash-2" size="sm" />
        </button>
    </div>
</div>
