<?php

namespace Torgash\Taxes;

use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\LocationStore;
use Torgash\Taxes\Models\Tax;
use Torgash\Taxes\Models\TaxRate;

/**
 * What a tax charges at an address — the one place that question is answered.
 *
 * The narrowest row naming the address wins (postal code › pattern › region ›
 * country), ties by the row's own position; nothing named means the tax's own
 * rate, unless the tax is charged `only_listed`, in which case nothing named
 * means nothing charged. An address nobody has given yet is answered the same
 * way as one no row names: the product page's "incl. VAT 20%" and a cart
 * before the town box is filled show the rate for everywhere else, and a tax
 * that is only charged somewhere in particular is not shown at all until the
 * customer says they are there.
 *
 * The answer is the Tax itself, COPIED with the rate that applies there — so
 * the calculator, the labels and the order snapshot, which all read
 * `rate_percent` / `rate_amount` off a Tax, need to know nothing about where.
 * The copy is never saved; it lives for one calculation.
 *
 * An INCLUDED tax at another rate does not change the price on the shelf: the
 * price is final and a different share of it is VAT (an export at 0% prints
 * "incl. VAT 0%" under the same number). That is how older engines behave too, and
 * the alternative — a price that moves when the customer names a country — is
 * a price the customer was not shown.
 *
 * Scoped, and forgotten on every request: `visitor()` remembers where THIS
 * visitor said they are.
 */
class Destinations
{
    /** @var array<int, list<TaxRate>>|null tax_id => rows, narrowest first */
    protected ?array $rates = null;

    protected ?Location $visitor = null;

    /** @var array<string, Location> a location with its region filled in */
    protected array $completed = [];

    /**
     * The taxes as charged at `$where`: each one copied with the rate that
     * applies there, and the ones not charged there left out.
     *
     * @param  list<Tax>  $taxes
     * @return list<Tax>
     */
    public function apply(array $taxes, ?Location $where): array
    {
        return array_values(array_filter(array_map(fn (Tax $tax) => $this->at($tax, $where), $taxes)));
    }

    /** One tax at one address; null when it is not charged there. */
    public function at(Tax $tax, ?Location $where): ?Tax
    {
        $row = $this->rowFor($tax, $where);

        if ($row === null) {
            return $tax->only_listed ? null : $tax;
        }

        if (! $row->charged) {
            return null;
        }

        $copy = clone $tax;
        $copy->rate_percent = $tax->rate_type === Tax::PERCENT ? (float) $row->rate_percent : null;
        $copy->rate_amount = $tax->rate_type === Tax::FIXED ? (int) $row->rate_amount : null;

        return $copy;
    }

    /** The row that decides, or null when none names this address. */
    public function rowFor(Tax $tax, ?Location $where): ?TaxRate
    {
        $where = $where ? $this->complete($where) : null;

        if ($where === null || $where->isEmpty()) {
            return null;
        }

        foreach ($this->rates()[$tax->id] ?? [] as $row) {
            if ($this->matches($row, $where)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Where the visitor of THIS request said they are — the cart page's town
     * box, remembered by checkout. Empty for anybody who has not said, and
     * always for a machine: a session is the only place it is kept.
     */
    public function visitor(): Location
    {
        // On a page kept ready for guests (PLAN §61) the town is read for
        // the rates alone, and every copy is filed under them (signature()).
        return page_cache()->under('tax_place', fn () => $this->visitor ??= app(LocationStore::class)->current());
    }

    /**
     * What the visitor's town makes of the taxes, in one word: for each tax
     * charged somewhere in particular, the row that decides it there. Two
     * visitors with the same word are charged alike — the town where no row
     * applies included, which is everybody who has not said. Every page
     * kept ready for guests is filed under it (PLAN §61); a shop whose taxes
     * name no place is one word for everybody.
     */
    public function signature(): string
    {
        $resolver = app(TaxResolver::class);

        if (! $resolver->active() || $this->rates() === []) {
            return '';
        }

        $where = $this->visitor();
        $parts = [];

        foreach ($resolver->all() as $tax) {
            if (($this->rates()[$tax->id] ?? []) !== []) {
                $parts[] = $tax->id.':'.($this->rowFor($tax, $where)?->id ?? '-');
            }
        }

        return implode(',', $parts);
    }

    /**
     * Where an order went. The region is not stored on the order — it is
     * derived from the town, the same way checkout derived it for the zone.
     */
    public static function forOrder(Order $order): Location
    {
        return $order->place_id
            ? Location::forPlace((int) $order->place_id, $order->postal_code)
            : new Location(country: $order->country, postal: $order->postal_code);
    }

    /**
     * A town settles its region, and a caller holding only the town (the
     * shop API sends `place_id`, not a region) must still match a row naming
     * the region.
     */
    protected function complete(Location $where): Location
    {
        if ($where->state !== null || $where->placeId === null) {
            return $where;
        }

        $key = $where->placeId.'|'.$where->postal.'|'.$where->country;

        return $this->completed[$key] ??= (function () use ($where) {
            $resolved = Location::forPlace($where->placeId, $where->postal);

            return new Location(
                country: $resolved->country ?? $where->country,
                state: $resolved->state,
                placeId: $where->placeId,
                postal: $where->postal,
            );
        })();
    }

    protected function matches(TaxRate $row, Location $where): bool
    {
        return match ($row->kind) {
            'country' => $where->country !== null && strtoupper($where->country) === $row->value,
            'state' => $where->country !== null && $where->state !== null
                && strtoupper($where->country).':'.$where->state === $row->value,
            'postal' => $where->postal !== null && $where->postal !== ''
                && self::postalMatches($row->value, $where->postal),
            default => false,
        };
    }

    /**
     * The usual postal syntax, which the shipping zone form uses too: `*` is
     * any run of characters, `?` is exactly one; anything else is literal.
     * Spaces and case are not part of a postal code ("sw1a 1aa").
     */
    public static function postalMatches(string $pattern, string $postal): bool
    {
        $normalise = fn (string $s) => strtoupper(str_replace(' ', '', trim($s)));

        $regex = strtr(preg_quote($normalise($pattern), '/'), ['\*' => '.*', '\?' => '.']);

        return (bool) preg_match('/^'.$regex.'$/', $normalise($postal));
    }

    /** @return array<int, list<TaxRate>> */
    protected function rates(): array
    {
        return $this->rates ??= TaxRate::query()
            ->orderByDesc('level')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('tax_id')
            ->map(fn ($rows) => $rows->values()->all())
            ->all();
    }
}
