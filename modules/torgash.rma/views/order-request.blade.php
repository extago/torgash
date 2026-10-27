{{--
    "Return or exchange", on the order page: a button beside checkout's own
    "Ask about this order", and the form behind it.

    Rendered only when the shop's rules actually allow one to be asked for —
    the statuses it ticked, the days, and whether anything is left to send
    back. Rendered for whoever the page already let in: their own account, the
    session that placed it, or the signed link. The page decided that; this only
    draws.
--}}
@php
    use Torgash\Checkout\Orders\OrderKey;
    use Torgash\Rma\Models\ReturnRequest;

    $dialog = 'rma-request';
@endphp

<button type="button" class="btn btn-outline btn-sm gap-1.5"
        data-open-dialog="modal-{{ $dialog }}">
    <x-icon name="package-x" size="sm" />
    {{ __('Return or exchange') }}
</button>

<x-modal :name="$dialog" :title="__('Send something back')" icon="package-x">
    {{-- Signed, so the guest reading this order by its link can post it: the
         signature on the form's own address is what authorises them, exactly as
         it did the page. --}}
    <form method="POST"
          action="{{ OrderKey::url($order, 'rma.request', app()->getLocale()) }}"
          enctype="multipart/form-data" class="flex flex-col gap-3">
        @csrf

        {{-- Stamp, honeypot and whatever else guards this form. It goes through
             the enquiries funnel, so it is guarded under that name. --}}
        <x-form-guard form="enquiry" />

        <div class="flex flex-col gap-2">
            @foreach ($remaining as $line)
                <label class="flex items-center gap-3 text-sm">
                    <input type="number" name="lines[{{ $line['product_id'] }}][qty]"
                           value="0" min="0" max="{{ $line['qty'] }}"
                           class="input input-bordered input-sm w-20">
                    <span>{{ $line['name'] ?? '' }}</span>
                    <span class="opacity-60">{{ __('of :n', ['n' => $line['qty']]) }}</span>
                </label>
            @endforeach
        </div>

        <label class="fieldset p-0">
            <span class="fieldset-legend text-xs">{{ __('What would you like') }}</span>
            <select name="resolution" class="select select-bordered select-sm w-full">
                @foreach (ReturnRequest::resolutions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label class="fieldset p-0">
            <span class="fieldset-legend text-xs">{{ __('What is wrong with it') }} <span class="text-error">*</span></span>
            <textarea name="reason" rows="3" required class="textarea textarea-bordered w-full"></textarea>
        </label>

        {{-- Photographs of the fault: the thing this whole feature was asked
             for, and the reason it is on the RETURN form first. --}}
        @include('torgash.enquiries::partials.attach-field')

        {{-- What the order already knows about them. Asked as fields by the
             kind, so the answers are stored and labelled like every other
             enquiry's. --}}
        <input type="hidden" name="name" value="{{ $order->customer_name }}">
        <input type="hidden" name="email" value="{{ $order->email }}">
        <input type="hidden" name="phone" value="{{ $order->phone }}">

        <button class="btn btn-primary btn-sm">{{ __('Send') }}</button>
    </form>
</x-modal>
