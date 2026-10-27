<?php

namespace Torgash\Cart;

use Torgash\Catalog\Models\Product;

/**
 * What a cart line IS, now that it is no longer "a product id and a number"
 * (PLAN §33.3).
 *
 * A line is `{key, product_id, qty, config}`: two servers configured
 * differently are two lines of the same product, and two servers configured
 * the same are one line of two. The KEY is what says which — the product id
 * alone for a line with no configuration, which is deliberate: every cart
 * written before this, every link and every API call that addresses a line by
 * product id keeps working, and a plain shop never sees a hash.
 *
 * Whatever a configuration MEANS is not this module's business. Cart knows
 * only that a module may answer `cart.line.config` (normalise and validate
 * what was submitted — the key is computed from the normalised form, so two
 * spellings of one configuration are one line) and `cart.line.parts` (what
 * the configuration adds to the line: a name, a price, sometimes a product of
 * its own). Everything about options, warranties and services lives in
 * `torgash.options`; this is the shape they arrive in.
 *
 * A PART is one thing inside the line:
 *
 *     ['code' => 'g7:v3', 'label' => 'CPU: Intel Xeon E5-2407', 'group' => 'CPU',
 *      'qty' => 2, 'per_unit' => true, 'amount' => 170000,
 *      'product_id' => 41, 'sku' => …, 'name' => …, 'weight' => 900,
 *      'url' => …, 'image' => …]
 *
 * `amount` is the price of ONE of it, in the shop's books like every other
 * money column. `per_unit` is the difference between "two processors in each
 * of the two servers" (four) and "one installation for this line however many
 * you buy" — and it is why a line's money is THREE numbers: `unit_price`
 * (the product plus everything that repeats per unit), `extra_total` (what is
 * charged once for the line) and `line_total`, which is the only one anybody
 * downstream adds up.
 */
class Lines
{
    /**
     * Read whatever is stored into lines.
     *
     * Two shapes are accepted for ever: the list of lines this writes, and the
     * `{product_id: qty}` map every cart held until PLAN §33 — the row is the
     * customer's basket, and a shop upgrading mid-afternoon may not empty it.
     *
     * @param  array<mixed>  $stored
     * @return list<array{key: string, product_id: int, qty: int, config: array<string, mixed>}>
     */
    public static function normalise(array $stored): array
    {
        $lines = [];

        foreach ($stored as $storedKey => $value) {
            // The old map: the key is the product, the value is the number.
            if (! is_array($value)) {
                $productId = (int) $storedKey;
                $qty = (int) $value;
            } else {
                $productId = (int) ($value['product_id'] ?? 0);
                $qty = (int) ($value['qty'] ?? 0);
            }

            if ($productId <= 0 || $qty <= 0) {
                continue;
            }

            $config = is_array($value) && is_array($value['config'] ?? null) ? $value['config'] : [];
            $key = is_array($value) && is_string($value['key'] ?? null) && $value['key'] !== ''
                ? $value['key']
                : static::key($productId, $config);

            // One key, one line: a cart that somehow holds two lines with the
            // same key (a race on a shared cart token) adds up rather than
            // silently losing one of them.
            if (isset($lines[$key])) {
                $lines[$key]['qty'] += $qty;

                continue;
            }

            $lines[$key] = ['key' => $key, 'product_id' => $productId, 'qty' => $qty, 'config' => $config];
        }

        return array_values($lines);
    }

    /**
     * The address of a line.
     *
     * A bare product id while there is nothing to configure — see the class
     * docblock — and the product plus a short digest of the configuration
     * otherwise. A DIGEST rather than the configuration itself because this
     * goes in a URL and into HTMX attributes, and because the same choice
     * expressed in a different order has to come out as the same line.
     *
     * @param  array<string, mixed>  $config
     */
    public static function key(int $productId, array $config = []): string
    {
        $config = static::canonical($config);

        return $config === []
            ? (string) $productId
            : $productId.'-'.substr(hash('sha256', json_encode($config, JSON_UNESCAPED_UNICODE)), 0, 12);
    }

    /**
     * A configuration AS THE PAGE SENT IT (a form's own field shapes), put in
     * the one spelling `cart.line.parts` reads — for a QUOTE: nothing is
     * refused (a half-answered form still prices what is answered) and nothing
     * is kept (a file is not taken). `cart.line.config` is the same thing plus
     * those two, for a line actually going into the basket; a module that
     * reads a configuration answers both (`cart.line.spell`).
     *
     * Without it a kit drawn under a configured product quoted the BARE
     * product: the answers arrived as the form spells them and the parts seam
     * found none of them (measured).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function spelled(Product $product, array $config): array
    {
        return static::canonical((array) hook_filter('cart.line.spell', $config, ['product' => $product]));
    }

    /**
     * The configuration in ONE spelling: keys sorted at every level, blank
     * answers dropped. Two customers who picked the same processor in a
     * different order are buying the same server.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function canonical(array $config): array
    {
        $clean = [];

        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $value = static::canonical($value);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $clean[(string) $key] = $value;
        }

        ksort($clean, SORT_STRING);

        return $clean;
    }

    /**
     * What a module makes of this line's configuration — asked once per line,
     * and asked of nobody in a shop with no options module, in which case a
     * line is a product and a number exactly as before.
     *
     * `$base` is the price the answers are measured against, and it is the
     * product's own unless a caller says otherwise. The one caller that does
     * is the admin re-configuring a PLACED order: that line's product has a
     * price the customer already agreed to, and repricing a warranty at 10% of
     * what the product costs today would move a number nobody asked to move.
     * A cart passes nothing, so a shop sees exactly what it saw before.
     *
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    public static function parts(Product $product, int $qty, array $config, ?int $base = null): array
    {
        // Asked for EVERY line, configured or not. The short-circuit that used
        // to sit here ("no configuration, no parts") looked free and was not:
        // a kit assembling itself out of an ordinary basket prices a line the
        // customer never configured (PLAN §33.5), and the cart would have
        // asked nobody about it. A shop with no such module answers with an
        // empty array and nothing else happens.
        $parts = hook_filter('cart.line.parts', [], [
            'product' => $product,
            'qty' => $qty,
            'config' => $config,
            'base' => $base,
        ]);

        return array_values(array_filter(array_map(
            fn ($part) => is_array($part) ? static::part($part) : null,
            is_array($parts) ? $parts : [],
        )));
    }

    /**
     * One part, with every field the rest of the shop reads present — a
     * missing `per_unit` must not make a warranty cost nothing.
     *
     * @param  array<string, mixed>  $part
     * @return array<string, mixed>|null
     */
    public static function part(array $part): ?array
    {
        $label = trim((string) ($part['label'] ?? ''));
        $productId = (int) ($part['product_id'] ?? 0) ?: null;

        if ($label === '' && ! $productId) {
            return null;
        }

        return [
            'code' => (string) ($part['code'] ?? $label),
            'label' => $label,
            // What it was chosen AS ("Processor"), where `label` is the whole
            // answer ("Processor: Intel Xeon E5-2407"). An order line prints
            // the first beside the product's own name, or it reads twice.
            'group' => isset($part['group']) ? (string) $part['group'] : null,
            'qty' => max(1, (int) ($part['qty'] ?? 1)),
            'per_unit' => (bool) ($part['per_unit'] ?? true),
            'amount' => (int) round((float) ($part['amount'] ?? 0)),
            'product_id' => $productId,
            'sku' => isset($part['sku']) ? (string) $part['sku'] : null,
            'name' => isset($part['name']) ? (string) $part['name'] : ($label ?: null),
            'weight' => max(0, (int) ($part['weight'] ?? 0)),
            'url' => isset($part['url']) ? (string) $part['url'] : null,
            'image' => isset($part['image']) ? (string) $part['image'] : null,
        ];
    }

    /**
     * What one part adds to the line: its price times how many of it, times
     * the line's own quantity when it repeats per unit.
     *
     * @param  array<string, mixed>  $part
     */
    public static function partTotal(array $part, int $qty): int
    {
        return (int) $part['amount'] * (int) $part['qty'] * ($part['per_unit'] ? max(1, $qty) : 1);
    }

    /**
     * The three numbers, worked out in one place so the cart, the checkout
     * summary and `PlaceOrder` cannot come to different totals for one line.
     *
     * @param  list<array<string, mixed>>  $parts
     * @return array{unit_price: int, extra_total: int, line_total: int}
     */
    public static function money(int $basePrice, array $parts, int $qty): array
    {
        $perUnit = 0;
        $perLine = 0;

        foreach ($parts as $part) {
            if ($part['per_unit']) {
                $perUnit += (int) $part['amount'] * (int) $part['qty'];
            } else {
                $perLine += (int) $part['amount'] * (int) $part['qty'];
            }
        }

        $unitPrice = $basePrice + $perUnit;

        return [
            'unit_price' => $unitPrice,
            'extra_total' => $perLine,
            'line_total' => $unitPrice * max(1, $qty) + $perLine,
        ];
    }

    /**
     * The grams a line adds beyond its product's own weight — a configured
     * server weighs its processors too. Nothing to do with a part having no
     * weight: an untracked one contributes 0, exactly as an unmeasured product
     * does (and `weightIsKnown()` is the separate question about that).
     *
     * @param  list<array<string, mixed>>  $parts
     */
    public static function partsWeight(array $parts, int $qty): int
    {
        $grams = 0;

        foreach ($parts as $part) {
            $grams += (int) $part['weight'] * (int) $part['qty'] * ($part['per_unit'] ? max(1, $qty) : 1);
        }

        return $grams;
    }
}
