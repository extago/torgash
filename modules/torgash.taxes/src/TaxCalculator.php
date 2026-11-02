<?php

namespace Torgash\Taxes;

use Torgash\Checkout\Shipping\Location;
use Torgash\Taxes\Models\Tax;

/**
 * All the tax arithmetic in one place, working in integer minor units.
 *
 * The two formulas, the standard ones:
 *
 *   included: tax = base − base / (1 + rate/100)   the price does not change,
 *                                                  the tax is carved out of it
 *   added:    tax = base × rate/100                and the base for anything
 *                                                  charged after it grows
 *
 * Priorities decide what "base" means: taxes sharing a priority are all
 * charged on the same untouched base, a higher priority is charged on what the
 * lower ones already produced. Two 10% taxes at the same priority take 20% of
 * 100; at different priorities they take 21.
 */
class TaxCalculator
{
    public function __construct(protected TaxResolver $taxes, protected Destinations $destinations) {}

    /**
     * Taxes for one whole cart or order, keyed by tax id.
     *
     * The order-level discount is spread across the lines in proportion to
     * what they cost, because tax is owed on what the customer actually pays,
     * not on the sticker price. The last line absorbs the rounding remainder
     * so the shares always add up to the discount exactly.
     *
     * `$where` is where the goods are going: each tax is charged at the rate
     * that applies there (Destinations). Null — nobody has said — charges
     * every tax at its own rate.
     *
     * @param  list<array{product_id: int, unit_price: int, qty: int}>  $lines
     * @return array<int, TaxLine>
     */
    public function forCart(array $lines, int $discount = 0, int $shippingCost = 0, ?Location $where = null): array
    {
        // A tax is worked out on the LINE, never per unit (PLAN §65): the
        // price per unit may be finer than a cent, the line is an amount.
        $lineAmount = fn (array $line) => price_scale()->amount($line['unit_price'] * $line['qty']);
        $subtotal = array_sum(array_map($lineAmount, $lines));
        $result = [];
        $spent = 0;

        foreach (array_values($lines) as $i => $line) {
            $lineTotal = $lineAmount($line);

            $share = $subtotal > 0
                ? ($i === count($lines) - 1 ? $discount - $spent : (int) round($discount * $lineTotal / $subtotal))
                : 0;
            $spent += $share;

            $taxes = $this->destinations->apply($this->taxes->forProduct($line['product_id']), $where);

            if ($taxes === []) {
                continue;
            }

            $this->accumulate($result, $taxes, max(0, $lineTotal - $share), $line['qty']);
        }

        if ($shippingCost > 0) {
            $shippingTaxes = $this->destinations->apply($this->taxes->forShipping(), $where);

            if ($shippingTaxes !== []) {
                $this->accumulate($result, $shippingTaxes, $shippingCost, 1);
            }
        }

        return $result;
    }

    /**
     * `$result` (forCart()'s) with the taxes of a payment method's surcharge
     * added — on the surcharge alone, apart from the
     * goods and the delivery, and folded into the same tax's line: one "VAT
     * 20%" on the bill, not two.
     *
     * @param  array<int, TaxLine>  $result
     * @return array<int, TaxLine>
     */
    public function withSurcharge(array $result, int $surcharge, ?int $paymentMethodId, ?Location $where = null): array
    {
        if ($surcharge <= 0) {
            return $result;
        }

        $taxes = $this->destinations->apply($this->taxes->forPaymentMethod($paymentMethodId), $where);

        if ($taxes !== []) {
            $this->accumulate($result, $taxes, $surcharge, 1);
        }

        return $result;
    }

    /**
     * What a single price becomes once the taxes that are ADDED to it are
     * applied — the storefront's "show prices with tax" view. Included taxes
     * are already in the number and leave it alone. The taxes are expected
     * already placed (Destinations::apply) — this only does the arithmetic.
     *
     * @param  list<Tax>  $taxes
     */
    public function priceWithTax(int $price, array $taxes): int
    {
        $total = $price;

        // `$price` is a price per unit (price units, PLAN §65); a fixed tax
        // is an amount in minor units and is brought onto the same scale.
        foreach ($this->charge($taxes, $price, 1, price_scale()->perMinor()) as $charge) {
            if (! $charge['included']) {
                $total += $charge['amount'];
            }
        }

        return $total;
    }

    /**
     * @param  array<int, TaxLine>  $result
     * @param  list<Tax>  $taxes
     */
    protected function accumulate(array &$result, array $taxes, int $base, int $qty): void
    {
        foreach ($this->charge($taxes, $base, $qty) as $taxId => $charge) {
            if (isset($result[$taxId])) {
                $result[$taxId] = $result[$taxId]->plus($charge['amount']);

                continue;
            }

            $result[$taxId] = new TaxLine($charge['tax'], $charge['amount']);
        }
    }

    /**
     * The chain itself: every tax charged on the given base, in priority order.
     *
     * @param  list<Tax>  $taxes
     * @return array<int, array{tax: Tax, amount: int, included: bool}>
     */
    protected function charge(array $taxes, int $base, int $qty, int $fixedScale = 1): array
    {
        if ($base <= 0) {
            return [];
        }

        usort($taxes, fn (Tax $a, Tax $b) => [$a->priority, $a->position] <=> [$b->priority, $b->position]);

        // Rounding per unit is a legitimate (and elsewhere mandatory) choice
        // that differs from per-line by a cent on awkward quantities, so the
        // whole chain runs on one unit and is multiplied at the end.
        $perUnit = setting('torgash.taxes', 'rounding') === 'unit' && $qty > 0;
        $working = $perUnit ? (int) round($base / $qty) : $base;

        $charged = [];
        $running = $working;
        $previousPriority = null;
        $previousBase = null;

        foreach ($taxes as $tax) {
            $chargeBase = ($previousPriority !== null && $tax->priority === $previousPriority)
                ? $previousBase
                : $running;

            if ($tax->rate_type === Tax::FIXED) {
                $amount = (int) $tax->rate_amount * $fixedScale * ($perUnit ? 1 : $qty);
            } elseif ($tax->price_includes_tax) {
                $amount = $chargeBase - (int) round($chargeBase / (1 + $tax->rate_percent / 100));
            } else {
                $amount = (int) round($chargeBase * $tax->rate_percent / 100);
            }

            if (! $tax->price_includes_tax) {
                $running += $amount;
            }

            $previousPriority = $tax->priority;
            $previousBase = $chargeBase;

            $charged[$tax->id] = [
                'tax' => $tax,
                'amount' => $perUnit ? $amount * $qty : $amount,
                'included' => $tax->price_includes_tax,
            ];
        }

        return $charged;
    }
}
