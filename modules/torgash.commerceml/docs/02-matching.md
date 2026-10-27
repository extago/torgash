# What matches what

## Two ways a thing is recognised

**By its own identifier**, where the shop has a column for one. Products,
categories, warehouses, orders and customers all carry `external_id`, and the
1C identifier goes there. That is the shop's convention for every exchange, not
this module's, and it is why renaming a category on either side breaks nothing.

**Through the matching screen**, where the shop has no such column.
Characteristics, their values and price types belong to the catalogue module,
and a module does not add columns to another module's tables. Their link lives
in this module's own, which is also the only place that can hold *seen, and not
decided about yet* — which is what makes that screen a queue rather than a form.

## The first exchange on a catalogue that already exists

An existing product has no 1C identifier, so there is a one-time bridge:
**Recognise a product by its article number** and **Recognise a category by its
name**. Both apply only to rows that have no identifier yet. Once matched, the
identifier is written onto the row and the bridge is never used for it again.

A category that already belongs to a different 1C group is not a candidate,
however alike the names.

## The three answers

Every row on the matching screen offers the same three, plus the shop's own
things to point at:

* **not decided** — the default, and it means *skipped*. Nothing carrying this
  is imported. The exchange counts them and says so in the message 1C prints.
* **Create one at the next exchange** — make it in the shop, using 1C's name,
  and link the two.
* **Never import this** — a permanent no. Different from "not decided" only in
  that it stops the row nagging.

A characteristic's values are listed under it and saved with it, because
"import this characteristic, and these of its values" is one decision. A value
cannot be created before its characteristic is — it has nowhere to hang — so it
stays in the queue under a parent that is still unanswered.

## Price types, and the thing that goes wrong

`ТипыЦен` is the part that can do real damage. A shop sells at retail and buys
at wholesale, and 1C sends both down the same wire with nothing but an
identifier to tell them apart. An importer that takes "the first price it finds"
will, one day, publish the purchase price.

So an unmatched price type writes **nothing**. Not the base price, not a guess.

What a 1C price type can point at is either one of your own price types, or one
of three columns:

| Local side | Fills |
|---|---|
| The base price | `price` — what the product sells for |
| The old (struck-through) price | `list_price` |
| The cost price | `cost_price` — never shown to a customer |

The money then goes through the shop's own pricing: 1C sends major units and
names a currency, and what is stored is the typed number in that currency plus a
books amount derived from it — exactly as if a person had typed a price in euro
into the product form. A later change to the exchange rate reprices it
correctly, which writing the column directly would not.

## Attributes: `ЗначенияРеквизитов`

Everything CommerceML has no element for lives in a bag of named attributes, and
the names are whatever that installation calls them: "Вес", "Вес, кг",
"ВесБрутто", "Масса". There is no canonical spelling to guess, so the module's
settings take a **list of names per field, one per line**, and the last one
actually present wins. Weight is read in kilograms, dimensions in centimetres,
and both are stored the way this shop stores them everywhere — whole grams and
whole millimetres.

## What an exchange will not overwrite

The settings that matter most are the ones about *not* writing, because an
exchange runs unattended and nightly against a shop somebody arranged by hand:

* **Fill these on the first import only** — names and descriptions are ticked by
  default. What the copywriter rewrote survives tonight.
* **Photographs → Ignore** is the default. "Replace" destroys, every night, the
  photographs the shop took itself.
* **A product 1C has just created is → Hidden**. It arrives with no photograph,
  no storefront copy and no category of yours; a person should choose when it
  goes on sale.
* A product 1C marks `Удален` is **disabled, never deleted**. The URL stays
  reachable, old orders stay readable, and a deletion sent by mistake is one
  click to undo.
