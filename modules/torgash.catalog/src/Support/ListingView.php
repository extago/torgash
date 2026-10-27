<?php

namespace Torgash\Catalog\Support;

use Illuminate\Http\Request;

/**
 * How a VISITOR wants a listing drawn: how many products to a page, and as a
 * grid of cards or a list of compact rows (the wholesale buyer's view — a
 * screen of forty lines with a quantity beside each, not twelve photographs).
 *
 * Neither changes WHICH products a listing holds, so neither is a filter and
 * neither touches the canonical (`PageMeta::$canonicalParams` keeps `page`
 * alone). Both words are reserved in `FilterQuery::RESERVED` all the same:
 * what an address means may not depend on a characteristic somebody coded
 * `view`.
 *
 * Read from the address first — a link with `?per_page=48` shows 48 for
 * whoever opens it — then from a cookie, so the choice follows the visitor
 * from one category to the next. The cookie is written by the PAGE's script
 * when the visitor clicks, never by the server answering a GET: a browser's
 * link prefetcher follows a GET on its own — the reason the admin's currency
 * switcher is a POST form (CLAUDE.md). That is also why the two cookies are on EncryptCookies' except list
 * — the browser writes them in plain text, and Laravel would otherwise read
 * an unencrypted cookie as absent.
 */
class ListingView
{
    public const PER_PAGE_COOKIE = 'catalog_per_page';

    public const LAYOUT_COOKIE = 'catalog_view';

    public const LAYOUTS = ['grid', 'list'];

    /**
     * The page sizes a visitor can switch between: the shop's list
     * (`per_page_choices`), with the shop's default size always among them —
     * a switch that cannot get back to where the page started is a trap.
     * Empty when the shop has left the list blank: no switch at all.
     *
     * @return list<int>
     */
    public function choices(): array
    {
        $typed = (string) setting('torgash.catalog', 'per_page_choices', '24 48 96');

        $choices = array_values(array_filter(
            array_map('intval', preg_split('/[\s,;]+/', $typed, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            fn (int $size) => $size > 0 && $size <= 200,
        ));

        if ($choices === []) {
            return [];
        }

        $choices[] = $this->defaultPerPage();
        $choices = array_values(array_unique($choices));
        sort($choices);

        return $choices;
    }

    public function defaultPerPage(): int
    {
        return max(1, (int) setting('torgash.catalog', 'per_page', 12));
    }

    /**
     * How many products THIS request shows. Only a size on offer: an address
     * saying `?per_page=100000` is somebody asking the database for the whole
     * catalogue in one page, and the answer is the shop's default.
     */
    public function perPage(Request $request): int
    {
        $choices = $this->choices();

        foreach ([$request->query('per_page'), $request->cookie(self::PER_PAGE_COOKIE)] as $asked) {
            if (is_scalar($asked) && in_array((int) $asked, $choices, true)) {
                return (int) $asked;
            }
        }

        return $this->defaultPerPage();
    }

    /** 'grid' or 'list'; anything else is the grid. */
    public function layout(Request $request): string
    {
        foreach ([$request->query('view'), $request->cookie(self::LAYOUT_COOKIE)] as $asked) {
            if (is_string($asked) && in_array($asked, self::LAYOUTS, true)) {
                return $asked;
            }
        }

        return 'grid';
    }
}
