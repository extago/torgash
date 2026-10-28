{{-- One product card. Drawn by the grid and the carousel, which prime the
     whole set first (Support\ProductCards::prime()) and hand each card its
     share: $cardLabels, $cardShortDescription. $cardAttributes / $cardClass
     are the drawer's own additions to the cell — raw attributes, so they are
     never anything but template code.

     The cell is a DIV holding the link and, beside it, the price row: a buy
     button is a form, and a form (or any control) inside an <a> is invalid
     markup whose click is the link's. For the same reason the photograph's
     top-right corner (catalog.product.card_corner — the heart, and whatever
     else toggles a product into a list) is drawn OVER the figure from outside
     the link rather than inside it.

     On a screen with a mouse the card lifts on hover and, when it has a short
     description, opens it in a panel beneath itself ($cardHoverPanel = false
     keeps it in the card — the carousel does, it clips anything past its own
     height). A phone has no hover, so there it stays in the card. The rules
     are .torgash-card* in resources/css/app.css. --}}
@php
    $cardCorner = hook_render('catalog.product.card_corner', ['product' => $product]);
    // Small marks other modules put among the top-left labels (a sale's
    // countdown) — drawn isolated, so a broken one costs only itself.
    $cardFlags = hook_render('catalog.product.card_flags', ['product' => $product]);
    $cardPanel = ($cardHoverPanel ?? true)
        && ($cardShortDescription ?? '') !== ''
        && setting('torgash.catalog', 'card_description_on_hover');
@endphp
<div {!! $cardAttributes ?? '' !!} @if ($cardPanel) data-card-panel @endif
     class="torgash-card relative p-3 md:p-4 flex flex-col gap-1 {{ $cardClass ?? '' }}">
<a href="{{ route('catalog.product', $product->slug) }}" class="flex flex-col gap-1 flex-1">
    <figure class="aspect-square bg-base-200 relative mb-2">
        @include('torgash.catalog::partials.label-badges', [
            'labels' => $cardLabels, 'position' => 'list', 'product' => $product,
            'badgesRightInset' => $cardCorner !== '',
            'badgesExtra' => $cardFlags,
        ])
        {{-- 400: the catalogue card's box. Asking for a width is what
             makes the media module's thumbnails actually get used —
             without it this served the full-size original. --}}
        @if ($cardImage = $product->primaryImageUrl(400))
            {{-- The media row is already loaded (withCardData), so
                 asking it what it is called costs nothing — and an
                 SEO template gets to answer for a photo nobody
                 captioned. --}}
            @php
                $cardMedia = $product->getFirstMedia('gallery');
                // Loaded already (withCardData), so the rest of the gallery is
                // free to ask for; how many a card flips through is the shop's.
                $cardPhotos = $product->getMedia('gallery')->take(max(0, (int) setting('torgash.catalog', 'card_photos_max')))->values();
                $cardAlt = $cardMedia
                    ? (\Torgash\Media\Gallery::altFor($cardMedia, $product) ?: $product->t('name'))
                    : $product->t('name');
            @endphp
            {{-- The size asked for, as the box it is drawn in: without it
                 the browser lays the card out before it knows the picture's
                 shape (the figure is square, the picture fits inside it).
                 Absolute, because `aspect-square` is only a minimum: a tall
                 photo in the flow stretched its card (224×400 made a card
                 590px high beside square ones). --}}
            <img src="{{ $cardImage }}" alt="{{ $cardAlt }}" class="absolute inset-0 w-full h-full object-contain" width="400" height="400"
                 loading="lazy" decoding="async" @if ($cardPhotos->count() > 1) data-card-photo @endif>
            @if ($cardPhotos->count() > 1)
                {{-- The other photos, one strip of the figure each: moving the
                     pointer across it shows them in turn (card-photos.js, a
                     delegated listener, so a card that arrives over HTMX works
                     too), and the marks under it say how many there are and
                     which one this is. Spans inside the link, so a click on
                     any of them is still the product. Nothing is fetched until
                     the pointer arrives. --}}
                <span class="torgash-card-photos" aria-hidden="true">
                    @foreach ($cardPhotos as $cardPhotoIndex => $cardPhoto)
                        <span data-card-photo-zone="{{ $cardPhotoIndex }}"
                              data-src="{{ \Torgash\Media\Gallery::thumbUrl($cardPhoto, 400) }}"
                              data-alt="{{ \Torgash\Media\Gallery::altFor($cardPhoto, $product, $cardPhotoIndex) ?: $product->t('name') }}"></span>
                    @endforeach
                </span>
                <span class="torgash-card-marks" aria-hidden="true">
                    @foreach ($cardPhotos as $cardPhotoIndex => $cardPhoto)
                        <span @class(['is-active' => $cardPhotoIndex === 0])></span>
                    @endforeach
                </span>
            @endif
        @else
            <x-no-image class="w-full h-full" />
        @endif
    </figure>
    {{-- text-xs, same as the product page's own SKU line: an
         arbitrary 11px here made the identical label render at two
         sizes one scroll apart. --}}
    @if (setting('torgash.catalog', 'card_sku', true))
        <div class="text-xs uppercase opacity-50">{{ __('SKU') }}: {{ $product->sku }}</div>
    @endif
    {{-- Three lines on a phone: a card there is half the screen, and two
         lines cut "Ubiquiti UniFi Enterprise Firewall…" before the words
         that tell two models apart. --}}
    <div class="text-sm leading-snug line-clamp-3 md:line-clamp-2">{{ $product->t('name') }}</div>
    {{-- One line under the name for what another module knows about
         this product and a card should say — a rating today. A
         listener MUST answer for a whole page in one query (it is
         handed the set through catalog.products.listed above), or
         this line turns a grid of twelve into twelve more queries
         that nothing in the markup makes visible. --}}
    {{-- ONE row however many modules speak: the rating, a bonus, a video
         mark side by side, the way a shopper reads them — each listener
         printing its own block stacked them into a column. --}}
    @php $cardMeta = trim(hook_render('catalog.product.card_meta', ['product' => $product])); @endphp
    @if ($cardMeta !== '')
        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5">{!! $cardMeta !!}</div>
    @endif
    @if (($cardShortDescription ?? '') !== '')
        {{-- Two lines, clamped: past that a card stops being a card.
             How many characteristics reach it is a setting. --}}
        <div @class(['text-xs opacity-50 leading-snug line-clamp-2', 'torgash-card-inline' => $cardPanel])>{{ $cardShortDescription }}</div>
    @endif
    {{-- "In stock" is the shop's simplest possible answer, and a
         module that knows more may give a better one — "at the shop
         you picked", "not there, but we can bring it". Seeded with
         null rather than with the default markup, so a listener
         that has nothing to say is not forced to hand the sentence
         back unchanged. --}}
    @php $stockLine = hook_filter('catalog.product.stock_line', null, ['product' => $product]); @endphp
    @if ($stockLine !== null)
        {!! $stockLine !!}
    @else
        <div @class([
            'text-xs',
            'torgash-availability torgash-availability-'.$product->effectiveAvailabilityKey(),
            'torgash-in-stock' => $product->isOrderable(),
            'torgash-out-of-stock' => ! $product->isOrderable(),
        ])>
            {{ $product->stockLabel() }}
        </div>
    @endif
    {{-- The price the customer is quoted, which is not always the
         price stored: a shop whose taxes are ADDED at checkout can
         ask for them to be shown here, and a product that cannot be
         bought without answering something is quoted from its
         cheapest build rather than from a sum nobody can pay. Both
         answers come from one class, so the card, the page and the
         feed cannot disagree — see Pricing\ShownPrice. --}}
    @php
        $shownPrice = app(\Torgash\Catalog\Pricing\ShownPrice::class);
        $priceIsFrom = $shownPrice->isFrom($product);
    @endphp
</a>
    {{-- The price, and whatever another module offers to DO with the product
         from here — the basket's button (torgash.cart). Catalog knows nothing
         of carts; without such a module the row is the price alone. --}}
    {{-- A price is never cut: on a narrow card it is a size smaller, and one
         that still does not fit beside the button ("1 299 990 ₽" on a phone)
         sends the button to the line below (app.css, .torgash-card-buy). --}}
    @php $cardActions = hook_render('catalog.product.card_actions', ['product' => $product]); @endphp
    <div class="torgash-card-buy flex flex-wrap items-end justify-between gap-x-2 gap-y-1 mt-1">
        <a href="{{ route('catalog.product', $product->slug) }}" class="flex flex-col shrink-0 whitespace-nowrap" tabindex="-1">
            {{-- Not beside a "from": the struck-through number was the old
                 price of the bare product, and setting it against the
                 cheapest BUILD claims a discount that was never given. --}}
            @if (! $priceIsFrom && $product->discountPercent() > 0)
                <span class="text-xs torgash-old-price">{{ format_price($product->oldPrice()) }}</span>
            @endif
            <span class="text-lg font-bold leading-tight torgash-price">{{ $shownPrice->format($product) }}</span>
        </a>
        @if (trim($cardActions) !== '')
            <div class="ml-auto">{!! $cardActions !!}</div>
        @endif
    </div>
    @if ($cardCorner !== '')
        {{-- top-5/right-5 (md: 6): the card's padding plus the figure's own
             inset, the same corner a top-right label would take (which moves
             aside). The padding is 12px on a phone, as the page's own edge. --}}
        {{-- Which buttons stand is the theme's, per device (phone / tablet /
             computer): by default a phone gets the FIRST button (the heart:
             the wishlist answers first) with "…" under it opening the rest —
             three frosted squares covered a phone's small photo. The rules are
             app.css, .torgash-corner-more. --}}
        <div class="torgash-card-corner absolute top-5 right-5 md:top-6 md:right-6 z-10 flex flex-col gap-1.5"
             x-data="{ more: false }" x-bind:class="more && 'is-open'" x-on:click.outside="more = false">{!! $cardCorner !!}<button type="button" class="torgash-corner-more btn btn-ghost btn-sm btn-square btn-touch"
                    x-on:click="more = ! more" x-bind:aria-expanded="more" aria-expanded="false" aria-label="{{ __('More actions') }}">
                <span x-show="! more"><x-icon name="ellipsis" size="lg" /></span>
                <span x-show="more" x-cloak><x-icon name="x" size="lg" /></span>
            </button></div>
    @endif
    @if ($cardPanel)
        {{-- A second copy of the words for the pointer, not for a reader: the
             card's own copy is what a screen reader and a phone get. --}}
        <a href="{{ route('catalog.product', $product->slug) }}" class="torgash-card-panel" tabindex="-1" aria-hidden="true">
            <span class="line-clamp-6">{{ $cardShortDescription }}</span>
        </a>
    @endif
</div>
