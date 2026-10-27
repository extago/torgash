{{--
    The basket: its lines on the left, the sum and the way on on the right.
    Swapped whole into #cart-contents by every action on the page (a quantity,
    the bin, the coupon, "clear"), so what the total says and what the lines
    say always come from one render.

    Every control is a real FORM that also works with no script (the
    controller answers a plain post with a redirect back here); HTMX only makes
    it quick. Delivery is not asked here — it is asked at checkout, where the
    address is asked anyway (the shipping module's `cart_estimate` brings the
    old estimate back for a shop that wants it).
--}}
@php $items = $cart->items(); @endphp
@if ($items->isEmpty())
    {{-- An empty basket is a way back into the shop, not a dead end. --}}
    <div class="bg-base-100 border border-base-300 rounded-box px-6 py-14 flex flex-col items-center text-center gap-3">
        <span class="w-16 h-16 rounded-full bg-base-200 flex items-center justify-center opacity-70"><x-icon name="shopping-bag" size="xl" /></span>
        <div class="text-lg font-semibold">{{ __('Your cart is empty.') }}</div>
        <a href="{{ route('catalog.index') }}" class="btn btn-primary mt-2">{{ __('Browse products') }}</a>
    </div>
@else
    <div class="grid lg:grid-cols-[1fr_24rem] gap-6 items-start">
        <div class="bg-base-100 border border-base-300 rounded-box divide-y divide-base-300">
            @foreach ($items as $item)
                @php
                    // Same reasoning as the buy box: only a status that GATES on
                    // stock caps the stepper by the shelf — a preorder or an
                    // untracked product gets a plain generous ceiling instead of
                    // one tied to a number that was never a promise (PLAN §31.3).
                    $lineStockGates = app(\Torgash\Catalog\Stock\AvailabilityStatuses::class)
                        ->gatesOnStock((string) $item->product->availability_status);
                    $lineMax = $lineStockGates ? max(1, $item->product->stock) : 999;
                    $lineUrl = route('catalog.product', $item->product->slug);
                @endphp
                {{-- One line, one shape at every width: the photo, then the
                     words with what can be done to the line right under them
                     (the bin, the heart — small, they are not what the page is
                     for), then the quantity and the sum. On a phone the last
                     two drop to a row of their own under the words, the
                     stepper on the left where the thumb is and the sum on the
                     right where a column of sums is read; from `sm` they stand
                     beside the words. The stepper is the catalogue row's own
                     (list-buy) — white, framed, 36px buttons — so the basket
                     speaks the shop's language instead of one of its own. --}}
                <div class="p-4 sm:p-5 flex gap-4 sm:gap-5">
                    {{-- The photo and the "no photo" plate in the same frame,
                         so a line without a picture is not a different shape. --}}
                    <a href="{{ $lineUrl }}" class="shrink-0 size-20 sm:size-24 rounded-box bg-base-200 p-1.5 flex items-center justify-center overflow-hidden">
                        @if ($lineImage = $item->product->primaryImageUrl(160))
                            <img src="{{ $lineImage }}" alt="{{ $item->product->t('name') }}" class="object-contain h-full w-full mix-blend-multiply"
                                 loading="lazy" decoding="async">
                        @else
                            <x-no-image class="w-full h-full rounded" size="sm" />
                        @endif
                    </a>

                    <div class="flex-1 min-w-0 flex flex-col sm:flex-row gap-3 sm:gap-6">
                    <div class="flex-1 min-w-0 flex flex-col">
                        <a href="{{ $lineUrl }}" class="font-medium leading-snug hover:text-primary line-clamp-2">{{ $item->product->t('name') }}</a>
                        <div class="text-sm opacity-50 mt-1">{{ __('SKU') }}: {{ $item->product->sku }}</div>
                        @if ($item->problem)
                            {{-- The stock fell, or the product was closed, while
                                 it waited here: said on the line, before the
                                 checkout refuses it. --}}
                            <div class="text-sm text-error mt-1.5 flex items-start gap-1.5">
                                <x-icon name="circle-alert" size="sm" class="mt-0.5 shrink-0" />
                                <span>{{ $item->problem }}</span>
                            </div>
                        @endif

                        {{-- What is INSIDE this line — the processors, the
                             licences, the installation (PLAN §33.8): one line
                             with its contents, never a row per part, so nobody
                             can take the processor out of the server by
                             accident. Its price is already in the line total. --}}
                        @if ($item->parts !== [])
                            <ul class="mt-2 flex flex-col gap-0.5 text-sm">
                                @foreach ($item->parts as $part)
                                    <li class="flex gap-1.5 items-baseline">
                                        <span class="opacity-40">↳</span>
                                        <span class="opacity-75">
                                            @if ($part['url'])
                                                <a href="{{ $part['url'] }}" class="link link-hover">{{ $part['label'] }}</a>
                                            @else
                                                {{ $part['label'] }}
                                            @endif
                                        </span>
                                        @if ($part['qty'] > 1)
                                            <span class="opacity-50 whitespace-nowrap">
                                                ×{{ $part['qty'] }}{{ $part['per_unit'] && $item->qty > 1 ? ' '.__('per item') : '' }}
                                            </span>
                                        @endif
                                        @if ($part['amount'] !== 0)
                                            {{-- A part may take money OFF — a kit's discount is a
                                                 negative part — so the sign is read from the
                                                 amount rather than assumed. --}}
                                            <span class="opacity-50 whitespace-nowrap">
                                                {{ $part['amount'] > 0 ? '+' : '−' }}{{ format_price(abs($part['amount'] * $part['qty']), exact: true) }}
                                            </span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            {{-- Editing a build means answering the same questions
                                 again, which is the product page with this
                                 line's answers in it. --}}
                            <a href="{{ route('catalog.product', ['slug' => $item->product->slug, 'line' => $item->key]) }}"
                               class="link link-hover text-sm opacity-60 mt-1 inline-block">{{ __('Change this build') }}</a>
                        @endif

                        {{-- Whatever a module puts here (the wishlist's heart,
                             "buy it for points") and the bin last —
                             `cart.line.actions`, so this module never learns
                             what a wishlist is. Pulled left by the buttons' own
                             padding, so the first icon lines up with the words. --}}
                        <div class="flex flex-wrap items-center gap-1 mt-2 sm:mt-auto sm:pt-2 -ms-2">
                            {!! hook_filter('cart.line.actions', '', ['product' => $item->product, 'line' => $item]) !!}
                            <form method="POST" action="{{ route('cart.remove', $item->key) }}"
                                  hx-delete="{{ route('cart.remove', $item->key) }}" hx-target="#cart-contents" hx-swap="innerHTML">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-ghost btn-sm btn-touch btn-square opacity-60 hover:opacity-100 hover:text-error"
                                        title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}: {{ $item->product->t('name') }}">
                                    <x-icon name="trash-2" size="sm" />
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-4 sm:items-start sm:justify-end sm:gap-6">
                        {{-- The quantity: − / typed / +, one form. The buttons send
                             `step`, the box sends `qty` — see the controller. --}}
                        <form method="POST" action="{{ route('cart.update', $item->key) }}"
                              hx-patch="{{ route('cart.update', $item->key) }}" hx-target="#cart-contents" hx-swap="innerHTML"
                              class="flex items-center rounded-field border border-base-300 bg-base-100">
                            @csrf
                            @method('PATCH')
                            {{-- Enter in the box presses the FIRST submit button of
                                 the form; without this one that would be "−". --}}
                            <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true"></button>
                            <button name="step" value="-1" class="btn btn-sm btn-touch btn-square btn-ghost border-0 shadow-none" @disabled($item->qty <= 1)
                                    aria-label="{{ __('One fewer') }}"><x-icon name="minus" /></button>
                            <input type="number" name="qty" value="{{ $item->qty }}" min="1" max="{{ $lineMax }}" inputmode="numeric"
                                   aria-label="{{ __('Quantity') }}"
                                   data-submit-on-change
                                   class="w-10 bg-transparent border-0 p-0 text-center text-sm tabular-nums font-medium focus:outline-none torgash-qty">
                            <button name="step" value="1" class="btn btn-sm btn-touch btn-square btn-ghost border-0 shadow-none" @disabled($item->qty >= $lineMax)
                                    aria-label="{{ __('One more') }}"><x-icon name="plus" /></button>
                        </form>

                        {{-- The line's sum, and what one costs when there are
                             several — under it, where the eye checks the sum. --}}
                        <div class="text-end sm:w-32 sm:pt-1">
                            <div class="text-lg font-semibold whitespace-nowrap tabular-nums leading-tight">{{ format_price($item->line_total, exact: true) }}</div>
                            @if ($item->qty > 1)
                                <div class="text-xs opacity-50 whitespace-nowrap mt-0.5">{{ __(':price each', ['price' => format_price($item->unit_price, exact: true)]) }}</div>
                            @endif
                        </div>
                    </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex flex-col gap-3 lg:sticky lg:top-[calc(var(--torgash-header-h)+1.5rem)]">
            <div class="bg-base-100 border border-base-300 rounded-box p-5 sm:p-6 flex flex-col gap-4">
                {{-- What moves the total (a coupon, a tax line) stands above it. --}}
                {!! hook_filter('cart.rows_before_total', '', ['cart' => $cart, 'subtotal' => $cart->total()]) !!}

                <div class="flex justify-between items-baseline gap-4">
                    <span class="text-xl font-semibold">{{ __('Total') }}</span>
                    <span class="text-2xl font-bold tabular-nums">{{ format_price(hook_filter('cart.total', $cart->total(), ['cart' => $cart]), exact: true) }}</span>
                </div>
                <div class="text-sm opacity-60 -mt-3 text-end">
                    {{ trans_choice('{1}:count item|[2,*]:count items', $items->sum('qty'), ['count' => $items->sum('qty')]) }}
                </div>

                {!! hook_filter('cart.summary_after_total', '', ['cart' => $cart]) !!}

                @if ($items->contains(fn ($cartLine) => $cartLine->problem !== null))
                    <p class="text-sm text-error flex items-start gap-1.5">
                        <x-icon name="circle-alert" size="sm" class="mt-0.5 shrink-0" />
                        <span>{{ __('Some lines cannot be ordered as they stand — they are marked in the basket.') }}</span>
                    </p>
                @endif

                @if (\Illuminate\Support\Facades\Route::has('checkout.create'))
                    <a href="{{ route('checkout.create') }}" class="btn btn-primary btn-lg w-full">{{ __('Proceed to checkout') }}</a>
                @else
                    <button class="btn btn-disabled btn-lg w-full" disabled>{{ __('Checkout coming soon') }}</button>
                @endif

                {{-- Other ways to buy what is here ("the whole cart in one
                     click") — whoever offers them draws them. --}}
                {!! hook_filter('cart.summary_actions', '', ['cart' => $cart]) !!}
            </div>

            <form method="POST" action="{{ route('cart.clear') }}"
                  hx-delete="{{ route('cart.clear') }}" hx-target="#cart-contents" hx-swap="innerHTML"
                  hx-confirm="{{ __('Remove everything from the cart?') }}" class="text-center">
                @csrf
                @method('DELETE')
                <button class="btn btn-ghost btn-sm font-normal opacity-60 hover:opacity-100">{{ __('Clear the cart') }}</button>
            </form>
        </div>
    </div>
@endif
