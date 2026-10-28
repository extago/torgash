{{--
    What the list can do beyond holding products (PLAN §35.3, step 2): the two
    letters and the link — two buttons beside the page's heading, each opening
    its panel (core's popover component), so the products come straight under the heading
    on a phone and on a computer alike. Icons alone on a phone.

    Each switch and each link button is its own tiny POST form, never inside
    another. Without JavaScript it posts and comes back to the page; with it
    the same answer (the page, after the redirect) is read over HTMX and only
    the panel is put back (hx-select), so the panel stays open on its new
    state — the bell on its button follows out of band. The letters are the
    customer's consent: nothing is ticked for them.

    Letters need an address, so a guest is told to sign in rather than shown a
    switch that could not work. Sharing works for a guest too: the link names
    the list, not a person.
--}}
@php
    $wishOffersLetters = (bool) setting('torgash.wishlist', 'offer_notifications');
    $wishShares = (bool) setting('torgash.wishlist', 'share');
    $wishShareUrl = $wishList?->share_token ? route('wishlist.shared', $wishList->share_token) : null;
    $wishWrites = (bool) ($wishList?->notify_price || $wishList?->notify_stock);
    $wishInPlace = fn (string $panel, string $also = '') => 'hx-target="#'.$panel.'" hx-select="#'.$panel.'" hx-swap="outerHTML"'
        .($also !== '' ? ' hx-select-oob="#'.$also.'"' : '');
@endphp
@if ($wishOffersLetters || $wishShares)
    <div class="flex items-center gap-2 shrink-0">
        @if ($wishOffersLetters)
            <x-popover class="btn btn-sm bg-base-100 border-base-300 gap-2 max-sm:btn-square" panel-class="w-72 p-4" :label="__('Letters about this list')">
                <div id="wishlist-letters" class="flex flex-col gap-2">
                    <div class="font-semibold">{{ __('Letters about this list') }}</div>
                    @auth
                        <p class="text-xs opacity-60">{{ __('To :email. Off until you turn them on; every letter has a link to stop it.', ['email' => auth()->user()->email]) }}</p>
                        @foreach (['notify_price' => __('When something gets cheaper'), 'notify_stock' => __('When something is back in stock')] as $wishFlag => $wishFlagLabel)
                            <form method="POST" action="{{ route('wishlist.notify') }}" hx-post="{{ route('wishlist.notify') }}" {!! $wishInPlace('wishlist-letters', 'wishlist-letters-bell') !!} x-data>
                                @csrf
                                <input type="hidden" name="{{ $wishFlag }}" value="0">
                                <label class="label cursor-pointer gap-3 py-1 whitespace-normal">
                                    <input type="checkbox" name="{{ $wishFlag }}" value="1" class="toggle toggle-primary shrink-0"
                                           @checked($wishList?->{$wishFlag}) x-on:change="$el.form.requestSubmit()">
                                    <span class="text-sm text-base-content">{{ $wishFlagLabel }}</span>
                                </label>
                                <noscript><button class="btn btn-sm btn-touch btn-outline">{{ __('Save') }}</button></noscript>
                            </form>
                        @endforeach
                    @else
                        <p class="text-sm opacity-70">
                            {{ __('Sign in, and we will write when something in your list gets cheaper or is back in stock.') }}
                            <a href="{{ route('account.login') }}" class="link link-primary">{{ __('Sign in') }}</a>
                        </p>
                    @endauth
                </div>

                <x-slot:trigger>
                    {{-- Ringing while a letter is switched on. --}}
                    <span id="wishlist-letters-bell" class="contents"><x-icon :name="$wishWrites ? 'bell-ring' : 'bell'" size="sm" class="{{ $wishWrites ? 'text-primary' : '' }}" /></span>
                    <span class="max-sm:hidden">{{ __('Letters') }}</span>
                </x-slot:trigger>
            </x-popover>
        @endif

        @if ($wishShares)
            <x-popover class="btn btn-sm bg-base-100 border-base-300 gap-2 max-sm:btn-square" panel-class="w-80 p-4" :label="__('Share the list')">
                <div id="wishlist-share" class="flex flex-col gap-3">
                    <div class="font-semibold">{{ __('Share the list') }}</div>
                    @if ($wishShareUrl)
                        <p class="text-xs opacity-60">{{ __('Whoever has the link sees these products and can buy them — not whose list it is.') }}</p>
                        <div class="join w-full" x-data="{ copied: false }">
                            <input type="text" readonly value="{{ $wishShareUrl }}" class="input join-item flex-1 min-w-0 text-sm" x-on:focus="$el.select()" aria-label="{{ __('Link to the list') }}">
                            <button type="button" class="btn btn-primary join-item gap-2"
                                    x-on:click="navigator.clipboard.writeText(@js($wishShareUrl)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                                <span x-show="! copied"><x-icon name="copy" size="sm" /></span>
                                <span x-show="copied" x-cloak><x-icon name="check" size="sm" /></span>
                                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                            </button>
                        </div>
                        {{-- The shop's own networks (core setting "Share to"),
                             the same ones core's share button offers. --}}
                        @php $wishNetworks = app(\App\Core\Content\Share::class)->links($wishShareUrl, __('Wishlist')); @endphp
                        @if ($wishNetworks !== [])
                            <div class="flex flex-col gap-1.5">
                                <span class="text-xs opacity-60">{{ __('Send the link') }}</span>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($wishNetworks as $wishNetwork)
                                        <a href="{{ $wishNetwork['href'] }}" target="_blank" rel="noopener nofollow" data-share="{{ $wishNetwork['slug'] }}"
                                           class="btn btn-sm btn-square btn-ghost border-base-300" title="{{ $wishNetwork['name'] }}" aria-label="{{ $wishNetwork['name'] }}">{!! $wishNetwork['mark'] !!}</a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        <div class="flex flex-wrap gap-2 border-t border-base-300 pt-3">
                            <form method="POST" action="{{ route('wishlist.share') }}" hx-post="{{ route('wishlist.share') }}" {!! $wishInPlace('wishlist-share') !!}>
                                @csrf
                                <input type="hidden" name="renew" value="1">
                                <button class="btn btn-sm btn-ghost gap-2"><x-icon name="refresh-cw" size="sm" /> {{ __('New link') }}</button>
                            </form>
                            <form method="POST" action="{{ route('wishlist.unshare') }}" hx-post="{{ route('wishlist.unshare') }}" {!! $wishInPlace('wishlist-share') !!}>
                                @csrf
                                <button class="btn btn-sm btn-ghost gap-2"><x-icon name="link-2-off" size="sm" /> {{ __('Close the link') }}</button>
                            </form>
                        </div>
                    @else
                        <p class="text-xs opacity-60">{{ __('A link to look at and buy from — for a gift, or to ask somebody\'s opinion.') }}</p>
                        <form method="POST" action="{{ route('wishlist.share') }}" hx-post="{{ route('wishlist.share') }}" {!! $wishInPlace('wishlist-share') !!}>
                            @csrf
                            <button class="btn btn-primary btn-sm gap-2"><x-icon name="link" size="sm" /> {{ __('Get a link') }}</button>
                        </form>
                    @endif
                </div>

                <x-slot:trigger>
                    <x-icon name="share-2" size="sm" />
                    <span class="max-sm:hidden">{{ __('Share') }}</span>
                </x-slot:trigger>
            </x-popover>
        @endif
    </div>
@endif
