# Kits

Separate products that are cheaper bought together: a phone with a glass and a
case, a fridge with installation, a server with a rail kit. Unlike an option,
a kit's items stay **separate goods** — the warehouse, 1C and a return all deal
in a phone and a glass, never in "a set".

## What the set is

Two kinds, and the choice decides what the basket holds:

* **Several separate items, no discount** — "people also buy these". The button
  puts four ordinary lines in the basket and each of them is afterwards the
  customer's to change or remove. This kind gives no discount at all.
* **One item with the rest inside it** — a real set. The basket holds one line,
  the main product, with the goods of the set inside it: the quantity moves as
  one, removing it removes everything, and **only this kind may give a
  discount**.

The second kind is the default, and the split is not a preference. A discounted
item as a line of its own can be raised on its own — one phone and a hundred
glasses at half price — and no rule can express "one glass per phone" that the
structure does not already express for free.

## A kit and its items

A kit is written either **for one product** (edited on that product, in its Kits
tab) or **for whatever the conditions say** — a category with everything under
it, a characteristic. In the second case the product whose page the card is
drawn on becomes the main one, which is how one row covers a category that keeps
growing.

Each item of the kit is a **slot**:

* **One product** — this glass, that charger.
* **One of a set of products** — a category, a label, a filter. The customer can
  be allowed to **choose another** from that set.

A slot carries how many, the discount, and two ticks: whether the customer may
untick it, and whether they may swap it.

## Fits the model

A slot drawn from a set can be narrowed to what **fits the main product**: name
one or more characteristics, and only the products whose value of every one of
them is the main product's own are offered. A glass for the phone being looked
at, without anybody keeping a list of pairs — and, with two conditions, trousers
of the jacket's colour **and** its fabric.

All of the conditions, never any of them: "the same colour or the same fabric"
describes a wider set than the person assembling an outfit means, and the narrow
reading is the one you can widen by deleting a condition.

A main product that does not answer one of those characteristics is offered
**nothing** for that slot — a glass for an unknown model is the wrong glass.
Two slots drawn from one set never fill with the same product, and a slot never
offers what another slot already holds.

## Show only the whole set

An item with nothing to put in it — no glass fits this model, the product it
named has been disabled — is normally left out, and the rest of the set is
offered anyway. That is right for "people also buy these" and wrong for a set
that was designed as one: a card headed "Everything for the kitchen" showing two
of its four items is an offer you never made.

Tick **Show only the whole set** and the card is not drawn at all until every
item can be filled.

This is a different question from a **required item**. "Required" is about the
customer: they may not untick it. This is about you: do not advertise a set that
cannot be assembled today. A case may be genuinely optional and the card still
not worth showing when there is no case to put in it.

Because the answer to a set that cannot be assembled is now silence, System →
Invariants keeps two statements about kits: *every item of every kit has goods
behind it*, and *every kit written for a product is actually shown on it*. That
is where you find out that a kit stopped appearing in March.

## The discount

Per slot, and only for a set bought as **one item**: a percentage off, an amount
off, or "the price becomes". It is expressed as the **price of the item inside
the set** — the glass costs 50 in the set and 100 out of it — so 1C gets a price
per line that adds up, and a return of the case knows what the case actually
cost.

Nothing is frozen onto the line but which kit and slot it was bought in, so:

* a kit whose dates have run out charges full price for what is already in the
  basket;
* an item withdrawn from the set goes back to its own price;
* editing the kit changes what is owed the next time the basket is priced.

**One line carries one discount.** Goods bought inside a kit are not discounted
again by cart promotions or a coupon, so set the kit price at least as good as
whatever promotion those goods fall under — the kit form says so on screen.

## Where the card is shown

Three places, and they are three **widths**:

* **Above the tabs, the full width of the page** — the one to reach for. A kit
  is a row of products with the totals beside them, and that needs the width.
* **Under the buy button** — a narrow column, about 400 pixels.
* **A section of the product page** — in the strip beside Description and
  Characteristics.

The goods themselves stand either in a grid that wraps or in one line that
scrolls sideways; that is the kit's own choice too.

## Kits in a spreadsheet

Admin → Import/Export takes two profiles for this: **Kit** and **Kit slot**.

Every kit and every slot has a **Code** — a short stable name that identifies
it in the file. It is given automatically; type your own if you would rather,
and it is kept even when you rename the kit.

Nothing refers to a row by its number: a kit names its main product by **SKU**,
a slot names its kit by **Code** and its compatibility characteristic by the
characteristic's own code. Import the kits before the slots — a slot whose kit
is not there yet has nothing to belong to — and note that a reference the shop
cannot resolve leaves that row as it was rather than moving it somewhere wrong.

A slot's discount travels as you typed it, in minor units, with its currency
beside it; the shop's books are worked out on import.
