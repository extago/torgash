@if ($orders->isEmpty())
    <p class="text-sm opacity-60 py-4 text-center">{{ __('No orders yet.') }}</p>
@else
    <div class="-mx-2">
        <table class="table table-sm table-fixed w-full">
            <tbody>
                @foreach ($orders as $order)
                    @php $orderStatus = $statuses->get($order->status); @endphp
                    <tr class="hover:bg-base-200/50">
                        <td class="whitespace-nowrap w-28">
                            <a href="{{ route('admin.orders.show', $order) }}" class="link link-hover font-mono text-xs">{{ $order->order_number }}</a>
                        </td>
                        <td class="truncate text-sm">{{ $order->customerLabel() }}</td>
                        <td class="whitespace-nowrap w-36 hidden @md:table-cell">
                            <span class="badge badge-sm gap-1.5 max-w-full truncate bg-base-100 border-base-300">
                                <span class="h-2 w-2 rounded-full inline-block" style="background-color: {{ $orderStatus?->color ?? '#999' }}"></span>
                                {{ $orderStatus?->t('name') ?? $order->status }}
                            </span>
                        </td>
                        <td class="text-end tabular-nums whitespace-nowrap text-sm w-28">{{ $order->adminMoney($order->total) }}</td>
                        <td class="text-end text-xs opacity-50 whitespace-nowrap w-28 hidden @xl:table-cell" title="{{ $order->created_at?->format('Y-m-d H:i') }}">{{ $order->created_at?->diffForHumans() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
