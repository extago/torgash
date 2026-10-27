{{-- An order's money beyond what the customer pays (PLAN §42.5а): what its
     goods cost from their batches, what else the shop spent on it or got on
     top, and what is left. The shop's own books — nothing here reaches the
     customer, an invoice, a letter or 1C. --}}
<section id="order-costs" class="card bg-base-100 border border-base-300">
    <div class="card-body gap-2 text-sm">
        <h3 class="font-semibold">{{ __('Profit of the order') }}</h3>

        @if ($profit)
            <div class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-0.5">
                <span class="opacity-70">{{ __('Goods after discounts') }}</span><span class="tabular-nums text-end">{{ $order->adminMoney($profit['goods']) }}</span>
                @if ($profit['delivery'])
                    <span class="opacity-70">{{ __('Delivery charged') }}</span><span class="tabular-nums text-end">{{ $order->adminMoney($profit['delivery']) }}</span>
                @endif
                <span class="opacity-70">{{ __('Cost of the goods') }}</span><span class="tabular-nums text-end">−{{ $order->adminMoney($profit['cost']) }}</span>
                @foreach ($rows as $row)
                    <span class="opacity-70 flex items-center gap-1 min-w-0">
                        <span class="truncate" title="{{ $row->note }}">{{ $row->label }}</span>
                        <button type="button" class="btn btn-ghost btn-xs btn-square text-error shrink-0" title="{{ __('Remove') }}"
                                hx-delete="{{ route('admin.inventory.orders.costs.destroy', [$order, $row->id]) }}" hx-target="#order-costs" hx-swap="outerHTML"
                                hx-confirm="{{ __('Remove this line?') }}">
                            <x-icon name="x" size="sm" />
                        </button>
                    </span>
                    <span class="tabular-nums text-end {{ $row->kind === 'income' ? 'text-success' : '' }}">{{ $row->kind === 'income' ? '+' : '−' }}{{ $order->adminMoney((int) $row->amount) }}</span>
                @endforeach
                <span class="font-semibold border-t border-base-300 pt-1">{{ __('Profit') }}</span>
                <span class="font-semibold tabular-nums text-end border-t border-base-300 pt-1 {{ $profit['profit'] < 0 ? 'text-error' : '' }}">{{ $order->adminMoney($profit['profit']) }}</span>
            </div>
            @if ($profit['unknown'] > 0)
                <div class="text-xs text-warning">{{ trans_choice('The cost of :count unit is not known — it arrived with no receipt price.|The cost of :count units is not known — they arrived with no receipt price.', $profit['unknown'], ['count' => $profit['unknown']]) }}</div>
            @endif
            <div class="text-xs opacity-50">{{ __('Taxes are not taken off.') }}</div>
        @endif

        @if ($costError)
            <div class="alert alert-warning text-xs">{{ $costError }}</div>
        @endif

        <details class="mt-1" @if ($costError) open @endif>
            <summary class="cursor-pointer link link-primary text-xs">{{ __('Add an expense or income') }}</summary>
            <form class="flex flex-col gap-2 mt-2" hx-post="{{ route('admin.inventory.orders.costs.store', $order) }}" hx-target="#order-costs" hx-swap="outerHTML">
                @csrf
                <div class="join">
                    <label class="join-item btn btn-sm has-[:checked]:btn-neutral"><input type="radio" name="kind" value="expense" class="hidden" checked> {{ __('Expense') }}</label>
                    <label class="join-item btn btn-sm has-[:checked]:btn-neutral"><input type="radio" name="kind" value="income" class="hidden"> {{ __('Income') }}</label>
                </div>
                <input name="label" required maxlength="190" list="order-cost-labels" class="input input-sm input-bordered w-full" placeholder="{{ __('What — delivery to the carrier, assembly…') }}">
                <datalist id="order-cost-labels">
                    @foreach ($labels as $label)
                        <option value="{{ $label }}"></option>
                    @endforeach
                </datalist>
                <input name="amount" required inputmode="decimal" class="input input-sm input-bordered w-full" placeholder="{{ __('Amount') }}">
                <input name="note" maxlength="2000" class="input input-sm input-bordered w-full" placeholder="{{ __('Note (optional)') }}">
                <button class="btn btn-sm">{{ __('Add') }}</button>
            </form>
        </details>
    </div>
</section>
