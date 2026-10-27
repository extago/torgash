<?php

namespace Torgash\Taxes;

use Torgash\Taxes\Models\Tax;

/**
 * One tax and what it came to — the shape every screen renders and the order
 * snapshot is written from.
 */
class TaxLine
{
    public function __construct(public readonly Tax $tax, public readonly int $amount) {}

    public function plus(int $amount): self
    {
        return new self($this->tax, $this->amount + $amount);
    }

    public function included(): bool
    {
        return $this->tax->price_includes_tax;
    }

    /** "VAT 20%" — the label a customer sees on a total line. */
    public function label(): string
    {
        return $this->tax->label();
    }
}
