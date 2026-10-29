{{--
    The return, on its ticket.

    Everything the shop does about a return is here, on the enquiry screen, and
    there is no returns list anywhere — a return IS a ticket, read in the one
    inbox the shop already reads (§29.2). What this card adds is the half an
    enquiry cannot hold: which goods, where they went, and what happened to the
    money.

    Every button is its own <form>, side by side and never nested: the parser
    drops an inner form and keeps its inputs, which is how a stray _method rides
    out on the wrong submit (LESSONS.md).
--}}
@php
    use Torgash\Rma\Models\ReturnRequest;
    use Torgash\Rma\State;

    $allows = $mayHandle ? $return->allows() : [];
    $lines = $return->lines;
    $linesTotal = $return->linesTotal();
@endphp

<div class="card bg-base-100 border border-base-300">
    <div class="card-body gap-3">
        <h2 class="card-title text-base">
            {{ __('Return or exchange') }}
            <span class="badge badge-sm {{ State::badge($return->state) }}">{{ State::label($return->state) }}</span>
        </h2>

        <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
            <div>
                <span class="opacity-60">{{ __('Asked for') }}:</span>
                {{ $return->resolutionLabel() }}
            </div>
            @if ($order)
                <div>
                    <span class="opacity-60">{{ __('Order') }}:</span>
                    <a href="{{ route('admin.orders.show', $order->order_number) }}" class="link link-primary">{{ $order->order_number }}</a>
                </div>
                <div>
                    <span class="opacity-60">{{ __('Amount paid') }}:</span>
                    {{ admin_price((int) $order->paid_total) }}
                </div>
            @else
                {{-- No foreign key to the order on purpose, so this is a real
                     state and not an impossible one: the record of a refund
                     outlives the order it was about. --}}
                <div class="text-warning">{{ __('The order no longer exists.') }}</div>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="table table-sm">
                <tbody>
                    @foreach ($lines as $line)
                        <tr>
                            <td>
                                {{ $line['name'] ?? '' }}
                                @if ($line['sku'] ?? null)
                                    <span class="text-xs opacity-50">{{ $line['sku'] }}</span>
                                @endif
                            </td>
                            <td class="w-16 text-right opacity-70">× {{ $line['qty'] ?? 1 }}</td>
                            <td class="w-32 text-right">{{ admin_price((int) ($line['line_total'] ?? 0)) }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-medium">
                        <td colspan="2" class="text-right">{{ __('Goods being sent back') }}</td>
                        <td class="text-right">{{ admin_price($linesTotal) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- What has already happened, stated as facts rather than as a state
             machine: each of these is something that cannot be undone by
             reopening the return, and the card says so where it says it. --}}
        @if ($return->received_at || $return->refund_amount !== null || $return->exchange_order_id)
            <div class="flex flex-col gap-1 text-sm">
                @if ($return->received_at)
                    <div>
                        <x-icon name="package-check" size="sm" class="inline" />
                        {{ $return->disposition === ReturnRequest::WRITEOFF
                            ? __('Received and written off')
                            : __('Received and put back into stock') }}
                        @if ($return->warehouse_id && ($warehouses[$return->warehouse_id] ?? null))
                            — {{ $warehouses[$return->warehouse_id] }}
                        @endif
                        <span class="opacity-50">{{ $return->received_at->format('Y-m-d H:i') }}</span>
                    </div>
                @endif

                @if ($return->refund_amount !== null)
                    <div>
                        <x-icon name="banknote" size="sm" class="inline" />
                        {{ __('Refunded :amount', ['amount' => admin_price((int) $return->refund_amount)]) }}
                    </div>
                @endif

                @if ($return->exchange_order_id)
                    <div>
                        <x-icon name="repeat" size="sm" class="inline" />
                        {{ __('Exchanged for') }}
                        <a href="{{ route('admin.orders.show', $return->exchangeOrder?->order_number) }}" class="link link-primary">
                            {{ $return->exchangeOrder?->order_number ?: __('a new order') }}
                        </a>
                        @if ($return->surchargeLabel())
                            — <span class="opacity-70">{{ $return->surchargeLabel() }}</span>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        @if (! $mayHandle)
            <p class="text-sm opacity-60">{{ __('You can read this return but not act on it.') }}</p>
        @elseif ($allows === [])
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <span class="opacity-60">{{ __('This return is finished.') }}</span>
                <form method="POST" action="{{ route('admin.rma.reopen', $return) }}">
                    @csrf
                    <button class="btn btn-ghost btn-sm gap-1">
                        <x-icon name="rotate-ccw" size="sm" />
                        {{ __('Reopen') }}
                    </button>
                </form>
            </div>
        @else

            @error('state')<div class="alert alert-warning py-2 text-sm">{{ $message }}</div>@enderror
            @error('amount')<div class="alert alert-warning py-2 text-sm">{{ $message }}</div>@enderror

            <div class="flex flex-wrap gap-2">
                @if (in_array('approve', $allows, true))
                    <button class="btn btn-primary btn-sm gap-1.5"
                            data-open-dialog="modal-rma-approve" type="button">
                        <x-icon name="circle-check" size="sm" />{{ __('Approve') }}
                    </button>
                @endif

                @if (in_array('receive', $allows, true))
                    <button class="btn btn-sm gap-1.5"
                            data-open-dialog="modal-rma-receive" type="button">
                        <x-icon name="package-check" size="sm" />{{ __('Goods received') }}
                    </button>
                @endif

                @if (in_array('refund', $allows, true))
                    <button class="btn btn-sm gap-1.5"
                            data-open-dialog="modal-rma-refund" type="button">
                        <x-icon name="banknote" size="sm" />{{ __('Refund the money') }}
                    </button>
                @endif

                @if (in_array('exchange', $allows, true))
                    <button class="btn btn-sm gap-1.5"
                            data-open-dialog="modal-rma-exchange" type="button">
                        <x-icon name="repeat" size="sm" />{{ __('Create the exchange order') }}
                    </button>
                @endif

                @if (in_array('decline', $allows, true))
                    <button class="btn btn-ghost btn-sm gap-1.5 text-error"
                            data-open-dialog="modal-rma-decline" type="button">
                        <x-icon name="circle-x" size="sm" />{{ __('Decline') }}
                    </button>
                @endif
            </div>

            {{-- The dialogs. Each carries a note box, because every one of these
                 is something the customer has to be told — and a note typed
                 here becomes a message in this very thread, which is what
                 emails them. Nothing is sent by a state badge. --}}
            @if (in_array('approve', $allows, true))
                <x-modal name="rma-approve" :title="__('Approve this return')" icon="circle-check">
                    <form method="POST" action="{{ route('admin.rma.approve', $return) }}" class="flex flex-col gap-3">
                        @csrf
                        <p class="text-sm opacity-70">{{ __('Tell them how to send it back. What you write here goes into this conversation and is emailed to them.') }}</p>
                        <textarea name="note" rows="3" maxlength="5000" class="textarea textarea-bordered w-full"
                                  placeholder="{{ __('Write a reply to the customer') }}"></textarea>
                        <button class="btn btn-primary btn-sm">{{ __('Approve') }}</button>
                    </form>
                </x-modal>
            @endif

            @if (in_array('decline', $allows, true))
                <x-modal name="rma-decline" :title="__('Decline this return')" icon="circle-x">
                    <form method="POST" action="{{ route('admin.rma.decline', $return) }}" class="flex flex-col gap-3">
                        @csrf
                        <p class="text-sm opacity-70">{{ __('Say why. A refusal with no reason is what makes people ring instead of read.') }}</p>
                        <textarea name="note" rows="3" maxlength="5000" class="textarea textarea-bordered w-full"
                                  placeholder="{{ __('Write a reply to the customer') }}"></textarea>
                        <button class="btn btn-sm btn-error btn-outline">{{ __('Decline') }}</button>
                    </form>
                </x-modal>
            @endif

            @if (in_array('receive', $allows, true))
                <x-modal name="rma-receive" :title="__('The goods are here')" icon="package-check">
                    <form method="POST" action="{{ route('admin.rma.receive', $return) }}" class="flex flex-col gap-3">
                        @csrf

                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('What happens to them') }}</legend>
                            @foreach (ReturnRequest::dispositions() as $value => $label)
                                <label class="label cursor-pointer justify-start gap-2 p-0">
                                    <input type="radio" name="disposition" value="{{ $value }}" class="radio radio-sm"
                                           @checked($value === (setting('torgash.rma', 'restock', true) ? ReturnRequest::RESTOCK : ReturnRequest::WRITEOFF))>
                                    <span class="label-text">{{ $label }}</span>
                                </label>
                            @endforeach
                            <p class="text-xs opacity-50">{{ __('A warranty case is written off: the item goes back to the manufacturer or into the bin, and stock must not gain one.') }}</p>
                        </fieldset>

                        @if ($warehouses !== [])
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Where it arrived') }}</legend>
                                <select name="warehouse_id" class="select select-bordered w-full">
                                    <option value="">{{ __('Back where it came from') }}</option>
                                    @foreach ($warehouses as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                                <p class="text-xs opacity-50">{{ __('A customer returns to the nearest shop, not to the one that shipped it.') }}</p>
                            </fieldset>
                        @endif

                        <textarea name="note" rows="2" maxlength="5000" class="textarea textarea-bordered w-full"
                                  placeholder="{{ __('Anything to tell the customer (optional)') }}"></textarea>

                        <button class="btn btn-primary btn-sm">{{ __('Goods received') }}</button>
                    </form>
                </x-modal>
            @endif

            @if (in_array('refund', $allows, true))
                <x-modal name="rma-refund" :title="__('Refund the money')" icon="banknote">
                    <form method="POST" action="{{ route('admin.rma.refund', $return) }}" class="flex flex-col gap-3">
                        @csrf

                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">
                                {{ __('Amount') }} ({{ shop_currency() }})
                            </legend>
                            {{-- Defaulted to what the goods came to and still
                                 typed in: the shop may keep the postage, or
                                 have agreed something else entirely. --}}
                            <input type="number" name="amount" step="0.01" min="0.01" required
                                   value="{{ number_format($linesTotal / 100, 2, '.', '') }}"
                                   class="input input-bordered w-full">
                            <p class="text-xs opacity-50">
                                {{ __('Goods: :goods. Recorded as paid on the order: :paid.', [
                                    'goods' => admin_price($linesTotal),
                                    'paid' => $order ? admin_price((int) $order->paid_total) : '—',
                                ]) }}
                            </p>
                        </fieldset>

                        <p class="text-sm opacity-70">{{ __('Where a gateway took the money it is asked to give it back, and a payment that is only on hold is released rather than transferred. Otherwise this records the refund and somebody makes the transfer.') }}</p>

                        <textarea name="note" rows="2" maxlength="5000" class="textarea textarea-bordered w-full"
                                  placeholder="{{ __('Anything to tell the customer (optional)') }}"></textarea>

                        <button class="btn btn-primary btn-sm">{{ __('Refund the money') }}</button>
                    </form>
                </x-modal>
            @endif

            @if (in_array('exchange', $allows, true))
                <x-modal name="rma-exchange" :title="__('Create the exchange order')" icon="repeat">
                    <form method="POST" action="{{ route('admin.rma.exchange', $return) }}" class="flex flex-col gap-3">
                        @csrf

                        <p class="text-sm opacity-70">{{ __('A new order for the same goods, to the same address, linked to this one — never an edit of the original, whose total the customer has already agreed to. It is priced from today\'s catalogue, so check it before you send it.') }}</p>

                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('The money') }}</legend>
                            @foreach (ReturnRequest::surcharges() as $value => $label)
                                <label class="label cursor-pointer justify-start gap-2 p-0">
                                    <input type="radio" name="surcharge" value="{{ $value }}" class="radio radio-sm"
                                           @checked($value === ReturnRequest::SURCHARGE_NONE)>
                                    <span class="label-text">{{ $label }}</span>
                                </label>
                            @endforeach
                            <p class="text-xs opacity-50">{{ __('Recorded, not charged: what happens about a difference is settled between people, and the engine will not invent a payment.') }}</p>
                        </fieldset>

                        <textarea name="note" rows="2" maxlength="5000" class="textarea textarea-bordered w-full"
                                  placeholder="{{ __('Anything to tell the customer (optional)') }}"></textarea>

                        <button class="btn btn-primary btn-sm">{{ __('Create the exchange order') }}</button>
                    </form>
                </x-modal>
            @endif
        @endif
    </div>
</div>
