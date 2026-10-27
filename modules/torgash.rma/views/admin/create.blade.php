{{--
    "Draw up a return" — on a ticket that came in as something else.

    The owner's case, and the ordinary one: somebody asks a question about their
    order, it turns out the thing is faulty, and the SHOP decides it is coming
    back. Everything already said stays in this one conversation, which is why
    this attaches a return to the ticket rather than starting a second one.

    Two steps, and no JavaScript for either: name the order (prefilled from the
    ticket's own subject), then choose what is coming back off that order's own
    lines. The first step is a GET form that reloads this page with the number
    in the query — a select of every order in the shop is not a control, and a
    manager reading a ticket already has the number in front of them.

    Beside each other, never nested: the parser drops an inner form and keeps
    its inputs.
--}}
@php
    use Torgash\Rma\Models\ReturnRequest;
@endphp

<div class="card bg-base-100 border border-base-300">
    <div class="card-body gap-3">
        <h2 class="card-title text-base">
            <x-icon name="package-x" size="sm" />
            {{ __('Return or exchange') }}
        </h2>

        @error('order_number')<div class="alert alert-warning py-2 text-sm">{{ $message }}</div>@enderror
        @error('lines')<div class="alert alert-warning py-2 text-sm">{{ $message }}</div>@enderror
        @error('enquiry')<div class="alert alert-warning py-2 text-sm">{{ $message }}</div>@enderror

        <form method="GET" class="flex flex-wrap items-end gap-2">
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('Order number') }}</legend>
                <input type="text" name="rma_order" value="{{ $orderNumber }}"
                       placeholder="TG-000123" class="input input-bordered w-40">
            </fieldset>
            <button class="btn">{{ __('Show what was bought') }}</button>
        </form>

        @if ($order && $remaining !== [])
            <form method="POST" action="{{ route('admin.rma.create', $enquiry) }}" class="flex flex-col gap-3">
                @csrf
                <input type="hidden" name="order_number" value="{{ $order->order_number }}">

                <div class="flex flex-col gap-2">
                    @foreach ($remaining as $line)
                        <label class="flex items-center gap-3 text-sm">
                            <input type="number" name="lines[{{ $line['product_id'] }}][qty]"
                                   value="0" min="0" max="{{ $line['qty'] }}"
                                   class="input input-bordered w-20">
                            <span>{{ $line['name'] ?? '' }}</span>
                            <span class="opacity-60">{{ __('of :n', ['n' => $line['qty']]) }}</span>
                            <span class="opacity-60">{{ admin_price((int) ($line['unit_price'] ?? 0)) }}</span>
                        </label>
                    @endforeach
                </div>

                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('What are we doing') }}</legend>
                    <select name="resolution" class="select select-bordered w-full max-w-xs">
                        @foreach (ReturnRequest::resolutions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </fieldset>

                <textarea name="note" rows="2" maxlength="5000" class="textarea textarea-bordered w-full"
                          placeholder="{{ __('Tell them what happens next (optional)') }}"></textarea>

                <div>
                    <button class="btn btn-primary btn-sm gap-1.5">
                        <x-icon name="package-x" size="sm" />
                        {{ __('Draw up the return') }}
                    </button>
                </div>

                {{-- The shop's own offer rules — which statuses, how many days,
                     paid or not — answer "when do we show a CUSTOMER the
                     button", and are not applied to a manager. What still binds
                     is what is left of the order: the same unit cannot come back
                     twice. --}}
                <p class="text-xs opacity-50">
                    {{ __('The order is :status. What the shop offers customers does not limit you here — only what is left of the order does.', [
                        'status' => \Torgash\Checkout\Models\OrderStatus::find($order->status)?->t('name') ?? $order->status,
                    ]) }}
                </p>
            </form>
        @elseif ($order)
            <p class="text-sm opacity-60">{{ __('Everything in this order has already been sent back.') }}</p>
        @else
            <p class="text-sm opacity-60">{{ __('Name the order and this ticket becomes a return, with everything already said kept on it.') }}</p>
        @endif
    </div>
</div>
