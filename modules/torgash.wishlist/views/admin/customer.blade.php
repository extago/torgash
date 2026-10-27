{{-- The customer card's wishlist — drawn by this module, placed by core
     through `core.admin.customer.panels`. The latest saved first; beside each
     the price it was saved at, since that is what a price-drop letter
     compares against. --}}
<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-3">
        <div class="flex items-center gap-3 flex-wrap">
            <h3 class="font-semibold grow flex items-center gap-2"><x-icon name="heart" size="sm" /> {{ __('Wishlist') }}</h3>
            @if ($list && $count > 0)
                <span class="text-sm opacity-70">
                    {{ trans_choice(':count product|:count products', $count, ['count' => $count]) }}
                    @if ($list->last_active_at)
                        · {{ __('last touched :when', ['when' => $list->last_active_at->diffForHumans()]) }}
                    @endif
                </span>
            @endif
        </div>

        @if ($list)
            {{-- Whether the letters reach this person is THEIR choice, made in
                 their account; the card only says what they chose. --}}
            <div class="flex flex-wrap gap-2 text-xs">
                <span @class(['badge badge-sm gap-1', 'badge-success' => $list->notify_price, 'badge-ghost' => ! $list->notify_price])>
                    <x-icon :name="$list->notify_price ? 'bell' : 'bell-off'" size="sm" /> {{ __('Price drops') }}
                </span>
                <span @class(['badge badge-sm gap-1', 'badge-success' => $list->notify_stock, 'badge-ghost' => ! $list->notify_stock])>
                    <x-icon :name="$list->notify_stock ? 'bell' : 'bell-off'" size="sm" /> {{ __('Back in stock') }}
                </span>
                @if ($list->share_token)
                    <span class="badge badge-sm badge-ghost gap-1"><x-icon name="share-2" size="sm" /> {{ __('Has a share link') }}</span>
                @endif
            </div>
        @endif

        @if ($items->isEmpty())
            <p class="text-sm opacity-60">{{ __('Nothing saved yet.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th colspan="2">{{ __('Product') }}</th>
                            <th class="text-end">{{ __('Price') }}</th>
                            <th>{{ __('Availability') }}</th>
                            <th class="text-end">{{ __('When saved') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $wishItem)
                            @php
                                $wishProduct = $products[$wishItem->product_id];
                                $wishNow = $wishProduct->publicPrice();
                                $wishThen = $wishItem->price_at_add;
                            @endphp
                            <tr class="hover:bg-base-200/50">
                                @include('torgash.wishlist::admin._product', ['wishProduct' => $wishProduct])
                                <td class="text-end whitespace-nowrap text-sm">
                                    @if ($wishThen !== null)
                                        <span @class(['tabular-nums', 'text-success font-semibold' => $wishNow < $wishThen, 'opacity-60' => $wishNow >= $wishThen])
                                              title="{{ __('The price when it was saved') }}">
                                            @if ($wishNow < $wishThen)<x-icon name="trending-down" size="sm" />@endif
                                            {{ admin_price($wishThen, shop_currency()) }}
                                        </span>
                                    @endif
                                    <div class="text-xs opacity-50">{{ $wishItem->created_at?->format('d.m.Y') }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($count > $items->count())
                <p class="text-xs opacity-50">{{ __('and :count more', ['count' => $count - $items->count()]) }}</p>
            @endif
        @endif
    </div>
</section>
