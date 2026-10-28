# Characteristics

A product's characteristics are kept in two places. They look alike on the
product page, but they are not two copies of the same table, and they do
different jobs.

## The shop's characteristics

Catalogue → Features. Each one has a name, a code and usually a list of
values ("Colour": black, white, blue). On a product they are the
**Global characteristics** card on the Attributes tab.

These are the ones the shop **works with**:

- the filter panel and filter pages;
- variations — the products of one group are told apart by them;
- the brand and the brand's page;
- labels that print `[feature:CODE]`;
- the short description composed from characteristics.

Where a characteristic is drawn is set on the characteristic itself, under
"Shown in": the filter panel, the product page's specifications, the short
list under the buy button. Untick all three and it is still there: it keeps
working in addresses, filter pages and variations.

## The product's own rows

The **Individual attributes** card on the same tab. These are words for one
product's page — "Warranty: 2 years", "In the box: cable, case". They are
printed under the shop's characteristics, found by the search and shown in
the comparison under "Other". **Nothing filters by them**, they do not make
variations, labels and the short description do not see them.

A few things read both, the shop's first: `{feature:…}` in templates and
texts, delivery conditions, and the weight setting. They look for a shop
characteristic with that code (or name), and only when there is none do they
take an own row whose name is written exactly the same.

A feed (Yandex Market's `<param>` lines, `item.params` in an export
template) lists the shop's characteristics and then the own rows. An own row
called like a characteristic the product already has a value of is left
out: a marketplace takes two lines with one name for a mistake.

## Which one is the real one

If a fact matters for more than the page — a customer filters by it, products
differ by it, a label shows it — it belongs among the **shop's
characteristics**. An own row with the same name is only words on the page.

Changing a colour in the own rows changes what the page says and nothing else:
the filter still finds the product under its old colour, because the filter
reads the shop's characteristic.

## When the page shows the same thing twice

If a product has a value of a shop characteristic shown in the specifications
**and** an own row with the same name, the page prints both lines:

    RAM            16 GB
    RAM            16 GB DDR4, two slots

The product form warns about it above the own rows, and Security → Invariants
lists every such product ("No product page prints a characteristic twice").
Nothing is changed for you — there are two good ways out:

- **The own row is just a longer wording.** The filter needs "16 GB", the page
  wants the details. Open the characteristic and untick "Specifications" under
  "Shown in": it keeps filtering, and the page prints only your own row.
- **The own row is a leftover.** Delete it; the value is in the shop's
  characteristic.

## Merging values

"тёмно‑синий" and "темно-синий", "Gold" and "gold" — one value written two
ways, and each holds only its own products: a customer who ticks one in the
filter misses the rest, and each has a filter page of its own. Open the
characteristic and press **Merge values** under its list. Choose the value to
keep, tick the ones to merge into it, and merge. Values written alike are
offered at the top of the window in one click, and the page warns about them.

What happens:

- their products get the main value — and so do the filter pages, labels,
  markup rules, collections, promotions and 1C links that named them;
- a filter page that becomes a copy of another is deleted, and its address
  redirects (301) to the other one; empty texts of the page that stays are
  filled from it;
- for a brand, the main value takes the page's address, logo and texts it
  does not have yet; the other brand addresses redirect to it;
- the merged values are deleted, but their spellings stay with the main value
  ("Also written" under it): an import and an old filtered address still find
  it by them.

Two products of one variation group cannot become the same: if the values being
merged are what tells them apart, nothing is merged and the products are named.
Change the value on one of them (or remove the extra product) first.

A spelling under a value is removed with its ×; after saving, the next import
makes it a value of its own again.

The import, the values list and Security → Invariants ("No characteristic has
one value written two ways") treat letter case, ё and е, dashes (the
non-breaking hyphen too), spaces and curly quotes as one spelling: a supplier's
file finds the value the shop already has, and a new value written like an
existing one is refused. Different words ("gold" and "golden") are yours to
merge.

## Import, 1C and the API

Each source writes to one place, never to both:

| Source | Where it goes |
|---|---|
| A column named after a characteristic ("Colour") | the shop's characteristic |
| "Colour: S[Black]; Memory: S[128 GB]" | the shop's characteristics (created if missing) |
| Brand, "VAR Feature 2 [Colour]" | the shop's characteristics |
| A JSON list of sections and rows in "Characteristics" | the product's own rows, as written |
| 1C (CommerceML) | the shop's characteristics you have linked |

A JSON row called like one of the shop's characteristics stays an own row —
the import does not guess, and never invents filter values from free text. If
you want it to filter, put it in a column of its own.

The API shows both: `characteristics` (the shop's, with codes) and `features`
(the product's own rows).
