# The sale's page and its clock

A promotion can run silently — prices drop and nothing says why — or it can
have a page of its own, an address you put in a newsletter, and a countdown.
That is the **Storefront** tab, plus **Repeats** on the General tab.

## Two switches, not one

**Enabled** decides whether money moves. **Give this sale a page** decides
whether there is something to read. They are deliberately independent:

* most sales need no page at all — the price simply drops;
* a page must be able to outlive its sale. The link in last week's mail lands
  on a page that says *This sale has ended*, never on a 404. Switching the
  sale off does not delete its page either; deleting the promotion does.

The address is made from the name if you leave it empty (a Russian name is
transliterated), and two sales with the same name get two different addresses
rather than one refusing to save.

## What the page shows

* the banner, the name and your description — write the terms here, in your
  own words;
* the countdown, if the sale has a deadline;
* **for a catalog-zone sale, the products it covers** — worked out from the
  same tier conditions that set the price, so the page can never advertise a
  product the cart then charges full price for. A product hidden from this
  visitor stays hidden here too;
* nothing machine-written about the terms. "−50%" without the condition beside
  it reads as a promise, and the conditions in the admin's words ("Category —
  is in 12, 15") are not a sentence for a customer.

A cart-zone sale (money off the order, free shipping, a gift) has a page but no
product list: it applies to an order, not to a set of goods.

The sale also appears on `/sale`, the list of everything running, and in the
sitemap. It is NOT added to the menu automatically — put a link where your shop
wants it.

## Repeats: the deal of the day

The two dates say when the sale **exists**. A window says when it is **open**
inside that:

* no days ticked means every day;
* an end time at or before the start time crosses midnight — `22:00 → 02:00`
  is a night sale, listed on the day it *starts*;
* the same time twice (`00:00 → 00:00`) means the whole day;
* several windows can sit under one sale.

Outside its window the sale is **paused**: the switch is on, the dates are
fine, and the shop is charging full price. The list shows that state in its own
colour and says when the sale comes back, because "running" beside full-price
goods is the report we would otherwise get as a bug.

Times are the shop's own clock. Customers see the same number of minutes left
wherever they are — the timer counts down, it does not print the shop's wall
clock.

## What the countdown counts to

Whichever comes first: the end of the sale, or the close of the current window.
A sale that is paused or over counts to nothing at all — a running timer beside
goods that are not on offer teaches customers that the timer is decoration.

Turn the countdown off if you would rather not show one; the sale itself is
unaffected.

## Badges on product cards

There is no separate "sale badge" here. A card already shows the new price with
the old one struck through, and **Catalog → Labels** is where a badge with its
own text, colour and rule lives (a label with a *discount ≥ 1%* rule marks
everything any sale has touched). A second badge system inside this module
would be a second place to look when a badge is wrong.

The product page is different: under the price it names the sale the price came
from and links to its page, with the countdown beside it.

The one exception is the **countdown**: a product in a running sale that shows a
countdown and has an end gets a small timer among the labels on its card's photo
— days, hours and minutes, seconds on the last day. Of several such sales the
one that ends first is counted. Switch it off with *Countdown on product cards*
in the module's settings.
