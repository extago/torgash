@extends('layouts.storefront')

@section('content')
    <x-breadcrumbs />

    {{-- The heading — allowed to differ from <title>, see App\Core\Seo\PageMeta;
         with nothing said about it, it IS the title. Under it, the one line
         that says what this is: either
         what somebody wrote or the shop's own characteristics standing in for
         it. Which of the two is a setting, and the page is not told — see
         Torgash\Catalog\Support\ShortDescription. --}}
    @php
        $shortDescription = app(\Torgash\Catalog\Support\ShortDescription::class)->for($product);
        $productLabels = \Torgash\Catalog\Models\Label::forProducts(collect([$product]))[$product->id] ?? [];
    @endphp
    <div class="mb-4">
        {{-- The name across the whole page, and the product's code at its far
             end — what a customer reads out on the phone, so it sits where the
             eye ends the line rather than inside the price block. --}}
        <div class="flex flex-col sm:flex-row sm:items-baseline sm:justify-between gap-x-6 gap-y-1">
            <h1 class="text-2xl lg:text-3xl font-bold leading-tight">{{ page_meta()->h1() }}</h1>
            {{-- The code copies itself: it is read out on the phone and pasted
                 into a message far more often than it is typed. --}}
            <span class="text-sm whitespace-nowrap shrink-0 flex items-center gap-1.5" x-data="{ copied: false }">
                <span class="opacity-60">{{ __('SKU') }}:</span>
                <button type="button" class="group inline-flex items-center gap-1 font-medium hover:text-primary cursor-pointer"
                        title="{{ __('Copy the SKU') }}" aria-label="{{ __('Copy the SKU') }}: {{ $product->sku }}"
                        x-on:click="navigator.clipboard.writeText(@js($product->sku)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })">
                    <span class="opacity-50 group-hover:opacity-100" x-show="! copied"><x-icon name="copy" size="sm" /></span>
                    <span class="text-success" x-show="copied" x-cloak><x-icon name="check" size="sm" /></span>
                    {{ $product->sku }}
                </button>
            </span>
        </div>
        @include('torgash.catalog::partials.label-row', ['labels' => $productLabels, 'labelRow' => 'above-description', 'labelRowClass' => 'mt-2'])
        @if ($shortDescription !== '')
            <p class="mt-1.5 text-sm opacity-70 max-w-3xl">{{ $shortDescription }}</p>
        @endif
    </div>

    {{-- The shop's own blocks, full width: under the heading, and at the very
         bottom. Placed by display rules with the product in the context, so
         "product category is in…" and friends answer about this product. --}}
    @php $productZoneTop = zone('catalog.product.top', ['product' => $product]); @endphp
    @if (trim($productZoneTop) !== '')
        <div class="mb-4 flex flex-col gap-4">{!! $productZoneTop !!}</div>
    @endif

    @php
        // Three sizes per photo, because the places that show it are orders of
        // magnitude apart: a 64px thumbnail, a 520px stage, the full-screen
        // viewer. Serving one file to all was what made the page pull
        // full-size originals for the strip.
        $galleryUrls = $product->galleryImages()
            ->values()
            ->map(fn ($m, $i) => [
                'thumb' => \Torgash\Media\Gallery::thumbUrl($m, 120),
                'stage' => \Torgash\Media\Gallery::thumbUrl($m, 800),
                // The viewer's: the stored file itself (already capped by the
                // media settings), with its real size so nothing is measured.
                'full' => $m->getUrl(),
                ...\Torgash\Media\Gallery::dimensions($m),
                // What a reader who cannot see the picture is told: the typed
                // caption, an SEO template's answer, or the product's name.
                'alt' => \Torgash\Media\Gallery::altFor($m, $product, $i) ?: $product->t('name'),
            ]);
        if ($galleryUrls->isEmpty()) {
            // Pictures by external link (catalog setting, off by default).
            $galleryUrls = collect($product->externalImageUrls())
                ->map(fn ($externalUrl) => ['thumb' => $externalUrl, 'stage' => $externalUrl, 'full' => $externalUrl, 'width' => null, 'height' => null, 'alt' => $product->t('name')]);
        }
        // What else stands in the strip beside the photos — a video module's
        // clips. Such an item says `video => true`, brings its poster as
        // `thumb`/`stage` (either may be null) and its own `player` markup,
        // which the gallery puts on the page only when play is pressed. The
        // catalogue never learns what plays it.
        $galleryUrls = collect(hook_filter('catalog.product.gallery', $galleryUrls->all(), ['product' => $product]))->values();
        // See the note in partials/product-grid.blade.php: display price, which
        // only differs from the stored one where a module adds tax to it.
        $effectivePrice = hook_filter('catalog.price.display', $product->effectivePrice(), ['product' => $product]);
        // The headline price and whether it is only a floor — one class, the
        // same one the card and the feed ask (Pricing\ShownPrice). The bare
        // $effectivePrice above stays what it is: the club-price row and the
        // labels below compare against the product's own number, not against
        // the cheapest build.
        $shownPrice = app(\Torgash\Catalog\Pricing\ShownPrice::class);
        $priceIsFrom = $shownPrice->isFrom($product);
        $priceLabel = $product->effectivePriceLabel();
        $oldPrice = $product->oldPrice();
        $discountPercent = $product->discountPercent();
        // In the admin's own order, under the admin's own sections — jsonb does
        // not keep key order, so this is the only place that knows it.
        $specFeatures = $product->featureRows();
        // Where a catalogued characteristic is drawn is the shop's own answer,
        // per characteristic (Features\Places): the same row can belong in the
        // specifications, in the short list under the buy button, in both, or
        // in neither while still being what a published address filters by.
        $shownSpecs = $product->featureAssignments->filter(fn ($pf) => $pf->feature && $pf->display() !== null);
        $globalSpecs = $shownSpecs->filter(fn ($pf) => $pf->feature->shows('specs'));
        $buyBoxSpecs = $shownSpecs->filter(fn ($pf) => $pf->feature->shows('buy_box'))
            ->take(max(0, (int) setting('torgash.catalog', 'buy_box_specs_max', 4)));
        $hasSpecs = ! empty($specFeatures) || $globalSpecs->isNotEmpty();

        // What stands under the gallery, as DATA — the page's own two sections,
        // whatever the shop has written, and whatever a module adds through
        // `catalog.product.tabs`. Which of the two LAYOUTS draws it (a strip of
        // tabs, or sections stacked under their own headings) is a setting, and
        // the list does not know: see Torgash\Catalog\Support\ProductSections.
        $sections = app(\Torgash\Catalog\Support\ProductSections::class);
        // The description's surroundings, asked ONCE: what stands above and
        // below its text (two zones), and what a module puts INSTEAD of the
        // text — a landing that the page shows while the description itself
        // stays the product's for feeds, the .md page and the meta tags. The
        // section is there when any of them has something to say, so a
        // product with a landing and no text still has its "Description".
        $descriptionParts = [
            'before' => zone('catalog.product.before_description', ['product' => $product]),
            'instead' => hook_render('catalog.product.description_instead', ['product' => $product]),
            'after' => zone('catalog.product.after_description', ['product' => $product]),
        ];
        $hasDescriptionSection = $product->hasDescription() || trim(implode('', $descriptionParts)) !== '';
        $detailTabs = $sections->for($product, ['description' => $hasDescriptionSection, 'specs' => $hasSpecs]);
        $detailsAsSections = $sections->asSections();
        $firstTabKey = $detailTabs[0]['key'] ?? null;
    @endphp

    {{--
        Layout, and why it is shaped like this (PLAN §38, after tac-defense).

        Desktop: the name across the page, then two columns that do not know
        each other's height. Left: gallery → the wide row (a kit) → description
        and the rest. Right: the buy panel → whatever the shop puts under it.

        Phone: one column, in the order a customer needs it — photo → buy panel
        → the wide row → description → what sits under the panel. Both columns
        are display:contents there, so their pieces are direct children of one
        flex column and order-* places them without anything being rendered
        twice.
    --}}
    {{-- TWO INDEPENDENT COLUMNS (PLAN §38): each is its own flex column, so
         the left one continues right under the gallery however tall the right
         one is. The grid ROWS this used before tied them together — the right
         column spanned three rows and the left one waited for it, which is the
         hole that opened under the gallery. On a phone both columns are
         display:contents and one flex column orders the pieces: photo → buy
         panel → the wide row → description → what sits under the panel. --}}
    <div class="torgash-product-layout flex flex-col gap-4 lg:grid lg:grid-cols-[minmax(0,1fr)_400px] lg:gap-6 lg:items-start">
        <div class="contents lg:flex lg:flex-col lg:gap-4 lg:min-w-0">
        {{-- Gallery. The image area is height-capped rather than aspect-square: at a
             1500px container this column is ~1050px wide, so a square would be a
             screen tall and push everything else below the fold. --}}
        <div class="order-1 lg:order-none bg-base-100 border border-base-300 rounded-box p-4">
            @if ($galleryUrls->isEmpty())
                <div class="h-[380px] lg:h-[520px] flex items-center justify-center relative">
                    @include('torgash.catalog::partials.label-badges', ['labels' => $productLabels, 'position' => 'detail', 'product' => $product])
                    <x-no-image class="w-full h-full rounded-box" size="lg" />
                </div>
            @else
                @include('torgash.catalog::partials.product-gallery', ['galleryItems' => $galleryUrls->all()])
            @endif
        </div>

        {{-- Everything under the gallery: description, characteristics and
             whatever the shop has written — as tabs or as sections, which is a
             setting. Left column under the gallery on desktop, third in flow on
             mobile. --}}
        {{-- The WIDE area under the gallery, above the tabs: for anything a
             400px column cannot hold. A kit is the reason — four products side
             by side with a totals column beside them is a row, and the sticky
             panel is not wide enough for a row, so it ran off the card's own
             edge. This is a third place a module can put something on a
             product page, and the three mean three different widths: the buy
             panel is narrow, `after_buy_box` is under it and just as narrow,
             and this is the width of the left column.

             On a phone there is one column and this simply falls between the
             buy panel and the description, which is the same place it is in on
             a desktop. --}}
        @php $beforeDetails = zone('catalog.product.before_details', ['product' => $product]); @endphp
        @if (trim($beforeDetails) !== '')
            <div class="order-3 lg:order-none flex flex-col gap-4">{!! $beforeDetails !!}</div>
        @endif

        @include('torgash.catalog::partials.product-details')
        </div>

        {{-- Right column: the buy panel and what the shop puts under it
             (delivery, payment, a banner). Not sticky — it is often taller than
             the screen, and a sticky column taller than the screen hides its own
             bottom until the page ends. It is STRETCHED to the left column's
             height instead, so the small card at its end has the rest of the
             page to stick in. --}}
        <div class="contents lg:flex lg:flex-col lg:gap-4 lg:self-stretch">

        <div id="product-buy-panel" class="order-2 lg:order-none bg-base-100 border border-base-300 rounded-box p-5 flex flex-col gap-5">
            <div>
                {{-- What another module can say about this product in one
                     line — the rating — at the top of the panel, over the
                     price (the owner's call, after tac-defense): the eye goes
                     to the price, and the stars are read on the way. The hook
                     keeps its old name; it is still the line about the thing. --}}
                @php
                    $titleMeta = hook_filter('catalog.product.title_meta', '', ['product' => $product]);
                    // The brand's logo on the same line, at its far end — who
                    // makes it is read beside how it is rated, and leads to the
                    // rest of the brand's goods. Nothing without a logo: the
                    // brand's NAME is already in the characteristics.
                    $brand = setting('torgash.catalog', 'brand_logo_on_product') ? $product->brandValue() : null;
                    $brandLogo = $brand?->logoUrl();
                @endphp
                @if ($titleMeta !== '' || $brandLogo)
                    <div class="mb-3 flex items-center justify-between gap-3 min-h-8">
                        <div class="min-w-0">{!! $titleMeta !!}</div>
                        @if ($brandLogo)
                            @php $brandName = (string) $brand->display(); @endphp
                            @if ($brand->slug)
                                <a href="{{ route('catalog.brands.show', $brand->slug) }}" class="shrink-0 opacity-90 hover:opacity-100"
                                   title="{{ __('All :brand products', ['brand' => $brandName]) }}">
                                    <img src="{{ $brandLogo }}" alt="{{ $brandName }}" class="torgash-brand-logo h-8 w-auto max-w-32 object-contain">
                                </a>
                            @else
                                <img src="{{ $brandLogo }}" alt="{{ $brandName }}" class="torgash-brand-logo shrink-0 h-8 w-auto max-w-32 object-contain">
                            @endif
                        @endif
                    </div>
                @endif
                {{-- "from", where this product cannot be bought without
                     answering something that costs money: the configurator
                     below prices the build as it is answered, and this is the
                     same number it will show for the cheapest one. The old
                     price and the discount badge go with it — both are about
                     the bare product, and neither is a fact about the
                     cheapest build. --}}
                <div class="text-3xl font-bold leading-none torgash-price">{{ $shownPrice->format($product) }}</div>
                @if (! $priceIsFrom && $discountPercent > 0)
                    {{-- One line under the price: what it was, by how much, and
                         the discount as MONEY — "you save 640" is what a customer
                         compares with the next shop, a percent is not. --}}
                    <div class="flex items-center gap-x-2.5 gap-y-1 flex-wrap mt-2 text-sm">
                        <span class="torgash-old-price">{{ format_price($oldPrice) }}</span>
                        <span class="badge torgash-discount badge-sm font-semibold">&minus;{{ $discountPercent }}%</span>
                        @if ($oldPrice > $effectivePrice)
                            <span class="torgash-discount-text font-medium">{{ __('You save :amount', ['amount' => format_price($oldPrice - $effectivePrice)]) }}</span>
                        @endif
                    </div>
                @endif
                @if (! $priceIsFrom && $discountPercent > 0 && $priceLabel)
                    <div class="text-sm text-success font-medium mt-1">{{ __(':type price', ['type' => $priceLabel]) }}</div>
                @endif
                {{-- The Omnibus line (PLAN §36): the lowest public price of the
                     window before the current price began — said only beside a
                     discount, and only when the history actually knows it. --}}
                @if (! $priceIsFrom && $discountPercent > 0 && $product->prior_lowest_price !== null && setting('torgash.catalog', 'lowest_price_note'))
                    <div class="text-xs opacity-60 mt-1" data-lowest-price>
                        {{ __('Lowest price in the :days days before the discount: :price', [
                            'days' => app(\Torgash\Catalog\Pricing\PriceHistory::class)->days(),
                            'price' => format_price($product->prior_lowest_price),
                        ]) }}
                    </div>
                @endif

                {{-- Club price: advertised publicly to every visitor (not gated by the
                     price type's own "requires login" flag — that governs who can
                     actually redeem it, not who can see the number), only when it's
                     genuinely cheaper than what's already showing above. --}}
                @foreach (setting('torgash.catalog', 'club_price_row', true) ? ($clubPriceTypes ?? []) : [] as $clubType)
                    @php $clubPrice = $product->priceForType($clubType); @endphp
                    @continue($clubPrice >= $effectivePrice)
                    <div class="flex items-center gap-2 mt-2 px-3 py-2 rounded-box bg-accent/10 border border-accent/20">
                        <x-icon name="gem" size="sm" class="text-accent shrink-0" />
                        <span class="text-sm font-medium text-accent">{{ $clubType->t('name') }}: {{ format_price($clubPrice) }}</span>
                        @if ($clubType->t('storefront_note'))
                            {{-- The note is a question ("How to get this
                                 price?"), so it opens the answer: who buys at
                                 it — the price type's own groups and sign-in
                                 flag — and, for a guest, the way in. --}}
                            @php
                                $clubGroups = $clubType->customerGroups->map(fn ($group) => $group->t('name'))->filter()->implode(', ');
                                $clubMine = $clubType->visibleTo(auth()->user());
                            @endphp
                            <x-popover class="badge badge-ghost badge-sm ms-auto cursor-pointer hover:bg-base-300 underline decoration-dotted underline-offset-2"
                                       panel-class="w-72 p-4 text-sm flex flex-col gap-2">
                                <p class="font-semibold">{{ $clubType->t('name') }}: {{ format_price($clubPrice) }}</p>
                                @if ($clubMine)
                                    <p>{{ __('This price is yours: it is what you pay at checkout.') }}</p>
                                @else
                                    @if ($clubGroups !== '')
                                        <p>{{ __('For customers in the group: :groups.', ['groups' => $clubGroups]) }}</p>
                                    @elseif ($clubType->requires_auth)
                                        <p>{{ __('For every customer with an account.') }}</p>
                                    @endif
                                    @guest
                                        <div class="flex gap-2 mt-1">
                                            <a href="{{ route('account.login') }}" class="btn btn-primary btn-sm">{{ __('Sign in') }}</a>
                                            <a href="{{ route('account.register') }}" class="btn btn-ghost btn-sm">{{ __('Create account') }}</a>
                                        </div>
                                    @endguest
                                    @if ($clubGroups !== '')
                                        <p class="text-xs opacity-60">{{ __('To join the group, ask us — the contacts are at the top of the page.') }}</p>
                                    @endif
                                @endif
                                <x-slot:trigger>{{ $clubType->t('storefront_note') }}</x-slot:trigger>
                            </x-popover>
                        @endif
                    </div>
                @endforeach

                {{-- Extension point for anything that wants to sit near the price
                     but isn't tied to a specific price type (e.g. a "price valid
                     until" note) — append-only, same idiom as after_buy_box. --}}
                @php $priceExtra = hook_filter('catalog.product.price_extra', '', ['product' => $product]); @endphp
                @if (trim($priceExtra) !== '')
                    {!! $priceExtra !!}
                @endif

                <div class="flex items-center gap-3 mt-3 flex-wrap">
                    {{-- `torgash-availability-{key}` is the hook a theme's own CSS
                         targets — the EFFECTIVE key (a depleted "in stock" reads
                         as `out_of_stock`), never the raw column and never the
                         translated label text, both of which can say something
                         this badge does not. --}}
                    <span @class([
                        'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium',
                        'torgash-availability torgash-availability-'.$product->effectiveAvailabilityKey(),
                        'torgash-in-stock-chip' => $product->isOrderable(),
                        'bg-base-300 torgash-out-of-stock' => ! $product->isOrderable(),
                    ])>
                        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                        {{ $product->stockLabel() }}
                    </span>
                </div>

                {{-- Optional per-warehouse breakdown, or any other availability
                     detail a module wants to add — torgash.warehouses is the
                     first (and optional) consumer of this. --}}
                @php $availabilityExtra = hook_filter('catalog.product.availability', '', ['product' => $product]); @endphp
                @if (trim($availabilityExtra) !== '')
                    {!! $availabilityExtra !!}
                @endif
            </div>

            @php
                $visibleTiers = $product->priceTiers
                    ->filter(fn ($t) => $t->customer_group_id === null || $t->customer_group_id === auth()->user()?->customer_group_id)
                    ->sortBy('min_qty');
            @endphp
            @if ($visibleTiers->isNotEmpty())
                <div class="flex flex-col gap-1 pt-3 border-t border-base-300 text-sm">
                    <div class="text-xs uppercase tracking-wide opacity-50 mb-1">{{ __('Wholesale prices') }}</div>
                    @foreach ($visibleTiers as $tier)
                        <div class="flex justify-between">
                            <span class="opacity-70">
                                {{ __('From :qty', ['qty' => $tier->min_qty]) }}
                                @if ($tier->customerGroup)
                                    &middot; {{ $tier->customerGroup->t('name') }}
                                @endif
                            </span>
                            <span class="font-semibold">{{ format_price($tier->price) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if (! empty($variations))
                {{-- The group's pickers, drawn the way each characteristic
                     says (Feature::productStyle()). --}}
                @include('torgash.catalog::partials.variation-picker', ['pickerAxes' => $variations])
            @endif

            <div class="pt-4 border-t border-base-300">
                {!! hook_filter('catalog.product.buy_box', view('torgash.catalog::partials.default-buy-box')->render(), ['product' => $product]) !!}
                @include('torgash.catalog::partials.label-row', ['labels' => $productLabels, 'labelRow' => 'under-buy', 'labelRowClass' => 'mt-4'])
            </div>

            @if ($buyBoxSpecs->isNotEmpty())
                {{-- The short list the shop has chosen for this panel. Not "the
                     first four specs": which characteristics belong beside a buy
                     button is a decision, taken on the characteristic itself,
                     and the number is only how many fit in 400px. --}}
                <div class="pt-4 border-t border-base-300 flex flex-col divide-y divide-base-300">
                    @foreach ($buyBoxSpecs as $pf)
                        <div class="flex justify-between gap-3 py-2 text-sm">
                            <span class="opacity-60">{{ $pf->feature->t('name') }}</span>
                            <span class="font-medium text-right">{{ $pf->display() }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Extension point: modules append cards under the buy panel, in the
             same right column (e.g. torgash.motivation's accordion, a banner
             block) — append pattern, not override; see catalog.product.buy_box
             above for that. Last on a phone, after the description. --}}
        @php $afterBuyBox = hook_filter('catalog.product.after_buy_box', '', ['product' => $product]); @endphp
        @if (trim($afterBuyBox) !== '')
            <div class="order-5 lg:order-none flex flex-col gap-4">{!! $afterBuyBox !!}</div>
        @endif

        {{-- The product in small — picture, name, price, the buy button — at
             the end of the right column, sticking under the header (and the
             section bar) for the rest of the page. Shown only once the buy
             panel has gone off the top: two buy buttons on one screen are one
             too many. Its button drives the PANEL's own form (whatever module
             drew it), so there is one set of controls: a form that still wants
             an answer (an option to choose) is scrolled to instead of sent.
             A computer's thing; a phone has its own bar. A setting. --}}
        @if (setting('torgash.catalog', 'sticky_buy_card', true))
            <div class="torgash-buy-mini hidden lg:block"
                 x-data="{
                     shown: false,
                     buy() {
                         const panel = document.getElementById('product-buy-panel');
                         const form = panel?.querySelector('form');
                         if (form && form.checkValidity()) {
                             form.requestSubmit();
                             return;
                         }
                         panel?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                         form?.reportValidity();
                     },
                 }"
                 x-init="new IntersectionObserver(([entry]) => { shown = ! entry.isIntersecting && entry.boundingClientRect.top < 0 })
                     .observe(document.getElementById('product-buy-panel'))">
                <div x-show="shown" x-cloak x-transition.opacity.duration.200ms
                     class="bg-base-100 border border-base-300 rounded-box p-4 flex flex-col gap-3 shadow-sm">
                    <div class="flex gap-3 items-center">
                        @if ($buyMiniImage = $product->primaryImageUrl(120))
                            <img src="{{ $buyMiniImage }}" alt="" class="w-16 h-16 object-contain rounded-field bg-base-200 shrink-0" loading="lazy">
                        @endif
                        <div class="min-w-0">
                            <div class="text-sm font-medium leading-snug line-clamp-2">{{ $product->t('name') }}</div>
                            <div class="flex items-baseline gap-2 mt-1 flex-wrap">
                                <span class="text-lg font-bold torgash-price">{{ $shownPrice->format($product) }}</span>
                                @if (! $priceIsFrom && $discountPercent > 0)
                                    <span class="text-sm torgash-old-price">{{ format_price($oldPrice) }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    @if ($product->isOrderable())
                        <button type="button" class="btn btn-primary w-full" x-on:click="buy()">
                            <x-icon name="shopping-bag" size="sm" /> {{ __('Add to cart') }}
                        </button>
                    @else
                        <span class="text-sm torgash-out-of-stock">{{ $product->stockLabel() }}</span>
                    @endif
                </div>
            </div>
        @endif

        </div>

    </div>

    @php $related = $product->relatedProducts()->visible()->withCardData()->get(); @endphp
    @if ($related->isNotEmpty())
        <div class="mt-8">
            <h2 class="text-xl font-bold mb-4">{{ __('You may also like') }}</h2>
            @include('torgash.catalog::partials.product-grid', ['products' => $related])
        </div>
    @endif

    @php $productZoneBottom = zone('catalog.product.bottom', ['product' => $product]); @endphp
    @if (trim($productZoneBottom) !== '')
        <div class="mt-8 flex flex-col gap-4">{!! $productZoneBottom !!}</div>
    @endif
@endsection
