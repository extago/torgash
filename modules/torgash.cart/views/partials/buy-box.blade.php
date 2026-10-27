@php
    // What a configurator submitted last time round, and what it got wrong —
    // passed explicitly, because a refused form was answered with no redirect
    // and old() is therefore empty (CartController::add).
    $buyBoxConfig = $config ?? [];
    $buyBoxErrors = $configErrors ?? [];
    // Orderable right now (PLAN §31.3) — a preorder or an untracked product
    // counts even at zero or negative stock, a discontinued one does not
    // whatever is left on the shelf.
    $inStock = $product->isOrderable();
    // The stepper's ceiling is the shelf only for a status that GATES on it;
    // a preorder or an untracked product has no such number to cap against,
    // so it gets a plain, generous ceiling instead of one tied to a count
    // that was never a promise.
    $stockGates = app(\Torgash\Catalog\Stock\AvailabilityStatuses::class)->gatesOnStock($product->availability_status);
    $max = $stockGates ? max(1, $product->stock) : 999;
    $label = match (true) {
        ! $inStock => __('Out of stock'),
        $product->availability_status === 'preorder' => __('Preorder'),
        default => __('Add to cart'),
    };
@endphp
{{--
    Quantity stepper + Add to cart. Both sides are btn-lg-sized (h-12): the qty
    field used to be a bare `input` (40px) next to a btn-lg (48px), so it read
    as a stray smaller control rather than one row.

    x-on:click rather than Alpine's @click shorthand — this tag also carries
    Blade's own @disabled directive, and keeping the two syntaxes visually
    distinct avoids any question of which scanner owns which attribute.
--}}
@php
    // Whatever has to be answered before this can be bought: the product's own
    // options and services (torgash.options). Rendered INSIDE the form, so the
    // answers ride the same POST and a browser with no JavaScript submits them
    // together with the quantity. Empty in a shop with no such module, and then
    // the buy box is the single row it always was.
    $buyBoxFields = hook_filter('cart.buy_box.fields', '', [
        'product' => $product,
        'config' => $buyBoxConfig,
        'errors' => $buyBoxErrors,
    ]);
    // This box's own name, so a refused add can be answered WITH THE BOX
    // rather than swapped into whatever the form happened to target. The form
    // aims at the header badge, which is right for the one thing it normally
    // answers with; a refusal is a different answer and says so in an
    // HX-Retarget (CartController::add).
    $boxId = 'buy-box-'.$product->id;
    // What was refused, in one place and in plain words. The messages name the
    // question they are about ("Choose Memory."), so beside the button that
    // was just pressed is where they are actually read — the configurator may
    // be a column away, or a screen away on a phone.
    $buyBoxMessages = collect($buyBoxErrors)->flatten()->filter()->unique()->all();
@endphp
{{-- Multipart the moment a module has put controls in this form, and not
     before: one of those controls may be a file (a layout to print, a logo to
     engrave), and HTMX sends urlencoded unless it is told otherwise — a file
     input would then arrive as a bare filename. Cart learns nothing about what
     the controls are; it only knows that somebody else's fields are in its
     form, which is exactly the point at which the assumption "this posts three
     scalars" stops being safe. A plain product still posts what it always
     did. --}}
<div id="{{ $boxId }}" class="flex flex-col">
@if ($buyBoxMessages !== [])
    <div class="alert alert-error text-sm mb-3 flex-col items-start gap-1">
        @foreach ($buyBoxMessages as $buyBoxMessage)
            <span>{{ $buyBoxMessage }}</span>
        @endforeach
    </div>
@endif
<form id="{{ $boxId }}-form" hx-post="{{ route('cart.add', $product->slug) }}" hx-swap="none"
      @if ($buyBoxFields !== '') hx-encoding="multipart/form-data" enctype="multipart/form-data" @endif
      class="flex flex-col gap-4"
      x-data="{ qty: 1, max: {{ $max }}, step(d) { this.qty = Math.min(this.max, Math.max(1, (this.qty || 1) + d)) } }">
    @if ($buyBoxFields !== '')
        <div>{!! $buyBoxFields !!}</div>
    @endif

    <div class="flex items-stretch gap-3">
    <div class="join shrink-0">
        <button type="button" class="btn btn-lg join-item px-2.5 sm:px-3.5" x-on:click="step(-1)"
                x-bind:disabled="qty <= 1" @disabled(! $inStock) aria-label="{{ __('Decrease quantity') }}">
            <x-icon name="minus" size="sm" />
        </button>
        <input type="number" name="qty" x-model.number="qty" min="1" max="{{ $max }}" aria-label="{{ __('Quantity') }}"
               class="input input-lg join-item torgash-qty w-12 sm:w-16 px-0 text-center" @disabled(! $inStock)>
        <button type="button" class="btn btn-lg join-item px-2.5 sm:px-3.5" x-on:click="step(1)"
                x-bind:disabled="qty >= max" @disabled(! $inStock) aria-label="{{ __('Increase quantity') }}">
            <x-icon name="plus" size="sm" />
        </button>
    </div>
    {{-- `torgash-availability-{key}` — the EFFECTIVE key, not the raw column
         and not the label text, so a theme can style a Preorder button (or a
         depleted plain product, which reads as `out_of_stock` here even
         though the column still says `in_stock`) without matching on words. --}}
    <button type="submit" id="{{ $boxId }}-submit" class="btn btn-primary btn-lg flex-1 min-w-0 whitespace-nowrap torgash-availability torgash-availability-{{ $product->effectiveAvailabilityKey() }}" @disabled(! $inStock)>
        <x-icon name="shopping-bag" size="lg" /> {{ $label }}
    </button>
    </div>
</form>

{{-- The other ways to buy or to ask, immediately under the button and still
     inside the buy panel: "buy in one click" and "ask about this product" used
     to sit in `catalog.product.after_buy_box`, which is a zone BELOW the panel
     — a screenful away past whatever the shop had written there, which is
     exactly where nobody looked for them.

     OUTSIDE the form above, and that is not a detail: both are dialogs holding
     forms of their own, and a form inside a form is the trap this project has
     already been bitten by — the parser drops the inner tag and keeps its
     inputs, so the inner form's fields ride the outer submit. --}}
@php
    // Not inside the choice window (choose.blade.php): those actions are
    // dialogs of their own, and a dialog opened from a dialog is one too many.
    // `form` names the box's own form, so a way to buy that sits outside it
    // (one click, a dialog of its own) can take the same answers and
    // quantity along instead of buying the bare product.
    $buyBoxActions = ($buyBoxInDialog ?? false) ? '' : hook_filter('cart.buy_box.actions', '', ['product' => $product, 'form' => $boxId.'-form']);
    // ...and the SMALL ones, in one row under them (PLAN §38): keep it, compare
    // it, ask about it. Each module draws a quiet icon-and-word control —
    // `cart.buy_box.links` — and the row lays them out; the panel keeps ONE
    // button that looks like a button.
    $buyBoxLinks = ($buyBoxInDialog ?? false) ? '' : hook_filter('cart.buy_box.links', '', ['product' => $product]);
    // ...and under that row, a whole-width quiet control for what is still
    // secondary but too long for an icon and a word ("All variants of this
    // product", catalog's). Outside the box's form; optional, so isolated.
    $buyBoxBottom = ($buyBoxInDialog ?? false) ? '' : hook_render('cart.buy_box.bottom', ['product' => $product]);
@endphp
@if (trim($buyBoxActions) !== '')
    <div class="mt-3 flex flex-col gap-2">{!! $buyBoxActions !!}</div>
@endif
@if (trim($buyBoxLinks) !== '')
    <div class="torgash-buy-links mt-3 pt-3 border-t border-base-300 gap-1">{!! $buyBoxLinks !!}</div>
@endif
@if (trim($buyBoxBottom) !== '')
    <div class="mt-2 flex flex-col gap-2">{!! $buyBoxBottom !!}</div>
@endif

{{-- On a phone the button scrolls away under the photographs, the reviews and
     the description, so a strip over the tab bar carries the price and the
     button while the real one is off screen. It submits the SAME form
     (`form=`), so there is one quantity and one set of answers; a product
     that asks something first gets "choose" instead, which brings the box
     into view — a configuration is not something to buy blind. The price is
     the page's own (ShownPrice, "from" where there is a floor). Not in the
     choice window, which is the box already. --}}
@unless ($buyBoxInDialog ?? false)
    @php $buyBarPrice = app(\Torgash\Catalog\Pricing\ShownPrice::class)->format($product); @endphp
    <div x-data="{ away: false }"
         x-init="document.body.classList.add('torgash-has-buybar'); new IntersectionObserver(([entry]) => away = ! entry.isIntersecting).observe(document.getElementById('{{ $boxId }}-submit'))"
         x-show="away" x-cloak
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full opacity-0"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-end="translate-y-full opacity-0"
         class="torgash-buybar lg:hidden fixed inset-x-0 bottom-16 z-30 bg-base-100 border-t border-base-300 shadow-[0_-6px_16px_rgb(0_0_0/0.06)] px-4 py-2 flex items-center gap-3">
        <div class="min-w-0 grow">
            <div class="text-xs opacity-60 truncate">{{ $product->t('name') }}</div>
            <div class="text-lg font-bold leading-tight whitespace-nowrap">{{ $buyBarPrice }}</div>
        </div>
        @if ($buyBoxFields !== '')
            <button type="button" class="btn btn-primary btn-touch shrink-0"
                    x-on:click="document.getElementById('{{ $boxId }}').scrollIntoView({ behavior: 'smooth', block: 'center' })">
                {{ __('Choose') }}
            </button>
        @else
            <button type="submit" form="{{ $boxId }}-form" class="btn btn-primary btn-touch shrink-0 torgash-availability torgash-availability-{{ $product->effectiveAvailabilityKey() }}" @disabled(! $inStock)>
                <x-icon name="shopping-bag" /> {{ $label }}
            </button>
        @endif
    </div>
@endunless
</div>
