# Filter pages

A filter page is a category plus a set of characteristic values, at an address
of its own — `/catalog/laptops/apple` instead of
`/catalog/laptops?feature[2][]=9`.

## Why they exist

A query string is not a page. The shop strips every parameter that is not part
of what a page IS before it prints a canonical address, so a filtered listing is
never an entry in a search index — it tells search engines it is really the
category. That is correct, and it means "Apple laptops" is a thing people search
for and the shop has no page for.

A filter page is that page. Once it exists, the filtered listing points its
canonical at it, so the shop has one indexable address for that set of products
instead of an unbounded number of unindexable ones.

## Two ways to get one

**By hand** — Catalogue → Filter pages → New filter page. Pick the category,
tick the values, give it a name. Use this for combinations of two
characteristics ("Apple laptops with 16GB"), which are always few.

**By a rule** — Catalogue → Filter pages → Rules. A rule says "a page per value
of this characteristic, in these categories (and everything under them)", and
the button makes them in the background.

A rule uses one characteristic on purpose: a rule that multiplied two of them
would publish the square of the catalogue.

## What a rule does and does not own

It owns the **address** and the **name** — what the page is called in the
category list and in the breadcrumb.

It does not own the title, the description or the H1. Those come from the SEO
templates (`torgash.seo`), the same ones that write them for products,
categories and brands. Write a template for the "Filter pages" subject and use
`{filter}`, `{category}`, `{total_products}`, `{min_price}` in it — on a filter
page the price and count tokens answer about that page's own, narrower set.

Each page also carries its own SEO card, so any one of them can be written by
hand and will beat the template.

## Running a rule twice

Nothing happens twice. A run adds what is missing and refreshes what it already
made; it never removes a page.

That is deliberate. Once a page has been crawled it is a published address, and
withdrawing it because a supplier was late gives a 404 on a page search engines
already hold. So a page whose product count falls below the rule's minimum
leaves the **sitemap** and keeps answering, and rises back into the sitemap when
it refills.

A page with nothing in it right now is marked "do not index" while it is empty —
worked out as the page is rendered, not from last night's count — and still
opens.

## Removing one

Delete it on its own screen. Its address then redirects (301) to its category,
which is genuinely the page it was a subset of.

Deleting a **rule** does not delete the pages it made: they are published
addresses, and from that moment they are ordinary hand-written pages.

## Where they are linked from

Published filter pages of a category are listed on the category page, where a
list of subcategories would be. An address nothing links to is an address
nothing crawls.

## City pages

The same screen makes the other kind of published address: a category in a
town — "Laptops in Kyiv" at `/catalog/laptops/kyiv`.

It is the same row with a **Town** on it, and the difference from a filter page
is the one that matters: a filter page holds *fewer* products than its category,
a city page holds *the same ones under different words*. So nothing
canonicalises onto a city page — there is no query string that means "in Kyiv",
so no listing is duplicating it — and its values may be left empty. Fill both
and you get "Apple laptops in Kyiv".

The Town field needs the place registry (`torgash.locations`) installed; without
it the field is not shown.

### The words

Write a template for the **City pages** subject in `torgash.seo` and use
`{location:N}`, where N is the case:

| N | Russian | Ukrainian |
|---|---------|-----------|
| 1 | Киев | Київ |
| 2 | Киева | Києва |
| 3 | Киеву | Києву |
| 4 | Киев | Київ |
| 5 | Киевом | Києвом |
| 6 | в Киеве | у Києві |
| 7 | — | Києве |

So `{category} в {location:6}` reads "Ноутбуки в Киеве". The forms are worked
out from the town's name by the rules of each language, which is why nobody has
to type six words for each of a hundred thousand towns — and where a rule gets
one wrong, open the town (Locations → the town) and type the correct form into
the box. The greyed-out word in each box is what the shop would say by itself.

`{location_region}` is the town's region, for a title that needs it.

### A page per town

A rule can do it: choose "a page per **town**" instead of "per value of a
characteristic", pick the towns the shop actually sells into, and the run makes
one page per town in each category the rule names.

Pick the towns deliberately. A pack holds a hundred thousand of them, which is
why "every town" is not offered as a default — and a rule carries one axis, so
towns × makes of laptop cannot be published from one screen.

The address is written from the base language (`kyiv`, never `kiev` one night
and `киев` the next), because an address may never move.

### What arriving on one does

It answers the delivery question: a visitor who came to the Kyiv page has said
where they are, so the cart's delivery estimate uses that town — unless the
customer has already named one themselves, which is never overwritten.
