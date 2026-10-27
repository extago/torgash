<?php

namespace Torgash\Checkout\Orders;

use Illuminate\Support\Collection;

/**
 * Turning the cart's lines into the order's (PLAN §33.3, §33.8).
 *
 * The two are deliberately not the same list. A customer bought ONE thing — a
 * server configured a certain way — and that is how the cart shows it and how
 * "change this build" works. A warehouse, 1C, a refund and a return all deal
 * in goods, and the processors inside that server are goods: they come off a
 * shelf, they go into a document, they can come back on their own. So each
 * part that IS a product becomes its own order line, carrying `parent_key` to
 * say what it was bought as part of.
 *
 * What is not a product — an extended warranty, an engraving, a service priced
 * by a formula — has no line of its own while it repeats per unit: it is
 * inside the parent's `unit_price`, and the parent's `options` snapshot says
 * so in the customer's own words. A service charged ONCE for the line cannot
 * hide inside a unit price without inventing fractions of money, so that one
 * does get a line, with no product on it.
 *
 * The arithmetic is arranged so the two lists always add up to the same money:
 * the parent keeps the base price plus what folds in, every part that got a
 * line of its own takes its share with it. Nothing is printed twice, because a
 * part is in exactly one of the two places.
 */
class OrderLines
{
    /**
     * @param  Collection<int, object>  $items  what CartManager::items() answers
     * @return list<array<string, mixed>>
     */
    public static function fromBasket(Collection $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            array_push($lines, ...static::forLine([
                'key' => $item->key,
                'product_id' => $item->product->id,
                'sku' => $item->product->sku,
                'name' => $item->product->t('name'),
                'qty' => (int) $item->qty,
                'base_price' => (int) $item->base_price,
                'config' => (array) $item->config,
            ], $item->parts));
        }

        return $lines;
    }

    /**
     * ONE line and its parts, from what it is and what its configuration makes
     * of it — the derivation `fromBasket()` performs per basket row, and the
     * same one the admin performs when a manager changes a placed order's
     * build (PLAN §33.7).
     *
     * Two callers, one piece of arithmetic, deliberately: an order edited by
     * hand must come out shaped exactly like an order that was placed, or the
     * warehouse, 1C and a return would each meet a line no other code path can
     * produce.
     *
     * **The key is given, never computed.** In a CART a key is an identity
     * worked out from the answers, which is what makes two identical builds
     * one line. In an ORDER it is the name of something already sold: the
     * warehouse breakdown, the admin's own URL, a 1C document and a return all
     * point at it. So changing the build keeps the line's name and only
     * changes what it says — while the parts, whose keys are built from the
     * answers, come and go as the answers do, which is exactly what lets
     * `OrderStock` credit the old memory module and take the new one.
     *
     * @param  array<string, mixed>  $line  key, product_id, sku, name, qty, base_price, config
     * @param  list<array<string, mixed>>  $parts
     * @return list<array<string, mixed>>
     */
    public static function forLine(array $line, array $parts): array
    {
        $key = (string) $line['key'];
        $qty = max(1, (int) $line['qty']);
        $basePrice = (int) $line['base_price'];
        $config = (array) ($line['config'] ?? []);

        $folded = [];
        $children = [];
        $unitPrice = $basePrice;

        foreach ($parts as $part) {
            $ownLine = $part['product_id'] !== null || ! $part['per_unit'];

            if (! $ownLine) {
                $unitPrice += (int) $part['amount'] * (int) $part['qty'];
                $folded[] = [
                    'label' => $part['label'],
                    'qty' => (int) $part['qty'],
                    'amount' => (int) $part['amount'] * (int) $part['qty'],
                ];

                continue;
            }

            $partQty = (int) $part['qty'] * ($part['per_unit'] ? $qty : 1);

            $children[] = [
                'key' => $key.':'.$part['code'],
                'parent_key' => $key,
                'product_id' => $part['product_id'],
                'sku' => $part['sku'],
                'name' => $part['name'] ?: $part['label'],
                'qty' => $partQty,
                'unit_price' => (int) $part['amount'],
                'line_total' => (int) $part['amount'] * $partQty,
                // What it was chosen AS ("CPU"), which is not the same as
                // what it is ("Intel Xeon E5-2407") — a picking list wants
                // the second, an invoice reads better with both. The
                // GROUP's name, never the whole answer: that already
                // contains the product's name, and a line printing both
                // read "CPU: Xeon: Xeon" (measured in the admin).
                'option_label' => $part['group'] ?? $part['label'],
            ];
        }

        $parent = [
            'key' => $key,
            'product_id' => (int) $line['product_id'],
            'sku' => $line['sku'],
            'name' => $line['name'],
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'line_total' => $unitPrice * $qty,
        ];

        if ($folded !== []) {
            $parent['options'] = $folded;
        }

        // What was ANSWERED, kept beside what the answers came to.
        //
        // `options` above is a snapshot for READING — labels and money, in the
        // customer's own words, frozen so that renaming a choice never rewrites
        // an invoice. It cannot be re-configured from: "Memory: 16 GB ×2" does
        // not say which choice of which group that was. The owner's own rule
        // (§33.8) is that the line's configuration is the single thing stored
        // and the order's lines are DERIVED from it — so the order has to carry
        // it too, or the truth stops at the moment of placement and a manager
        // can only correct a build by deleting it.
        //
        // `base_price` is stored rather than worked back out of `unit_price`
        // minus the folded amounts: that subtraction is exact today and would
        // quietly stop being exact the day a module folds something in that is
        // not in `options`. Money may not depend on a display structure.
        //
        // Both only where there is a configuration, so an ordinary shop's
        // orders are byte for byte what they were.
        if ($config !== []) {
            $parent['config'] = $config;
            $parent['base_price'] = $basePrice;
        }

        return [$parent, ...$children];
    }

    /**
     * The lines of an order arranged for PRINTING: each parent followed by
     * whatever was bought as part of it, in the order the parts were chosen.
     *
     * A child whose parent is gone (a line deleted in the admin) is listed on
     * its own rather than dropped — it is still a line of this order, and
     * silently not printing something the customer is being charged for is the
     * one answer that cannot be right.
     *
     * @param  array<mixed>  $items
     * @return list<array{line: array<string, mixed>, children: list<array<string, mixed>>}>
     */
    public static function tree(array $items): array
    {
        $parents = [];
        $byParent = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $parentKey = (string) ($item['parent_key'] ?? '');

            if ($parentKey === '') {
                $parents[] = $item;

                continue;
            }

            $byParent[$parentKey][] = $item;
        }

        $known = array_map(fn (array $item) => (string) ($item['key'] ?? ''), $parents);
        $tree = [];

        foreach ($parents as $parent) {
            $key = (string) ($parent['key'] ?? '');
            $tree[] = ['line' => $parent, 'children' => $byParent[$key] ?? []];
        }

        foreach ($byParent as $parentKey => $orphans) {
            if (! in_array((string) $parentKey, $known, true)) {
                foreach ($orphans as $orphan) {
                    $tree[] = ['line' => $orphan, 'children' => []];
                }
            }
        }

        return $tree;
    }
}
