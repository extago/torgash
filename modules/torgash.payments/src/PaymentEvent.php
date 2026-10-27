<?php

namespace Torgash\Payments;

/**
 * What a gateway just told us, in our words.
 *
 * The whole point of the object is that a gateway module translates ONCE, here,
 * and nothing downstream ever sees `mono_status` or `IN_PROCESS`. Payment
 * states are the shop's fixed vocabulary
 * (`Torgash\Checkout\Orders\PaymentState`), so an order screen, a report, an
 * automation trigger and the next gateway all agree on what "paid" means.
 */
class PaymentEvent
{
    /**
     * @param  string  $state  a PaymentState constant
     * @param  int|null  $amount  minor units the gateway is holding, if it says
     * @param  string|null  $externalId  the gateway's id for this payment
     * @param  string|null  $eventKey  the gateway's id for THIS message; two
     *     callbacks with the same one are the same event redelivered, and the
     *     second must change nothing
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $state,
        public ?int $amount = null,
        public ?string $externalId = null,
        public ?string $eventKey = null,
        public ?string $summary = null,
        public array $raw = [],
        public ?int $orderId = null,
    ) {}
}
