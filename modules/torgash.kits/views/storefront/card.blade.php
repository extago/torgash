@php
    $kit = $offer['kit'];
    $main = $offer['main'];
@endphp
{{-- "Everything you need, in one set" — the owner's own card (PLAN §33.5).
     One form: the ticks are what is bought, the hidden inputs are which
     product fills each slot, and the totals are recomputed by the SERVER on
     every change, by exactly the code that will price the cart lines. --}}
{{-- `@container`, not the viewport. This card is placed by the shop: under
     the buy button it sits in a 400px column, and as a tab it is the width of
     the page. A `lg:` breakpoint asks how wide the SCREEN is, which is the
     wrong question — it laid the goods and the totals side by side inside
     that narrow column and pushed them off its edge (measured on a phone-width
     window). Everything below asks how wide THIS CARD is. --}}
<div class="card bg-base-100 border-2 border-primary/60 @container" id="kit-{{ $kit->id }}">
    <div class="card-body gap-4">
        <div>
            <h2 class="card-title text-lg text-primary">{{ $kit->t('name') }}</h2>
            @if ($kit->t('description'))
                <p class="text-sm opacity-70 mt-1">{{ $kit->t('description') }}</p>
            @endif
        </div>

        {{-- `hx-include` reaches the CONFIGURATOR on the same page.

             A kit is drawn under a product the customer may have just answered
             questions about, and pressing "add the set" buys that product, not
             a bare one. Two things follow: the price quoted here has to be the
             price charged, and a product whose questions are REQUIRED cannot
             be bought without them at all — before this the whole kit was
             silently refused and nothing reached the basket. Variations need
             nothing of the sort: picking one is a different PRODUCT with its
             own page, so the kit is already about the right one.

             Multipart because one of those answers can be a file (a layout, a
             logo). The selector matches nothing in a shop with no options, and
             then this is the plain form it always was. --}}
        <form id="kit-form-{{ $kit->id }}" class="flex flex-col @2xl:flex-row gap-6"
              hx-post="{{ route('kits.add', $kit) }}"
              hx-encoding="multipart/form-data"
              hx-include="#product-options"
              hx-swap="none">
            <input type="hidden" name="main" value="{{ $main->id }}">

            {{-- The goods, main first. Recomputing the totals is a POST of this
                 same form: a tick changes what is being bought, and only the
                 server knows what that costs — and so does answering a question
                 up in the buy panel, which is the second trigger.

                 GRID or one scrolling LINE, the shop's choice (Kit::LAYOUTS).
                 A grid is `items-start` on purpose: the cells are of different
                 heights (one name wraps to two lines, another does not) and
                 stretching them was what left the photographs on different
                 baselines. Every cell now begins with a row of the same height
                 — a tick for a slot, a word for the main product — so the
                 pictures line up whatever the names do. --}}
            @php $scroller = $kit->layout === 'scroller'; @endphp
            <div @class([
                    'flex-1 gap-3',
                    'flex items-start overflow-x-auto pb-2' => $scroller,
                    'flex flex-wrap items-start' => ! $scroller,
                 ])
                 hx-post="{{ route('kits.quote', $kit) }}"
                 hx-trigger="change, change from:#product-options"
                 hx-include="closest form, #product-options"
                 hx-target="#kit-total-{{ $kit->id }}"
                 hx-swap="innerHTML">
                @include('torgash.kits::storefront.item', [
                    'product' => $main,
                    'price' => $offer['main_price'],
                    'full' => $offer['main_price'],
                    'qty' => 1,
                    'slot' => null,
                    'kit' => $kit,
                    'main' => $main,
                    'scroller' => $scroller,
                    // What the customer answered above, named in their own
                    // words under the product it is about — the card says what
                    // it is buying rather than quietly charging for it.
                    'mainParts' => $offer['main_parts'] ?? [],
                ])

                @foreach ($offer['slots'] as $line)
                    {{-- The plus only means anything while the goods are in a
                         row; in a grid they wrap, and a stray + between two
                         cells reads as another item. --}}
                    @if ($scroller)
                        <div class="self-start mt-14 text-2xl opacity-30 shrink-0" aria-hidden="true">+</div>
                    @endif
                    @include('torgash.kits::storefront.item', [
                        'product' => $line['product'],
                        'price' => $line['price'],
                        'full' => $line['full'],
                        'qty' => $line['qty'],
                        'slot' => $line['slot'],
                        'required' => $line['required'],
                        'swappable' => $line['swappable'],
                        'kit' => $kit,
                        'main' => $main,
                        'scroller' => $scroller,
                    ])
                @endforeach
            </div>

            <div class="@2xl:w-64 shrink-0 flex flex-col gap-2 justify-center @2xl:border-s @2xl:ps-6 border-t @2xl:border-t-0 pt-4 @2xl:pt-0 border-base-300"
                 id="kit-total-{{ $kit->id }}">
                @include('torgash.kits::storefront.total', ['offer' => $offer])
            </div>
        </form>

        {{-- The window "choose another" fills, once per card and a SIBLING of
             the form rather than a child of it: its cards each carry a
             <select> of their own, and inside the form those would ride along
             on "add the set". Empty until a slot asks for it — one dialog
             serves every slot, because only one can be open at a time. --}}
        <dialog id="kit-picker-{{ $kit->id }}" class="modal">
            <div class="modal-box max-w-4xl">
                <form method="dialog">
                    <button class="btn btn-sm btn-circle btn-ghost absolute end-3 top-3" aria-label="{{ __('Close') }}">✕</button>
                </form>
                <h3 class="text-lg font-semibold mb-3">{{ __('Choose another') }}</h3>
                <div id="kit-picker-body-{{ $kit->id }}"></div>
            </div>
            <form method="dialog" class="modal-backdrop"><button>{{ __('Close') }}</button></form>
        </dialog>
    </div>
</div>
