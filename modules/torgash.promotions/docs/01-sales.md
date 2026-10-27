# Running a sale

A promotion is one sale event: a switch, a date range, and an ordered list of
**tiers**. A tier is its own set of conditions plus its own discount, tiers are
tried top to bottom, and the **first one that matches wins**.

That ordering is the whole idea. "10% off everything, but 50% off Brand X in
Electronics" is ONE promotion with two tiers — the narrow one first, the
sitewide one last as a fallback — not two promotions guessing priorities
against each other. A tier with no conditions matches everything, which is what
makes it a fallback.

Use the arrows on the left of each tier to move it. Moving a tier is not
cosmetic: the fallback sitting above the narrow discount means every customer
gets the small one.

## Two zones

**Catalog** changes what a product costs. The new price is what the storefront
prints, what the cart takes, and what a search filter sorts by — there is no
separate "sale price" field anywhere.

**Cart** takes money off the order instead: a discount on the total, free
shipping, a gift, or an upgrade to another customer group. Nothing about the
product's own price changes.

The zone is chosen when the promotion is created and cannot be switched
afterwards — the two zones ask about genuinely different things (a product on
its own page has no cart), so their conditions are different vocabularies.

## What a tier can take off

| Kind | Means |
|---|---|
| Percent off | Take N% off. |
| Fixed amount off | Take a sum off. |
| A percent of the original price | The price BECOMES N% of what it was. |
| A fixed price | The price BECOMES this — "everything at 999". |

The last two are stated from the other end, which is often how a sale is
advertised. A target above the price is never a surcharge: "everything down to
999" leaves an item priced 500 at 500.

Cart-zone promotions have three more: **free shipping**, **a gift product**,
and **give a customer group**. The last two are not price lines at all — see
"How the money adds up".

## When it runs

The status on the list is the switch and the calendar answered together:

- **running** — enabled, and today is inside the dates.
- **scheduled** — enabled, but it has not started yet.
- **ended** — enabled, and the end date has passed.
- **off** — the switch is off.

Leave a date empty for "no limit on that side". A promotion can be switched on
and scheduled from the API too (`PATCH /api/v2/admin/promotions/{id}`), which is
how a sale starts at midnight without anyone being awake for it.

## When two promotions want the same thing

That is what **Combine** answers:

- **Replace** — competes with other Replace promotions; the highest-priority
  match wins and the rest are skipped. The default.
- **Stack (add)** — its discount is summed with whatever else applied. "10% +
  10%" reads as 20% off.
- **Stack (multiply)** — applies to the ALREADY discounted price. A real double
  discount.

Whatever they add up to, the customer never ends up being owed money: the total
discount can bring an order to zero and stops there.

## Naming an arbitrary set of products

Sooner or later a sale is "these three hundred, and nothing else" — a list that
matches no category and no characteristic anyone thought to record.

Do not list them one by one. Make a category, **switch it off**, and put them in
it. A disabled category has no page, no menu entry and no line in the sitemap,
and it never appears in a product's breadcrumbs — but membership in it is
ordinary membership, so a tier condition can say "category is in ⟨that one⟩"
exactly as it would for a real one. The products themselves stay as visible as
they ever were: what is switched off is the category, not what is inside it.

The same grouping then answers to more than one module. An automation rule
conditions on the same category; so does a shipping method, and a price-type
markup rule. One hidden category is a set the whole shop can point at, and it
costs no new kind of entity to have.

Category pickers mark such a category "hidden from the storefront" so it is not
mistaken for a real one.

## Limits (cart zone)

**Usage limit** caps how many orders may take it. **Once per customer** ties it
to the buyer: exact for signed-in accounts, best-effort for guests, because a
guest is only recognisable once the order exists.

Both are counted from what orders actually took, which the **Usage log**
(Promotions → Usage log) shows: how much has been given away, on which orders,
under the name each order took it under.
