{{--
    What the list can do beyond holding products (PLAN §35.3, step 2): the two
    letters and the link. Each switch is its own tiny POST form submitted as it
    changes (the letters are the customer's consent — nothing is ticked for
    them), and each form stands on its own, never inside another.

    Letters need an address, so a guest is told to sign in rather than shown a
    switch that could not work. Sharing works for a guest too: the link names
    the list, not a person.
--}}
@php
    $wishOffersLetters = (bool) setting('torgash.wishlist', 'offer_notifications');
    $wishShares = (bool) setting('torgash.wishlist', 'share');
    $wishShareUrl = $wishList?->share_token ? route('wishlist.shared', $wishList->share_token) : null;
@endphp
@if ($wishOffersLetters || $wishShares)
    <div class="card bg-base-100 border border-base-300/60 shadow-sm">
        <div class="card-body gap-4 md:flex-row md:items-start md:gap-8">
            @if ($wishOffersLetters)
                <div class="flex-1 min-w-0 flex flex-col gap-2">
                    <div class="font-semibold flex items-center gap-2"><x-icon name="mail" /> {{ __('Letters about this list') }}</div>
                    @auth
                        <p class="text-xs opacity-60">{{ __('To :email. Off until you turn them on; every letter has a link to stop it.', ['email' => auth()->user()->email]) }}</p>
                        @foreach (['notify_price' => __('When something gets cheaper'), 'notify_stock' => __('When something is back in stock')] as $wishFlag => $wishFlagLabel)
                            <form method="POST" action="{{ route('wishlist.notify') }}" x-data>
                                @csrf
                                <input type="hidden" name="{{ $wishFlag }}" value="0">
                                <label class="label cursor-pointer gap-3 py-1">
                                    <input type="checkbox" name="{{ $wishFlag }}" value="1" class="toggle toggle-primary"
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
            @endif

            @if ($wishShares)
                <div class="flex-1 min-w-0 flex flex-col gap-2">
                    <div class="font-semibold flex items-center gap-2"><x-icon name="share-2" /> {{ __('Share the list') }}</div>
                    @if ($wishShareUrl)
                        <p class="text-xs opacity-60">{{ __('Whoever has the link sees these products and can buy them — not whose list it is.') }}</p>
                        <div class="join w-full" x-data="{ copied: false }">
                            <input type="text" readonly value="{{ $wishShareUrl }}" class="input input-bordered join-item flex-1 min-w-0 text-sm" x-on:focus="$el.select()" aria-label="{{ __('Link to the list') }}">
                            <button type="button" class="btn btn-primary join-item gap-2"
                                    x-on:click="navigator.clipboard.writeText(@js($wishShareUrl)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                                <span x-show="! copied"><x-icon name="copy" /></span>
                                <span x-show="copied" x-cloak><x-icon name="check" /></span>
                                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                            </button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('wishlist.share') }}">
                                @csrf
                                <input type="hidden" name="renew" value="1">
                                <button class="btn btn-sm btn-touch btn-ghost gap-2"><x-icon name="refresh-cw" /> {{ __('New link') }}</button>
                            </form>
                            <form method="POST" action="{{ route('wishlist.unshare') }}">
                                @csrf
                                <button class="btn btn-sm btn-touch btn-ghost gap-2"><x-icon name="link-2-off" /> {{ __('Close the link') }}</button>
                            </form>
                        </div>
                    @else
                        <p class="text-xs opacity-60">{{ __('A link to look at and buy from — for a gift, or to ask somebody\'s opinion.') }}</p>
                        <form method="POST" action="{{ route('wishlist.share') }}">
                            @csrf
                            <button class="btn btn-outline gap-2"><x-icon name="link" /> {{ __('Get a link') }}</button>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endif
