{{--
    The conversation half of the order page.

    Every ticket filed against THIS order, each with what was said, plus a form
    to start another. A guest reading the order by its signed link gets all of
    it: the thread's own reply form carries a signature of its own, and this
    form's action is signed for this one address.

    Whatever else a module has to say about an order's tickets arrives as
    $extras (per ticket) and $actions (buttons beside the one below) — that is
    how returns adds its state, its lines and its own button without checkout
    knowing what a return is.
--}}
@php
    use Torgash\Checkout\Orders\OrderKey;

    $dialog = 'order-enquiry';
@endphp

<div class="card bg-base-100 shadow-sm border border-base-300/60">
    <div class="card-body p-4 sm:p-5 gap-3">
        <h2 class="font-semibold text-base">{{ __('Questions about this order') }}</h2>

        @if (session('warning'))
            <div class="alert alert-warning py-2 text-sm">{{ session('warning') }}</div>
        @endif

        @if ($errors->first('enquiry'))
            <div class="alert alert-warning py-2 text-sm">{{ $errors->first('enquiry') }}</div>
        @endif

        @foreach ($enquiries as $enquiry)
            <div class="rounded-box border border-base-300 p-4 flex flex-col gap-3">
                {!! $extras[$enquiry->id] ?? '' !!}
                {!! $threads[$enquiry->id] ?? '' !!}
            </div>
        @endforeach

        {{-- Buttons are written here and open their dialogs by id, rather than
             passed as the modal's `trigger` attribute: that one takes text, and
             a named slot before the default content leaves an output buffer
             open (the component says so in its own docblock). --}}
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline btn-sm gap-1.5"
                    data-open-dialog="modal-{{ $dialog }}">
                <x-icon name="circle-help" size="sm" />
                {{ __('Ask about this order') }}
            </button>

            {!! $actions !!}
        </div>

        <x-modal :name="$dialog" :title="__('Ask about this order')" icon="circle-help">
            <form method="POST"
                  action="{{ OrderKey::url($order, 'checkout.enquiry', app()->getLocale()) }}"
                  enctype="multipart/form-data" class="flex flex-col gap-3">
                @csrf

                {{-- Stamp, honeypot and whatever else guards this form — one
                     component, so no template can be shipped with half of it. --}}
                <x-form-guard form="enquiry" />

                <label class="fieldset p-0">
                    <span class="fieldset-legend text-xs">{{ __('Your question') }} <span class="text-error">*</span></span>
                    <textarea name="question" rows="4" required class="textarea textarea-bordered w-full"></textarea>
                </label>

                {{-- "Here is what arrived" is half the questions a shop gets
                     about an order, and a photograph answers it faster than
                     three messages do. Rendered by the enquiries module, and
                     nothing at all when the shop has turned photos off. --}}
                @include('torgash.enquiries::partials.attach-field')

                {{-- What the order already knows about them, asked as fields by
                     the kind so the answers are stored and labelled like every
                     other enquiry's. --}}
                <input type="hidden" name="name" value="{{ $order->customer_name }}">
                <input type="hidden" name="email" value="{{ $order->email }}">
                <input type="hidden" name="phone" value="{{ $order->phone }}">

                <button class="btn btn-primary btn-sm">{{ __('Send') }}</button>
            </form>
        </x-modal>
    </div>
</div>
