@php
    use Torgash\Catalog\Models\Product;

    $locked = hook_filter('catalog.product.stock_locked', false, ['product' => $product]);
    $statusClass = match ($product->status) {
        Product::STATUS_ACTIVE => 'badge-success',
        Product::STATUS_HIDDEN => 'badge-warning',
        default => 'badge-ghost',
    };
@endphp
{{--
    Swapped standalone by HTMX after a quick-edit or status change, so the id
    has to stay on this <tr> — see ProductsController::rowResponse().

    Clicking dead space in the row toggles inline editing (the one bit of the
    older engines' lists worth stealing outright). The guard skips the click when it
    landed on something interactive, otherwise selecting text or hitting the
    status dropdown would flip edit mode as a side effect.
--}}
<tr id="product-row-{{ $product->id }}"
    {{-- A failed quick-save comes back with the row re-opened for editing, so
         the corrected value is one keystroke away rather than retyped. --}}
    x-data="{ editing: {{ ($rowError ?? null) ? 'true' : 'false' }} }"
    x-on:click="if (!$event.target.closest('a,button,input,select,label,.dropdown')) editing = !editing"
    :class="{ 'bg-base-200/60': editing, 'bg-primary/5': ! editing && selected.includes('{{ $product->id }}') }"
    class="hover:bg-base-200/40 cursor-default">

    {{--
        Every cell that swaps text for an input keeps both states inside the
        same fixed-height box (h-7). Without it the row grew ~5px the moment it
        opened for editing, so the rows underneath jumped down while you were
        aiming at them.
    --}}
    <td x-on:click.stop>
        <input type="checkbox" class="checkbox checkbox-sm" x-model="selected" value="{{ $product->id }}">
    </td>

    <td>
        {{-- The photo sits in the same fixed box as the placeholder: a bare
             <img> carries max-width:100% and the table squeezed it to 32px
             while the empty box beside it kept its 40. --}}
        @if ($url = $product->primaryImageUrl(120))
            <div class="h-10 w-10 shrink-0 rounded bg-base-200 overflow-hidden">
                <img src="{{ $url }}" alt="" class="h-full w-full object-contain" loading="lazy">
            </div>
        @else
            <div class="h-10 w-10 rounded bg-base-200 flex items-center justify-center">
                <x-icon name="image" class="opacity-30" />
            </div>
        @endif
    </td>

    <td class="min-w-0">
        <a href="{{ route('admin.catalog.products.edit', $product) }}" class="link link-hover font-medium block truncate">
            {{ $product->t('name', admin_content_locale()) ?: '—' }}
        </a>
        <div class="h-7 flex items-center">
            <span x-show="!editing" class="font-mono text-xs opacity-50">{{ $product->sku }}</span>
            <input x-show="editing" x-cloak name="sku" value="{{ $product->sku }}"
                   class="input input-xs font-mono w-full max-w-56" aria-label="{{ __('SKU') }}">
        </div>
        @if ($rowError ?? null)
            <div class="text-xs text-error mb-1 flex items-center gap-1">
                <x-icon name="triangle-alert" size="sm" /> {{ $rowError }}
            </div>
        @endif
    </td>

    <td>
        <div class="h-7 flex items-center">
            {{-- Read in whatever the admin picked; TYPED in the currency it is
                 stored in. The two halves of this cell deliberately disagree
                 when a viewing currency is on: converting the input as well
                 would let a quick edit rewrite a price through an exchange
                 rate, which is the one thing this switcher must never do. --}}
            <span x-show="!editing" class="truncate">{{ $product->adminPrice() }}</span>
            <input x-show="editing" x-cloak type="number" step="0.01" min="0" name="price"
                   value="{{ number_format($product->typedPrice() / 100, 2, '.', '') }}"
                   class="input input-xs w-full" aria-label="{{ __('Price') }}">
            {{-- Which currency that box is in, when it is not the shop's own.
                 The list has no picker on purpose: a number typed here means
                 "the same currency as last time", and changing which currency a
                 product is priced in is a decision that belongs on its form. --}}
            @if ($product->price_currency)
                <span x-show="editing" x-cloak class="text-xs opacity-50 ml-1">{{ $product->price_currency }}</span>
            @endif
        </div>
    </td>

    <td>
        <div class="h-7 flex items-center">
            <span x-show="!editing">{{ $product->stock }}</span>
            @if ($locked)
                {{-- Aggregate owned by the warehouses module; editable there only. --}}
                <span x-show="editing" x-cloak class="text-xs opacity-60 flex items-center gap-1"
                      title="{{ __('Split across warehouses — edit it there instead.') }}">
                    {{ $product->stock }} <x-icon name="lock" size="sm" class="opacity-50" />
                </span>
            @else
                <input x-show="editing" x-cloak type="number" name="stock" value="{{ $product->stock }}"
                       class="input input-xs w-full" aria-label="{{ __('Stock') }}">
            @endif
        </div>
    </td>

    <td>
        <div class="dropdown dropdown-end">
            <div tabindex="0" role="button" class="badge {{ $statusClass }} badge-sm gap-1 cursor-pointer">
                {{ $product->statusLabel() }}
                <x-icon name="chevron-down" size="sm" />
            </div>
            <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
                @foreach ([Product::STATUS_ACTIVE => __('Active'), Product::STATUS_HIDDEN => __('Hidden'), Product::STATUS_DISABLED => __('Disabled')] as $value => $label)
                    <li>
                        <button type="button"
                                hx-patch="{{ route('admin.catalog.products.status', $product) }}"
                                hx-vals='@json(['status' => $value])'
                                hx-target="closest tr" hx-swap="outerHTML"
                                @class(['font-semibold text-primary' => $product->status === $value])>
                            {{ $label }}
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    </td>

    <td class="text-right whitespace-nowrap">
        {{-- Preview, edit, and — set apart — the trash, as on the categories
             list: the harmless click and the destructive one are not neighbours. --}}
        <div x-show="!editing" class="h-8 flex items-center justify-end gap-1">
            @if ($product->status !== Product::STATUS_DISABLED && $product->slug)
                <a href="{{ route('catalog.product', $product->slug) }}" target="_blank" rel="noopener"
                   class="btn btn-ghost btn-sm btn-square" title="{{ __('Preview') }}" aria-label="{{ __('Preview') }}">
                    <x-icon name="external-link" />
                </a>
            @else
                <span class="btn btn-ghost btn-sm btn-square btn-disabled" title="{{ __('A disabled product has no page on the storefront.') }}">
                    <x-icon name="external-link" class="opacity-40" />
                </span>
            @endif
            <a href="{{ route('admin.catalog.products.edit', $product) }}"
               class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                <x-icon name="pencil" />
            </a>
            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
            {{-- Beside the row, never nested in it: a <form> inside the table
                 row markup is the nesting trap called out in CLAUDE.md. --}}
            <button form="trash-product-{{ $product->id }}" class="btn btn-ghost btn-sm btn-square text-error"
                    title="{{ __('Move to trash') }}" aria-label="{{ __('Move to trash') }}">
                <x-icon name="trash-2" />
            </button>
        </div>
        {{-- Two squares, the size of the three icons they replace: "Save"
             and "Cancel" in words were wider than the column and lay over
             the status beside it. --}}
        <div x-show="editing" x-cloak class="h-8 flex items-center justify-end gap-1">
            <button type="button" class="btn btn-primary btn-sm btn-square"
                    hx-patch="{{ route('admin.catalog.products.quick', $product) }}"
                    hx-include="closest tr" hx-target="closest tr" hx-swap="outerHTML"
                    title="{{ __('Save') }}" aria-label="{{ __('Save') }}">
                <x-icon name="check" />
            </button>
            <button type="button" class="btn btn-ghost btn-sm btn-square" x-on:click="editing = false"
                    title="{{ __('Cancel') }}" aria-label="{{ __('Cancel') }}">
                <x-icon name="x" />
            </button>
        </div>
    </td>
</tr>
