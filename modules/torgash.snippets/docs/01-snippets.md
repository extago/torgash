# Code snippets

A counter, a pixel, a chat widget, a verification tag from a search engine — a
piece of code somebody gave you, a place on the page for it, and the rules for
when it should be there.

The code is printed **exactly as written**, script tags and all. Nothing tidies
it, reformats it or strips anything out of it: whatever your provider handed you
is what the browser gets.

## Where on the page

Three places, the ones a provider's instructions name:

* **Page head** — where nearly every counter belongs.
* **Start of the page body** — the `<noscript>` pixel that usually comes with
  one.
* **End of the page** — chat widgets and anything that should not hold up
  drawing the page.

Nothing ever appears in the admin panel. That is not a setting; the admin simply
has no such slots.

## When it is shown

With no conditions, a snippet is on every storefront page. The conditions are
the shop's own rule engine — the same one the content blocks use:

* **Page** — a route name. `checkout.confirmation` is the thank-you page,
  `catalog.product` any product page. *Route starts with* catches a page in
  every language at once.
* **Language**, **Storefront**, **Visitor** (signed in or not), **Customer
  group**.

So "the conversion pixel only on the thank-you page" is one condition, and "the
counter everywhere except the admin panel" needs none at all.

A condition belonging to a module that has been switched off **hides** the
snippet rather than being ignored — for a tracking tag that is the safe
direction.

## Reporting the order

On an order's confirmation page the code can ask about that order:

```html
<script>
  gtag('event', 'purchase', {
    transaction_id: '{order_number}',
    value: {order_total},
    currency: '{order_currency}',
    items: {order_items_json}
  });
</script>
```

* `{order_number}` — as the customer sees it.
* `{order_total}` — a plain number, no symbol and no spaces, in the currency the
  customer was actually charged in.
* `{order_currency}` — the ISO code of that currency.
* `{order_items_json}` — the lines as a JSON array of
  `{sku, name, price, quantity}`.

`{storefront}` and `{currency}` work on any page. Everywhere but the
confirmation page the order tokens are empty strings, so the same snippet left
on every page does not break.

Anything in braces the shop does not recognise is left exactly as typed, which
is why ordinary JavaScript — `${total}`, `[{ id: 1 }]`, `function () { … }` —
is safe to paste.

## Who may do this

Editing snippets needs its own permission, **Manage code snippets**, and it is
deliberately not part of managing content: this is the one screen in the shop
where what you type is executed in every visitor's browser. Every change is
recorded in the audit trail (*Guard → Audit*), including what the code was
before.

## What this is not for

Text, headings and pictures belong in **Blocks**, where the person editing them
cannot break the page. Consent management for counters — asking a visitor before
the tags load — is not here yet.
