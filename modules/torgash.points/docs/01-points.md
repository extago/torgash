# Bonus points

Points are a **discount** a customer earns: for every order they are credited
some, and in the basket they spend them to take money off the goods. Only
customers with an account take part — points are a relationship with a person.

## Earning

"N points for every X" (Settings → Bonus points), counted from the **goods after
every discount**, never from delivery. What a gift card paid earns like money:
the card itself, when it was bought, earned nothing. A shop that wants only
money to earn turns *A part paid with a gift card earns nothing* on.

The number is shown to the customer as **waiting** while the order is on its
way, and credited when the order reaches the status the shop chose ("Complete"
by default). An order that moves into a cancelling status takes back what it
earned and returns what was spent on it; a refund takes back its share.

## Rules

Not every product has to earn the same, and not every product has to be payable
with points. Two questions are answered for each product — **what it earns**
and **how much of it points may pay** — each by the first of:

1. the product's own answer, on its *Bonus points* tab;
2. the **rules** (Marketing → Bonus points → Rules), from the top: the first
   one that fits the product and speaks of the question;
3. the module's settings.

A rule is "which goods → what is decided". Which goods: categories (with
everything beneath them), characteristic values (a brand is one), a price from
and up to, particular products, customer groups — all that are filled in must
hold. What is decided:

* **Earns** — the shop's rate multiplied (×0.5, ×2), a percent of the price in
  points (5% of 1 000 is 50), a fixed number per piece, or nothing;
* **Paying with points** — allowed within the shop's share, allowed up to its
  own share, or not allowed.

A rule may speak of one question and leave the other "not decided here": then
the other goes on down the list. "Goods from 50 000 earn half" is one rule
with a price and ×0.5, and no product is touched.

**The order of the list is the law.** A new rule is put above the first one
that would leave it nothing — a subcategory's above its category's — and can be
dragged anywhere. A rule nothing can reach is marked in the list, with a button
that puts it above the one in its way.

A **customer group's multiplier** (same screen) is another matter — who buys,
not what — and multiplies whatever the product and the rules came to.

A product's tab prints what it comes to today and whose word that is: "Buying
it earns 300 points now — rule «Expensive goods»".

An order keeps the terms it was placed by: a rule edited later does not move
the points an order is already waiting for.

## Many products at once

A rule is the first tool for many products alike. When the answers really are
the products' own — brought over from another shop, or set for a hand-picked
list — there are two more:

* **The products list**: tick the products, *Change…*, section *Bonus points* —
  what they earn and whether points pay for them. "Leave as it is" writes
  nothing; "As the rules say" takes the product's own answer back.
* **Import and export** (Exchange): three columns of a product.

  | Column | A cell |
  |---|---|
  | Bonus points: earns (`points_earn`) | empty — as the rules say; `x2` — the shop's rate doubled; `5%` — of the price; `500` — points per piece; `none` |
  | Bonus points: paying with points (`points_pay`) | empty — as the rules say; `yes`; `no`; `30%` — up to its own share |
  | Bonus points: price in points (`points_price`) | a whole number; empty — none |

  An export writes the same spelling, so a file goes through a spreadsheet and
  back. A cell that is not an answer leaves the product as it was, and the
  import says which line. The columns are in an export only when its profile
  ticks them.

## Points as a gift

Two more ways to give points, each inside the module it belongs to:

* **A promotion** (Marketing → Promotions, a cart promotion): the discount
  kind *Give bonus points* and a number. An order that wins the promotion
  earns that many on top of what its goods earn — they wait and are credited
  with the order, and go back if it is cancelled. Rechecking an order's
  promotions gives them once; a guest's order gets none.
* **An automation rule** (Automations): the action *Give bonus points* — a
  number and a reason the customer reads in their history ("Thank you for
  order {{ order.number }}"). Given **at once**, when the rule runs, and once
  per event however many times the rule is retried.

## Spending

In the basket and at checkout: "Bonus points — you have 540 — [ ] Apply". A point
is worth what the settings say; points may pay at most a share of the goods
(half by default) and never the delivery. The share is counted line by line: a
product a rule keeps points away from is not in it, and the basket says so to
the customer. They come off after the coupon and
lower the tax like it, and together with a coupon only if the shop allows it.
The order prints them as a discount line.

## Prices in points

Optional (Settings → Prices in points). A product can have a price in points,
shown under its price as "or 1 500 points"; in the basket the customer presses
the coins button on that line and buys it outright for them.

## Where to see it

* **The customer** — Account → Bonus points: the balance, what is waiting, and
  every movement.
* **The customer's card** in the admin — the balance, the latest movements and a
  correction by hand (a reason is required and the customer sees it).
* **The order** — its payment card: points spent and what it earns, which a
  manager can change.
* Points can have a **life** (months): the oldest are spent first and the rest
  expire by themselves.
