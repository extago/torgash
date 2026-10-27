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
    </div>
</section>
