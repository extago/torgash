# Search

Answers product search — on the results page (`/search`) and, in the next
steps, in the live window while typing. Both ask one engine.

It finds by the **start of a word** ("lap" → "Laptop"), by **another form of
the word** in its own language ("laptops"), in **every language** a product is
named in, by **SKU** (exact first; run together too — "ab100" finds "AB-100"),
by **brand** always and by **characteristics** marked "Search" in their own
form, on the **wrong keyboard layout** ("yjen,er" → "ноутбук") and **with a typo**
("lapotp" → "laptop") — corrected by a dictionary of the catalogue's own words,
with no outside service, by edit distance (so a word that merely looks alike,
"айфон" and "смартфон", is not a typo; numbers are never corrected) — saying so
on the page in both last cases. When a query did find something but a
corrected one would find more, the correction is offered as **"Did you mean"**
rather than substituted. Only what the visitor may be shown; sold-out last if the catalogue says
so.

Every product has a search document, rebuilt by itself whenever the product,
its characteristics, a value's name or where a characteristic is shown
changes. Marketing → Search shows how many have one, tries a query and rebuilds
the index; `php artisan search:reindex` does it from the command line.

The engine is a registry of sources (`search.retrievers`), so a meaning-based
(vector) source can join later as a module of its own.

## What the window shows

Pictures, prices, SKUs, stock and the "add to cart" button are each switched
on or off in the module's settings. A product already in the basket (as
itself, not configured) shows a green "In cart" tick linking to the basket —
and the button turns into it the moment the add succeeds. What was typed is in
bold: the start of a word, or the stem another form of it shares; a typo is
bold as corrected. The window starts with "Products in the window" and loads as
many again each time it is scrolled to the end. "Search the rest of the site
too" adds information pages (and whatever other modules offer) in the window
and on the results page, and a switch inside the search box — "Across the site"
/ "In the catalogue" — lets the visitor choose; the choice rides the query and
is remembered for the visit. A theme places it through
`storefront.search.controls` inside its search form.

## The window while typing

Under the header's search box: products (picture, price — a "from" for one
that must be configured first —, stock, and "add to cart"; "Choose" instead for
a product that must be configured, nothing for one out of stock), brands whose
name begins with what was typed, the categories of what was found (each
narrows the search to itself and everything under it), information pages
(answered by the pages module) and "all results". Before enough letters are
typed, the visitor's own recent searches (kept in their session, clearable).
Keyboard: arrows, Enter, Escape. One product found as typed opens its page —
never after a layout reading or a typo. The window attaches to any form marked
`data-live-search`; it is rate-limited (120 a minute per address) and private
to crawlers.
