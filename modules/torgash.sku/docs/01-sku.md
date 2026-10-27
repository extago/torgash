# SKU templates

A product's SKU made by a template — the text as it is, and in braces what
changes from one product to the next:

| In braces | What it gives |
|---|---|
| `{n}`, `{n:6}` | a running number (zero-padded to 6 digits) |
| `{id}` | the product's own number |
| `{category}`, `{category:3}` | the category's address in capitals (its first 3 characters) |
| `{brand}`, `{brand:3}` | the brand's code |
| `{year}`, `{yy}` | this year |
| `{rand:4}` | random letters and digits (no O/0, I/1 — they get misread) |
| `{digits:4}` | random digits |
| `{ean13}` | a whole EAN-13 with its check digit, starting with 2 |

`SKU-{n:6}` gives `SKU-000001`, `{category:3}-{yy}-{n:4}` gives `SMA-26-0001`.
A token with nothing to say (a product without a brand) prints nothing, and
the dash it leaves doubled is tidied away.

## When it applies

Catalogue → SKU templates, "Give a new product a SKU by the template":

- **When the SKU is left blank** — the safe choice. Leave the field empty in
  the product form (or in an import) and the template fills it; a SKU typed
  in, or brought by 1C, is kept.
- **Always** — even over a SKU typed in. For a shop whose SKUs are only its own.
- **Never** — the template is used only by the renumbering below.

Without this module a product created with an empty SKU gets `P-<number>`.

## A template per category

A category may have a template of its own; its subcategories take it too,
and a category without one takes its parent's, else the shop's. A product in
several categories takes the deepest one.

A product's categories are attached after it is created, so the SKU is first
made by the template that applies then, and made again by the category's
once the product form is saved. An import keeps the shop's template — renumber
the category afterwards.

## Running numbers

Each template has its own sequence: two categories with the same template
share it, so no two products get the same SKU. Two products created at the
same moment never draw the same number.

## EAN-13

`{ean13}` makes a valid EAN-13 starting with 2 — the range GS1 leaves for a
shop's own goods: for its scanner and its labels. A marketplace wants a
GS1 member's number; that one comes from GS1, not from a template.

## Renumbering the products already there

Runs in the background (System → Background tasks) and saves each product through the
catalogue, so the audit keeps every old SKU:

- **Only the products nobody gave a SKU (P-…)** — tidies up after products
  created blank before the module was on;
- **Every product** — the typed SKUs too, the running number going on from
  where it is. A SKU the template wants may still belong to a product not
  yet renumbered; it is skipped, so the numbers can come out with holes;
- **Clear every SKU and number all the products again from 1** — the whole
  shop: the running numbers are reset and every old SKU set aside first, so
  the products get 1, 2, 3… in the order they were added, with no holes. If
  the task is stopped, the old SKUs are put back; a product left with a
  temporary `~~…` SKU is counted on the screen — start it again to finish.

An import, 1C or a marketplace that knows the old SKUs will no longer find
the products by them, which is why the screen asks first.

## SKUs and 1C

With "Always — even over a SKU typed in", a product created by the exchange
gets the template's SKU at once. On the NEXT exchange 1C sends its article
again and writes it back — unless "Article number" is ticked in CommerceML's
"Fill these on the first import only". Tick it when the SKUs are the shop's
own; 1C still recognises its products by their identifier, not the SKU.

The first two can be narrowed to a category, with everything under it.
