@php
    use Torgash\Catalog\Models\Feature;
    use Torgash\Catalog\Models\Product;

    /*
     * One row of the Variations tab's table: a member of the group with
     * its answers on the axes, its price, stock and status — the same quick
     * edit as the products list (_row): clicking dead space opens the
     * SKU, price and stock as fields, Enter or the tick saves through
     * HTMX and the row comes back drawn afresh; the status is a badge
     * with a menu.
     *
     * The table stands INSIDE the product's own form. HTMX sends the
     * enclosing form's values with every non-GET request, so the fields
     * here are named `variant[…]` (never `price`, which the form has too
     * — the last of two to arrive wins) and belong to a hidden sibling form
     * OF THEIR OWN ROW (`form="variant-quick-<id>"`) so that saving the
     * product does not send them. One form for every row was measured and
     * rejected: HTMX sends the button's form's elements, and a form every
     * row's fields belong to holds all of them — the last row's SKU
     * arrived as this one's. `hx-params` keeps only this row's three in the
     * request and `row`/`for` travel in the address. Swapped standalone by
     * HTMX afterwards, so the id stays on this <tr> —
     * ProductsController::rowResponse().
     */
    $isThis = $variant->id === $product->id;
    $keptInstead = $variationInfo['duplicates'][$variant->id] ?? null;
    $keptProduct = $keptInstead ? $variationInfo['members']->firstWhere('id', $keptInstead) : null;
    $locked = hook_filter('catalog.product.stock_locked', false, ['product' => $variant]);
    $canManage = (bool) auth()->user()?->can('catalog.manage');
    $cardAxes = array_filter($variationAxes, fn ($axis) => $axis['feature']->variation_role !== Feature::ROLE_CHOICE);
    $cardOf = fn (int $id) => implode('|', array_map(fn ($axis) => $variationInfo['values'][$id][$axis['feature']->id]['key'] ?? '∅', $cardAxes));
    $sameCard = $cardAxes !== [] && $cardOf($variant->id) === $cardOf($product->id);
    $statusClass = match ($variant->status) {
        Product::STATUS_ACTIVE => 'badge-success',
        Product::STATUS_HIDDEN => 'badge-warning',
        default => 'badge-ghost',
    };
    $rowQuery = ['row' => 'variant', 'for' => $product->id];
    // A hidden or disabled member is dimmed CELL by cell, never the row:
    // the status menu hangs off the row, and an opacity on the row made
    // the menu see-through (the owner's screenshot, 2026-11-05).
    $dim = $variant->status !== Product::STATUS_ACTIVE ? 'opacity-60' : '';
@endphp
<tr id="variant-row-{{ $variant->id }}"
    x-data="{ editing: {{ ($rowError ?? null) ? 'true' : 'false' }} }"
    x-on:click="if ({{ $canManage ? 'true' : 'false' }} && !$event.target.closest('a,button,input,select,label,.dropdown')) editing = !editing"
    x-on:keydown.enter="if (editing) { $event.preventDefault(); $el.querySelector('[data-variant-save]').click() }"
    x-on:keydown.escape="editing = false"
    :class="{ 'bg-base-200/60 is-editing': editing }"
    @class([
        'cursor-default',
        'bg-primary/10' => $isThis && ! $keptInstead,
        'bg-primary/5' => $sameCard && ! $isThis && ! $keptInstead,
        'bg-warning/10' => $keptInstead,
    ])>
    <td class="{{ $dim }}">
        <span class="w-10 h-10 rounded-md bg-base-200 overflow-hidden flex items-center justify-center">
            @if ($variantImage = $variant->primaryImageUrl(80))
                <img src="{{ $variantImage }}" alt="" class="w-full h-full object-contain" loading="lazy">
            @else
                <x-icon name="image" size="sm" class="opacity-30" />
            @endif
        </span>
    </td>
    <td class="min-w-0 {{ $dim }}">
        @if ($isThis)
            <span class="font-medium me-1">{{ $variant->t('name', $locale) }}</span>
            <span class="badge badge-primary badge-sm align-middle">{{ __('this product') }}</span>
        @else
            <a href="{{ route('admin.catalog.products.edit', $variant) }}" class="link link-hover">{{ $variant->t('name', $locale) }}</a>
        @endif
        @if ($keptProduct)
            <div class="text-xs text-warning flex items-center gap-1 mt-0.5">
                <x-icon name="triangle-alert" size="sm" />
                {{ __('Not offered: the same answers as an older product,') }}
                <a href="{{ route('admin.catalog.products.edit', $keptProduct) }}" class="link">{{ $keptProduct->sku }}</a>
            </div>
        @endif
        @if ($rowError ?? null)
            <div class="text-xs text-error mt-1 flex items-center gap-1">
                <x-icon name="triangle-alert" size="sm" /> {{ $rowError }}
            </div>
        @endif
    </td>
    <td class="font-mono text-xs {{ $dim }}">
        <div class="h-8 flex items-center">
            <span x-show="!editing">{{ $variant->sku }}</span>
            <input x-show="editing" x-cloak form="variant-quick-{{ $variant->id }}" name="variant[sku]" autocapitalize="off" autocorrect="off" spellcheck="false"
                   value="{{ $variant->sku }}" class="input input-sm font-mono w-full min-w-28" aria-label="{{ __('SKU') }}">
        </div>
    </td>
    @foreach ($variationAxes as $axis)
        @php $axisAnswer = $variationInfo['values'][$variant->id][$axis['feature']->id] ?? null; @endphp
        <td class="{{ $dim }}">
            @if ($axisAnswer === null)
                <span class="text-error text-xs" title="{{ __('Without this value the product cannot be chosen by it.') }}">—</span>
            @else
                <span class="inline-flex items-center gap-1.5">
                    @if ($axisAnswer['swatch'])
                        <span class="h-3 w-3 rounded-full border border-black/15" style="background-color: {{ $axisAnswer['swatch'] }}"></span>
                    @endif
                    {{ $axisAnswer['label'] }}
                </span>
            @endif
        </td>
    @endforeach
    <td class="text-end tabular-nums whitespace-nowrap {{ $dim }}">
        <div class="h-8 flex items-center justify-end gap-1">
            {{-- Read in the admin's viewing currency, TYPED in the product's own
                 — the list's rule: a quick edit never moves a price through
                 an exchange rate. --}}
            <span x-show="!editing">{{ $variant->adminPrice() }}</span>
            <input x-show="editing" x-cloak form="variant-quick-{{ $variant->id }}" type="number" step="{{ price_step() }}" min="0" name="variant[price]"
                   value="{{ price_input($variant->typedPrice()) }}" class="input input-sm w-28 text-end" aria-label="{{ __('Price') }}">
            @if ($variant->price_currency)
                <span x-show="editing" x-cloak class="text-xs opacity-50">{{ $variant->price_currency }}</span>
            @endif
        </div>
    </td>
    <td class="text-end tabular-nums {{ $dim }}">
        <div class="h-8 flex items-center justify-end">
            <span x-show="!editing">{{ $variant->stock }}</span>
            @if ($locked)
                <span x-show="editing" x-cloak class="text-xs opacity-60 flex items-center gap-1"
                      title="{{ __('Split across warehouses — edit it there instead.') }}">
                    {{ $variant->stock }} <x-icon name="lock" size="sm" class="opacity-50" />
                </span>
            @else
                <input x-show="editing" x-cloak form="variant-quick-{{ $variant->id }}" type="number" name="variant[stock]" value="{{ $variant->stock }}"
                       class="input input-sm w-20 text-end" aria-label="{{ __('Stock') }}">
            @endif
        </div>
    </td>
    <td>
        @unless ($canManage)
            <span class="badge {{ $statusClass }} badge-sm">{{ $variant->statusLabel() }}</span>
        @else
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="badge {{ $statusClass }} badge-sm gap-1 cursor-pointer whitespace-nowrap">
                    {{ $variant->statusLabel() }}
                    <x-icon name="chevron-down" size="sm" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
                    @foreach ([Product::STATUS_ACTIVE => __('Active'), Product::STATUS_HIDDEN => __('Hidden'), Product::STATUS_DISABLED => __('Disabled')] as $value => $label)
                        <li>
                            {{-- The status in the address and nothing from the
                                 form around (`hx-params="none"`). --}}
                            <button type="button" form="variant-quick-{{ $variant->id }}"
                                    hx-patch="{{ route('admin.catalog.products.status', [$variant] + $rowQuery + ['status' => $value]) }}"
                                    hx-params="none" hx-target="closest tr" hx-swap="outerHTML"
                                    @class(['font-semibold text-primary' => $variant->status === $value])>
                                {{ $label }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endunless
    </td>
    <td class="text-end whitespace-nowrap">
        <div x-show="!editing" class="h-8 flex items-center justify-end gap-1">
            @if ($variant->status !== Product::STATUS_DISABLED && $variant->slug)
                <a href="{{ route('catalog.product', $variant->slug) }}" target="_blank" rel="noopener"
                   class="btn btn-ghost btn-sm btn-square" title="{{ __('Preview') }}" aria-label="{{ __('Preview') }}">
                    <x-icon name="external-link" />
                </a>
            @endif
            @unless ($isThis)
                <a href="{{ route('admin.catalog.products.edit', $variant) }}"
                   class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                    <x-icon name="pencil" />
                </a>
            @endunless
        </div>
        <div x-show="editing" x-cloak class="h-8 flex items-center justify-end gap-1">
            <button type="button" form="variant-quick-{{ $variant->id }}" data-variant-save class="btn btn-primary btn-sm btn-square"
                    hx-patch="{{ route('admin.catalog.products.quick', [$variant] + $rowQuery) }}"
                    hx-include="closest tr" hx-params="variant[sku],variant[price],variant[stock]"
                    hx-target="closest tr" hx-swap="outerHTML"
                    title="{{ __('Save') }}" aria-label="{{ __('Save') }}">
                <x-icon name="check" />
            </button>
            <button type="button" form="variant-quick-{{ $variant->id }}" class="btn btn-ghost btn-sm btn-square" x-on:click="editing = false"
                    title="{{ __('Cancel') }}" aria-label="{{ __('Cancel') }}">
                <x-icon name="x" />
            </button>
        </div>
    </td>
</tr>
