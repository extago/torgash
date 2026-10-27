<?php

namespace Torgash\Catalog\Pricing;

use App\Core\Support\Currencies;
use Illuminate\Support\Facades\DB;

/**
 * The one place that turns "what the admin typed, in which currency" into the
 * columns a product carries — and the one place that recomputes them when a
 * rate moves.
 *
 * Two rules it exists to keep, both of which were easy to get wrong twice:
 *
 * 1. The TYPED number is the truth. A product priced at ₴42 000 is ₴42 000
 *    tomorrow as well; what moves is its dollar equivalent, and moving it is
 *    this class's job rather than something a shop discovers by comparing
 *    yesterday's export.
 * 2. The books column is always in the books' minor units. Everything else in
 *    the engine — sorting, filters, the cart, discounts, taxes, shipping,
 *    reports — reads it and knows nothing about any of this.
 *
 * A DERIVED price is rounded to the books currency's own precision — the
 * places it is shown with (`decimals`). A shop keeping dollars without cents
 * otherwise sold €89 as "$100" on the product page and charged $100.28 at the
 * till: the page rounds for display, the bill prints what is charged
 * (format_price exact), and both were honest about a number nobody chose. The
 * cost price is not rounded — it is nobody's shelf price, and a margin should
 * not move by a whole unit because of display.
 */
class Prices
{
    /** The three amounts a product carries, each with a `_original` twin. */
    public const FIELDS = ['price', 'cost_price', 'list_price'];

    /**
     * The columns to save, from the major-unit numbers the form posted.
     *
     * Only the fields actually present in `$typed` are touched, so the inline
     * edit on the list screen (which posts nothing but a price) cannot wipe a
     * cost price it never showed.
     *
     * @param  array<string, mixed>  $typed  field => major units, as typed
     * @param  string|null  $currency  what they were typed in; null/shop's own = no conversion
     * @return array<string, mixed>
     */
    public function columns(array $typed, ?string $currency): array
    {
        $currency = $this->normalise($currency);
        $rate = $currency ? $this->rate($currency) : 1.0;

        $columns = ['price_currency' => $currency];

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $typed)) {
                continue;
            }

            $value = $typed[$field];
            $minor = ($value === null || $value === '') ? null : (int) round((float) $value * 100);

            // In the shop's own currency there is nothing to remember and
            // nothing to derive: the typed number IS the stored number, and a
            // leftover `_original` would later be rebased over the top of it.
            $columns[$field.'_original'] = $currency ? $minor : null;
            $columns[$field] = $currency && $minor !== null ? $this->derive($minor, $rate, $field !== 'cost_price') : $minor;
        }

        // `price` is NOT NULL and always posted; the guard is for a caller that
        // passes an empty string rather than a number.
        if (array_key_exists('price', $columns) && $columns['price'] === null) {
            $columns['price'] = 0;
        }

        return $columns;
    }

    /**
     * The `price` / `price_original` pair for one satellite row — a wholesale
     * tier or a price-type override — from the major-unit number typed for it
     * and the currency it shares with its product.
     *
     * Same rule as a product's own amounts: in the shop's currency the typed
     * number IS the stored number and there is no `_original`; in another it is
     * kept verbatim and the books amount is derived.
     *
     * @return array{price: int, price_original: int|null}
     */
    public function row(mixed $typedMajor, ?string $currency): array
    {
        $currency = $this->normalise($currency);
        $minor = ($typedMajor === null || $typedMajor === '')
            ? 0
            : (int) round((float) $typedMajor * 100);

        if (! $currency) {
            return ['price' => $minor, 'price_original' => null];
        }

        return [
            'price' => $this->derive($minor, $this->rate($currency)),
            'price_original' => $minor,
        ];
    }

    /**
     * Recompute the books amounts of every product priced in `$code`.
     *
     * One statement per currency, not a model loop: this runs from a rate
     * change, which may touch the whole catalogue, and the arithmetic is
     * exactly what the database is for. NULL originals stay NULL — a product
     * with no cost price still has none.
     */
    public function rebase(string $code): int
    {
        $rate = $this->rate($code);

        if ($rate <= 0) {
            return 0;
        }

        // Formatted rather than bound: this goes inside a round() expression,
        // and a rate is a number we produced ourselves from the currency table.
        $divisor = sprintf('%.8F', $rate);
        $step = $this->step();
        $upper = strtoupper($code);

        $touched = DB::table('m_catalog_products')
            ->where('price_currency', $upper)
            ->update([
                'price' => DB::raw("round(price_original / {$divisor} / {$step}) * {$step}"),
                'cost_price' => DB::raw("round(cost_price_original / {$divisor})"),
                'list_price' => DB::raw("round(list_price_original / {$divisor} / {$step}) * {$step}"),
            ]);

        // The tiers and overrides of those same products move with them —
        // `price_original` is NULL for a row typed in the shop's own currency,
        // so those are left exactly as they are.
        foreach (['m_catalog_product_prices', 'm_catalog_product_price_type_overrides'] as $table) {
            DB::update(
                "UPDATE {$table} AS t
                 SET price = round(t.price_original / {$divisor} / {$step}) * {$step}
                 FROM m_catalog_products AS p
                 WHERE t.product_id = p.id AND p.price_currency = ? AND t.price_original IS NOT NULL",
                [$upper],
            );
        }

        return $touched;
    }

    /**
     * Forget that these products were ever priced in `$code`, keeping the books
     * amount they last had.
     *
     * For a currency the shop deletes: the alternative is a `price_currency`
     * pointing at nothing, which would silently stop rebasing and leave a price
     * that looks maintained and is not. The number the shop last agreed to is
     * kept, in the books' own currency, which is the only currency left.
     */
    public function forget(string $code): int
    {
        $upper = strtoupper($code);

        // Before the products row loses its `price_currency`, while the join
        // still finds them: the satellite rows keep the books amount they last
        // had and forget they were ever a converted number.
        foreach (['m_catalog_product_prices', 'm_catalog_product_price_type_overrides'] as $table) {
            DB::update(
                "UPDATE {$table} AS t
                 SET price_original = NULL
                 FROM m_catalog_products AS p
                 WHERE t.product_id = p.id AND p.price_currency = ?",
                [$upper],
            );
        }

        return DB::table('m_catalog_products')
            ->where('price_currency', $upper)
            ->update([
                'price_currency' => null,
                'price_original' => null,
                'cost_price_original' => null,
                'list_price_original' => null,
            ]);
    }

    /** Books minor units from another currency's, rounded to what the books show (see the class). */
    protected function derive(int $minor, float $rate, bool $shown = true): int
    {
        $step = $shown ? $this->step() : 1;

        return (int) (round($minor / $rate / $step) * $step);
    }

    /**
     * The smallest amount the books currency shows, in minor units: 1 for a
     * currency printed with cents, 100 for one printed in whole units.
     */
    protected function step(): int
    {
        return app(Currencies::class)->step();
    }

    /** A usable, non-shop currency, or null for "the shop's own". */
    protected function normalise(?string $code): ?string
    {
        $code = $code ? strtoupper($code) : null;

        if ($code === null || $code === '' || $code === shop_currency()) {
            return null;
        }

        return $this->rate($code) > 0 ? $code : null;
    }

    protected function rate(string $code): float
    {
        return (float) (app(Currencies::class)->find(strtoupper($code))?->rate ?? 0);
    }
}
