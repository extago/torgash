{{-- The buy button of a LIST (a catalogue card — through catalog's
     catalog.product.card_actions — or anywhere else that shows products in a
     row). Three shapes:
       - nothing, for a product that cannot be bought;
       - a cart with a gear, for one that must be CHOSEN first (variations,
         questions — ListBuy::mustChoose): it opens the choice window, and is
         a plain link to the product page without script;
       - an add form, with a − 1 + stepper that opens beside the button when
         the pointer is over it (listBuyQuantity), and a button that turns
         green with a tick once the product is in the basket — still adding
         one more when pressed. listBuyQuantityOpen draws the stepper open
         from the start (a list's row has the room; a card does not).
     Variables are named listBuy* on purpose: an @include hands a partial
     every variable its caller holds. --}}
@php
    $listBuy = app(\Torgash\Cart\ListBuy::class);
    $listBuyName = $listBuyProduct->t('name');
    $listBuyInCart = $listBuy->inCart($listBuyProduct);
    $listBuyGates = app(\Torgash\Catalog\Stock\AvailabilityStatuses::class)->gatesOnStock($listBuyProduct->availability_status);
    $listBuyMax = $listBuyGates ? max(1, (int) $listBuyProduct->stock) : 999;
@endphp
@if ($listBuyProduct->isOrderable())
    @if ($listBuy->mustChoose($listBuyProduct, $listBuyVariationChosen ?? false))
        <a href="{{ route('catalog.product', $listBuyProduct->slug) }}"
           hx-get="{{ route('cart.choose', $listBuyProduct->slug) }}" hx-target="#torgash-cart-dialog-body" hx-swap="innerHTML"
           class="btn btn-primary btn-sm btn-touch btn-square relative shrink-0"
           title="{{ __('Choose and add to cart') }}" aria-label="{{ __('Choose and add to cart') }}: {{ $listBuyName }}">
            <x-icon name="shopping-cart" size="lg" />
            <span class="absolute -top-1.5 -right-1.5 rounded-full bg-base-100 text-base-content shadow-sm p-0.5 leading-none">
                <x-icon name="settings" size="sm" />
            </span>
        </a>
    @else
        <form hx-post="{{ route('cart.add', $listBuyProduct->slug) }}" hx-swap="none"
              data-list-buy-add @if ($listBuyInCart > 0) data-in-cart @endif data-product="{{ $listBuyProduct->id }}"
              @class([
                  'group/buy flex items-center shrink-0',
                  // A CARD has no room: the stepper opens on hover INSIDE one
                  // frame with the button, as a single control.
                  'gap-0.5 rounded-field p-0.5 border border-transparent transition-colors' => ! ($listBuyQuantityOpen ?? false),
                  'hover:border-base-300 hover:bg-base-100 focus-within:border-base-300 focus-within:bg-base-100' => ($listBuyQuantity ?? false) && ! ($listBuyQuantityOpen ?? false),
                  // A ROW has the room, and there the two are two controls:
                  // the stepper in a frame of its own, the button beside it.
                  'gap-2' => $listBuyQuantityOpen ?? false,
              ])
              @if ($listBuyQuantity ?? false) x-data="{ qty: 1, max: {{ $listBuyMax }} }" @endif>
            @if ($listBuyAfter ?? null)
                <input type="hidden" name="after_add" value="{{ $listBuyAfter }}">
            @endif
            @if ($listBuyQuantity ?? false)
                {{-- Opens on hover or keyboard focus; a touch screen has no
                     hover, and there the button simply adds one. --}}
                {{-- Plain on white, grey under the pointer; the number is bare
                     text in the frame, not a box inside a box. --}}
                <div @class([
                    'items-center',
                    'flex rounded-field border border-base-300 bg-base-100' => $listBuyQuantityOpen ?? false,
                    'hidden group-hover/buy:flex group-focus-within/buy:flex' => ! ($listBuyQuantityOpen ?? false),
                ])>
                    <button type="button" class="btn btn-sm btn-touch btn-square btn-ghost bg-base-100 hover:bg-base-200 border-0 shadow-none" x-on:click="qty = Math.max(1, (qty || 1) - 1)" aria-label="{{ __('Decrease quantity') }}"><x-icon name="minus" /></button>
                    <input type="number" name="qty" x-model.number="qty" min="1" max="{{ $listBuyMax }}" value="1"
                           class="w-9 bg-transparent border-0 p-0 text-center text-sm tabular-nums focus:outline-none torgash-qty" aria-label="{{ __('Quantity') }}">
                    <button type="button" class="btn btn-sm btn-touch btn-square btn-ghost bg-base-100 hover:bg-base-200 border-0 shadow-none" x-on:click="qty = Math.min(max, (qty || 1) + 1)" aria-label="{{ __('Increase quantity') }}"><x-icon name="plus" /></button>
                </div>
            @else
                <input type="hidden" name="qty" value="1">
            @endif
            <button class="btn btn-primary btn-sm btn-touch btn-square group-data-[in-cart]/buy:btn-success"
                    title="{{ $listBuyInCart > 0 ? __('In cart — add one more') : __('Add to cart') }}"
                    aria-label="{{ __('Add to cart') }}: {{ $listBuyName }}">
                <span class="group-data-[in-cart]/buy:hidden"><x-icon name="shopping-cart" size="lg" /></span>
                <span class="hidden group-data-[in-cart]/buy:inline"><x-icon name="check" size="lg" /></span>
            </button>
        </form>
    @endif
@endif
