{{-- The customer card's orders — drawn by checkout, placed by core through
     `core.admin.customer.panels`. The ten latest; the list itself is one
     link away, filtered to this customer. --}}
@php use Torgash\Checkout\Orders\PaymentState; @endphp
<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-3">
        <div class="flex items-center gap-3 flex-wrap">
            <h3 class="font-semibold grow flex items-center gap-2"><x-icon name="package" size="sm" /> {{ __('Orders') }}</h3>
            @if ($count > 0)
                <span class="text-sm opacity-70">{{ trans_choice(':count order|:count orders', $count, ['count' => $count]) }} · {{ __('paid :amount', ['amount' => admin_price($paid, shop_currency())]) }}</span>
                <a href="{{ route('admin.orders.index', ['customer' => $customer->id]) }}" class="btn btn-ghost btn-sm gap-1.5">{{ __('All orders') }} <x-icon name="arrow-right" size="sm" /></a>
            @endif
        </div>

        @if ($orders->isEmpty())
            <p class="text-sm opacity-60">{{ __('No orders yet.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Order') }}</th>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Payment') }}</th>
                            <th class="text-end">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $customerOrder)
                            <tr class="hover:bg-base-200/50">
                                <td><a href="{{ route('admin.orders.show', $customerOrder) }}" class="link link-hover font-mono text-sm">{{ $customerOrder->order_number }}</a></td>
                                <td class="text-sm opacity-70 whitespace-nowrap">{{ $customerOrder->created_at->format('d.m.Y') }}</td>
                                <td class="whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 text-sm">
                                        <span class="h-2 w-2 rounded-full inline-block" style="background-color: {{ $statuses->color($customerOrder->status) }}"></span>
                                        {{ $statuses->label($customerOrder->status) }}
                                    </span>
                                </td>
                                <td><span class="badge badge-sm whitespace-nowrap {{ PaymentState::badge($customerOrder->payment_state) }}">{{ PaymentState::label($customerOrder->payment_state) }}</span></td>
                                <td class="text-end tabular-nums font-semibold whitespace-nowrap">{{ $customerOrder->adminMoney($customerOrder->total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Orders placed WITHOUT signing in with one of this account's
             contacts (GuestOrders). Attached by themselves once the customer
             confirms that contact; until then the manager decides. --}}
        @if ($guestOrders->isNotEmpty())
            <form method="POST" action="{{ route('admin.orders.attach', $customer->id) }}" class="rounded-box border border-warning/40 bg-warning/5 p-3 flex flex-col gap-2">
                @csrf
                <div class="text-sm">
                    <div class="font-semibold flex items-center gap-2"><x-icon name="link" size="sm" /> {{ __('Placed without signing in, with this customer\'s email or phone') }}</div>
                    <div class="opacity-70">{{ __('Not in the account yet: the customer does not see them there. Tick the ones that are theirs.') }}</div>
                </div>
                <ul class="flex flex-col gap-1">
                    @foreach ($guestOrders as $guestOrder)
                        <li>
                            <label class="flex items-center gap-3 text-sm flex-wrap cursor-pointer">
                                <input type="checkbox" name="orders[]" value="{{ $guestOrder->id }}" class="checkbox checkbox-sm" checked>
                                <a href="{{ route('admin.orders.show', $guestOrder) }}" class="link link-hover font-mono">{{ $guestOrder->order_number }}</a>
                                <span class="opacity-70">{{ $guestOrder->created_at->format('d.m.Y') }}</span>
                                <span class="opacity-70 break-all">{{ collect([$guestOrder->customer_name, $guestOrder->email, $guestOrder->phone])->filter()->implode(' · ') }}</span>
                                <span class="ms-auto tabular-nums font-semibold">{{ $guestOrder->adminMoney($guestOrder->total) }}</span>
                            </label>
                        </li>
                    @endforeach
                </ul>
                <div><button class="btn btn-sm btn-primary gap-1.5"><x-icon name="link" size="sm" /> {{ __('Attach to this account') }}</button></div>
            </form>
        @endif
    </div>
</section>
