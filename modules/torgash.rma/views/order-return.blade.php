{{--
    What a ticket that is a RETURN says on the order page, above its
    conversation: where the goods have got to, what was asked for, and what is
    being sent back.

    Added to checkout's panel through `checkout.order.enquiry_extra` — checkout
    draws the conversation and knows nothing about any of this.
--}}
<div class="flex flex-col gap-2">
    <div class="flex items-baseline gap-2 flex-wrap">
        <span class="badge {{ \Torgash\Rma\State::badge($return->state) }}">
            {{ \Torgash\Rma\State::label($return->state) }}
        </span>
        <span class="text-sm opacity-70">{{ $return->resolutionLabel() }}</span>
    </div>

    <ul class="text-sm">
        @foreach ($return->lines as $line)
            <li>{{ $line['name'] ?? '' }} × {{ $line['qty'] ?? 1 }}</li>
        @endforeach
    </ul>
</div>
