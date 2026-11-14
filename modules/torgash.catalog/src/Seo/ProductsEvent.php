<?php

namespace Torgash\Catalog\Seo;

use Illuminate\Support\Str;

/**
 * Products that moved — into a basket, out of a wishlist, into an order —
 * told to the page as an HTMX event with its detail (PLAN §73): lists of
 * products as ProductFacts::item() describes them (`added`, `removed`,
 * `lines`), whatever else the event says, and the books' `currency`. A
 * counter reports "added to the basket" from it without knowing the basket.
 *
 * It travels in the HX-Trigger header, so: ASCII JSON (`\uXXXX` — a Cyrillic
 * name raw in a header reaches the browser as mojibake), and within
 * MAX_BYTES. Past that the lines lose what is only description (brand,
 * categories, the long end of a name), then lines are left out from the end
 * of the LAST list (a basket's removals before its additions); what cannot
 * be said in the budget at all is the bare event, which every listener that
 * only wants to know "something changed" hears the same as before.
 */
class ProductsEvent
{
    /** Lines described at most, a list — each description is read from the database. */
    public const MAX_LINES = 20;

    /**
     * The header's budget. nginx keeps one memory page — 4 KB — for ALL of a
     * FastCGI answer's headers unless told otherwise; a basket's answer
     * carries 1.6 KB of others (the session and XSRF cookies, the content
     * security policy — measured), more with a counter's hosts in the
     * policy. An answer past it reaches the visitor as a 502.
     */
    public const MAX_BYTES = 1024;

    /**
     * The same kind of event raised by a PAGE rather than an answer: a
     * script of the page dispatches it once the page is parsed, so a
     * deferred listener is there by then. Not a header, so no budget.
     *
     * @param  array<string, mixed>  $detail
     */
    public static function script(string $event, array $detail): string
    {
        $json = json_encode($detail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return '<script nonce="'.e(csp_nonce()).'">(() => {'
            .' const fire = () => document.dispatchEvent(new CustomEvent('.json_encode($event).', { detail: '.$json.' }));'
            .' document.readyState === "loading" ? document.addEventListener("DOMContentLoaded", fire) : fire();'
            .' })();</script>';
    }

    /**
     * A basket's or a wishlist's change.
     *
     * @param  list<array<string, mixed>>  $added
     * @param  list<array<string, mixed>>  $removed
     */
    public static function header(string $event, array $added = [], array $removed = []): string
    {
        return self::described($event, array_filter(['added' => $added, 'removed' => $removed]));
    }

    /**
     * Any event that names products: its lists, and the facts beside them
     * (an order's number and total).
     *
     * @param  array<string, list<array<string, mixed>>>  $lists
     * @param  array<string, scalar|null>  $facts
     */
    public static function described(string $event, array $lists, array $facts = []): string
    {
        $lists = array_map(fn (array $list) => array_slice(array_values($list), 0, self::MAX_LINES), $lists);

        if (array_filter($lists) === [] && $facts === []) {
            return $event;
        }

        $json = self::encode($event, $lists, $facts);

        if (strlen($json) > self::MAX_BYTES) {
            $lists = array_map(fn (array $list) => array_map(self::slim(...), $list), $lists);
            $json = self::encode($event, $lists, $facts);
        }

        while (strlen($json) > self::MAX_BYTES && ($last = self::lastFilled($lists)) !== null) {
            array_pop($lists[$last]);
            $json = self::encode($event, $lists, $facts);
        }

        return strlen($json) > self::MAX_BYTES ? $event : $json;
    }

    /** @param  array<string, list<array<string, mixed>>>  $lists */
    protected static function lastFilled(array $lists): ?string
    {
        foreach (array_reverse($lists, true) as $key => $list) {
            if ($list !== []) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $lists
     * @param  array<string, scalar|null>  $facts
     */
    protected static function encode(string $event, array $lists, array $facts): string
    {
        $detail = array_filter($lists) + $facts + ['currency' => shop_currency()];

        return (string) json_encode([$event => $detail], JSON_UNESCAPED_SLASHES);
    }

    /**
     * What still says WHICH product moved and for how much — the same keys, so
     * a listener reads one shape either way.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    protected static function slim(array $line): array
    {
        return ['name' => Str::limit((string) ($line['name'] ?? ''), 60), 'brand' => null, 'categories' => []] + $line;
    }
}
