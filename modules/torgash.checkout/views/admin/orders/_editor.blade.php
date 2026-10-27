{{-- Items + totals, in view or edit mode. Every endpoint that changes a line
     returns this whole fragment, so the lines and the money they add up to can
     never be rendered from two different moments. --}}
<div id="order-editor">
    <div class="flex justify-between items-center">
        <h3 class="font-semibold">{{ __('Items') }}</h3>
        @if ($order->merged_into_id)
            {{-- A merged order's goods are another order's now. --}}
        @elseif ($editing)
            <button type="button" class="btn btn-sm btn-primary"
                    hx-get="{{ route('admin.orders.items.edit', ['order' => $order, 'editing' => 0]) }}"
                    hx-target="#order-editor" hx-swap="outerHTML">{{ __('Done') }}</button>
        @else
            <button type="button" class="btn btn-sm"
                    hx-get="{{ route('admin.orders.items.edit', $order) }}"
                    hx-target="#order-editor" hx-swap="outerHTML">{{ __('Edit items') }}</button>
        @endif
    </div>

    <div class="overflow-x-auto">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('SKU') }}</th>
                    <th>{{ __('Name') }}</th>
                    <th class="w-24">{{ __('Qty') }}</th>
                    <th class="w-32">{{ __('Unit') }}</th>
                    <th>{{ __('Total') }}</th>
                    @if ($editing) <th></th> @endif
                </tr>
            </thead>
            <tbody>
                {{-- Parents first, each followed by what was bought as part of
                     it (PLAN §33.3). Those parts are ordinary lines — they came
                     off a shelf and they can be returned on their own — they
                     are merely printed under the thing they were chosen for. --}}
                @foreach (\Torgash\Checkout\Orders\OrderLines::tree($order->items ?? []) as $node)
                    @foreach ([$node['line'], ...$node['children']] as $childIndex => $item)
                    @php
                        $isPart = $childIndex > 0;
                        // A line written before keys existed is still addressed
                        // by its product id — what it used to be addressed by.
                        $lineKey = (string) ($item['key'] ?? $item['product_id'] ?? '');
                    @endphp
                    <tr @class(['bg-base-200/40' => $isPart])>
                        <td class="font-mono text-xs">{{ $item['sku'] }}</td>
                        <td @class(['ps-6' => $isPart])>
                            @if ($isPart)
                                <span class="opacity-40">↳</span>
                                @if (! empty($item['option_label']) && $item['option_label'] !== $item['name'])
                                    <span class="text-xs opacity-50">{{ $item['option_label'] }}:</span>
                                @endif
                            @endif
                            {{ $order->itemLabel($item) }}
                            {{-- What is inside this line's own price: a
                                 warranty, an engraving — whatever had no
                                 product to be a line of. --}}
                            @foreach ($item['options'] ?? [] as $option)
                                <div class="text-xs opacity-50">
                                    {{ ($option['amount'] ?? 0) < 0 ? '−' : '+' }} {{ $option['label'] }}@if (($option['qty'] ?? 1) > 1) ×{{ $option['qty'] }}@endif
                                    @if (($option['amount'] ?? 0) !== 0) · {{ $order->adminMoney(abs((int) $option['amount'])) }} @endif
                                </div>
                            @endforeach
                        </td>
                        @if ($editing)
                            <td colspan="2">
                                <form id="line-{{ $lineKey }}" class="flex items-center gap-2"
                                      hx-patch="{{ route('admin.orders.items.update', ['order' => $order, 'line' => $lineKey]) }}"
                                      hx-target="#order-editor" hx-swap="outerHTML">
                                    @csrf
                                    <input name="qty" type="number" min="1" step="1" value="{{ $item['qty'] }}"
                                           class="input input-bordered w-20" aria-label="{{ __('Qty') }}">
                                    <span class="opacity-40">×</span>
                                    <input name="unit_price" type="number" min="0" step="0.01"
                                           value="{{ number_format($item['unit_price'] / 100, 2, '.', '') }}"
                                           class="input input-bordered w-32" aria-label="{{ __('Unit') }}">
                                    {{-- Typed in the books' own currency, whatever the row
                                         beside it is being READ in: an order's money must
                                         not move through an exchange rate because somebody
                                         edited a quantity. Said out loud, because the
                                         column next to it may well show hryvnia. --}}
                                    <span class="text-xs opacity-50">{{ $order->currency_code }}</span>
                                    <button class="btn btn-sm">{{ __('Apply') }}</button>
                                </form>
                            </td>
                        @else
                            <td>{{ $item['qty'] }}</td>
                            <td>{{ $order->adminMoney($item['unit_price']) }}</td>
                        @endif
                        <td class="font-semibold">{{ $order->adminMoney($item['line_total']) }}</td>
                        @if ($editing)
                            <td class="text-right whitespace-nowrap">
                                {{-- Only a parent line, and only where something
                                     answers for it: a part exists because its
                                     parent's build says so, and editing it on
                                     its own is how the two stop agreeing. --}}
                                @if (! $isPart && app(\Torgash\Checkout\Orders\LineConfig::class)->isConfigurable($order, $item))
                                    <button class="btn btn-ghost btn-sm"
                                            hx-get="{{ route('admin.orders.items.configure', ['order' => $order, 'line' => $lineKey]) }}"
                                            hx-target="#order-editor" hx-swap="outerHTML">{{ __('Build') }}</button>
                                @endif
                                <button class="btn btn-ghost btn-sm text-error"
                                        hx-delete="{{ route('admin.orders.items.destroy', ['order' => $order, 'line' => $lineKey]) }}"
                                        hx-target="#order-editor" hx-swap="outerHTML"
                                        hx-confirm="{{ $node['children'] !== [] && ! $isPart ? __('Remove this item and everything bought as part of it?') : __('Remove this item from the order?') }}">{{ __('Remove') }}</button>
                            </td>
                        @endif
                    </tr>
                    @endforeach
                @endforeach
                @if (empty($order->items))
                    <tr><td colspan="{{ $editing ? 6 : 5 }}" class="text-center opacity-50 py-4">{{ __('This order has no items.') }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    @if ($editing)
        <div class="border-t border-base-300 pt-3 mt-1">
            <input type="search" name="q" placeholder="{{ __('Add a product — search by name or SKU') }}"
                   class="input input-bordered w-full"
                   hx-get="{{ route('admin.orders.items.search', $order) }}"
                   hx-trigger="input changed delay:300ms, search"
                   hx-target="#order-product-search">
            <div id="order-product-search"></div>
        </div>

        @if ($order->stock_deducted)
            <div class="text-xs opacity-60 mt-2">
                {{ __('This order holds stock: every change here moves the warehouse too.') }}
            </div>
        @endif
    @endif

    <div class="flex flex-wrap items-start justify-between gap-4 mt-2">
        {{-- A discount on the placed order: the coupon the customer forgot,
             the manager's own for a reason. Recomputed from what the order
             holds, like everything on this card (OrderDiscounts). --}}
        @if ($editing)
            <div class="flex flex-col gap-3 w-full max-w-xl">
                @if (! empty($discountError))
                    <div class="alert alert-warning text-sm py-2">{{ $discountError }}</div>
                @endif

                <div class="flex flex-col gap-1.5">
                    <div class="text-sm font-medium">{{ __('Coupon') }}</div>
                    @if ($order->coupon_code)
                        <div class="flex items-center gap-2 text-sm">
                            <span class="badge badge-success badge-outline font-mono">{{ $order->coupon_code }}</span>
                            @if (is_array($order->coupon_terms))
                                <span class="opacity-60">{{ $order->coupon_terms['type'] === 'percent' ? $order->coupon_terms['value'].'%' : $order->adminMoney((int) $order->coupon_terms['value']) }}</span>
                            @else
                                <span class="opacity-60" title="{{ __('Placed before discounts were kept as terms: the sum it was placed with stays.') }}">{{ __('fixed sum') }}</span>
                            @endif
                            <button type="button" class="btn btn-ghost btn-sm text-error"
                                    hx-delete="{{ route('admin.orders.coupon.remove', $order) }}"
                                    hx-target="#order-editor" hx-swap="outerHTML"
                                    hx-confirm="{{ __('Take the coupon off this order?') }}">{{ __('Remove') }}</button>
                        </div>
                    @endif
                    <form class="join w-full max-w-sm"
                          hx-post="{{ route('admin.orders.coupon.apply', $order) }}" hx-target="#order-editor" hx-swap="outerHTML">
                        <input name="code" class="input input-bordered join-item w-full font-mono uppercase"
                               placeholder="{{ $order->coupon_code ? __('Another code') : __('Coupon code') }}" autocomplete="off">
                        <button class="btn join-item">{{ $order->coupon_code ? __('Replace the coupon') : __('Apply') }}</button>
                    </form>
                </div>

                <div class="flex flex-col gap-1.5" x-data="{ type: @js($order->manual_discount['type'] ?? 'percent') }">
                    <div class="text-sm font-medium">{{ __('Manager\'s discount') }}</div>
                    @if ($order->manual_discount)
                        <div class="flex items-center gap-2 text-sm flex-wrap">
                            <span class="badge badge-success badge-outline">
                                {{ ($order->manual_discount['type'] ?? '') === 'percent' ? $order->manual_discount['value'].'%' : $order->adminMoney((int) $order->manual_discount['value']) }}
                            </span>
                            <span class="opacity-70">{{ $order->manual_discount['reason'] ?? '' }}</span>
                            @if (! empty($order->manual_discount['by']))
                                <span class="text-xs opacity-50">— {{ $order->manual_discount['by'] }}</span>
                            @endif
                            <button type="button" class="btn btn-ghost btn-sm text-error"
                                    hx-delete="{{ route('admin.orders.discount.remove', $order) }}"
                                    hx-target="#order-editor" hx-swap="outerHTML"
                                    hx-confirm="{{ __('Take the manager\'s discount off this order?') }}">{{ __('Remove') }}</button>
                        </div>
                    @endif
                    <form class="flex flex-wrap items-center gap-2"
                          hx-put="{{ route('admin.orders.discount.set', $order) }}" hx-target="#order-editor" hx-swap="outerHTML">
                        <div class="join">
                            <input name="value" type="number" step="0.01" min="0" required class="input input-bordered join-item w-28"
                                   value="{{ $order->manual_discount ? (($order->manual_discount['type'] ?? '') === 'percent' ? $order->manual_discount['value'] : number_format($order->manual_discount['value'] / 100, 2, '.', '')) : '' }}"
                                   aria-label="{{ __('Size') }}">
                            <select name="type" x-model="type" class="select select-bordered join-item w-24" aria-label="{{ __('Discount') }}">
                                <option value="percent">%</option>
                                <option value="fixed">{{ $order->currency_code }}</option>
                            </select>
                        </div>
                        <input name="reason" required maxlength="255" class="input input-bordered grow min-w-48"
                               value="{{ $order->manual_discount['reason'] ?? '' }}"
                               placeholder="{{ __('Why — a regular customer, a late delivery…') }}">
                        <button class="btn">{{ $order->manual_discount ? __('Change') : __('Apply') }}</button>
                    </form>
                    <p class="text-xs opacity-60">{{ __('Taken off the goods after the coupon. Shown to the customer as "Discount"; the reason stays in the shop.') }}</p>
                </div>

                {{-- Whatever else can re-price a placed order — promotions'
                     "recheck", which shows the difference before it applies. --}}
                {!! hook_filter('checkout.order.discount_tools', '', ['order' => $order]) !!}
            </div>
        @endif

    <div class="flex flex-col items-end gap-1 text-sm ms-auto">
        <div class="flex justify-between w-56"><span class="opacity-60">{{ __('Subtotal') }}</span><span>{{ $order->adminMoney($order->subtotal) }}</span></div>
        @foreach ($order->discountLines() as $discountLine)
            <div class="flex justify-between w-56 text-success"><span>{{ $discountLine['label'] }}</span><span>&minus;{{ $order->adminMoney($discountLine['amount']) }}</span></div>
        @endforeach
        <div class="flex justify-between w-56">
            <span class="opacity-60">{{ __('Shipping') }}</span>
            <span class="{{ $order->deliveryPending() ? 'text-warning text-xs' : '' }}">
                {{ $order->deliveryPending()
                    ? __('to be priced')
                    : ($order->shipping_cost > 0 ? $order->adminMoney($order->shipping_cost) : __('Free')) }}
            </span>
        </div>
        @foreach (hook_filter('checkout.order.extra_totals', [], ['order' => $order]) as $line)
            <div class="flex justify-between w-56 {{ $line['included'] ? 'opacity-60' : '' }}">
                <span class="opacity-60">{{ $line['label'] }}</span><span>{{ $order->adminMoney($line['amount']) }}</span>
            </div>
        @endforeach
        <div class="flex justify-between w-56 font-bold text-base"><span>{{ __('Total') }}</span><span>{{ $order->adminMoney($order->total) }}</span></div>
        @include('torgash.checkout::partials.prepaid-lines', ['order' => $order, 'prepaidMoney' => fn (int $a) => $order->adminMoney($a), 'prepaidWidth' => 'w-56'])
    </div>
    </div>
</div>

@if ($oob ?? false)
    {{-- Every edit writes a log line, and the log lives in another card. HTMX
         swaps that card too, out of band, so the history is never one action
         behind the table above it. --}}
    @include('torgash.checkout::admin.orders._activity', ['order' => $order, 'oob' => true])
@endif
