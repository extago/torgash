<?php

namespace Torgash\Checkout\Orders;

/**
 * What happened when the shop tried to give money back.
 *
 * A bare boolean would not do: "the gateway reversed the hold", "recorded by
 * hand" and "we are not holding any of their money here" are three different
 * answers, and the manager has to be told which one it was — the third means
 * somebody still has to go and make a transfer.
 */
class RefundResult
{
    public function __construct(
        public readonly bool $done,
        public readonly string $summary,
        public readonly bool $viaGateway = false,
    ) {}
}
