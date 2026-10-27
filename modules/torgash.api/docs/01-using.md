# Using the API

Every call carries a token and says which resource it means. There is one URL
shape for everything:

    GET    /api/v2/admin/{resource}
    GET    /api/v2/admin/{resource}/{key}
    PATCH  /api/v2/admin/{resource}/{key}
    POST   /api/v2/admin/{resource}/{key}/actions/{action}

**Admin → API reference** is the live list: every endpoint that exists on this
shop, its filters, what it answers with, and a Send button that fires the call
from the page. The same thing machine-readable is at

    GET /api/v2/openapi.json

— OpenAPI 3.1, public (a client generating a stub does not have a token yet),
and generated from what the modules declared rather than kept beside it. Feed it
to Postman, to a code generator, or to whoever is writing the other end.

## Authentication

Issue a token in the admin panel and send it on every call:

    Authorization: Bearer tg_...

The token belongs to the account that issued it and can never do more than that
account may. Narrowing it to `read` is what keeps a price feed from being able
to change an order. The plaintext is shown once, at issue: the database keeps
only a hash, so a stolen dump cannot be replayed.

## Addressing a row

By our id, or by any key the resource declares — for a product that is
`external_id`, `sku` and `slug`. These are the same product:

    /api/v2/admin/products/4
    /api/v2/admin/products/ST-2002
    /api/v2/admin/products/1c-42

That is the point of `external_id`: an exchange stores its own id and never has
to keep ours.

## Lists

    ?page=2&itemsPerPage=50
    ?order[sku]=desc
    ?updated_after=2026-09-01T00:00:00Z
    ?status=active

Asking for more items than the shop allows gets the ceiling, not an error.
`updated_after` is the filter an exchange should be built on: pulling the whole
catalogue every night is how an integration ends up switched off.

The answer is always the same shape:

    {"data": [...], "meta": {"page": 1, "itemsPerPage": 30, "total": 412, "pages": 14}}

## Writing

A `PATCH` changes what it names and nothing else — sending a stock level does
not risk the name, the price or the description. Each resource decides what an
exchange may write; for products that is stock, status, prices, sku and
`external_id`, and not the slug, the categories or the photos.

Prices are sent in major units, and may name their own currency:

    PATCH /api/v2/admin/products/1c-42   {"price": 42000, "price_currency": "UAH"}

They are stored exactly as the admin form would store them: the typed number
kept, the shop's own price derived from it (see the catalogue's docs on pricing
in another currency).

## Changing an order

Not by writing the status column — by asking for the transition:

    POST /api/v2/admin/orders/TG-000123/actions/status   {"status": "complete"}

This runs the same code the admin panel's own select runs: the stock moves, the
change is logged, the customer's email goes out. A `PATCH` setting `status`
would have looked like it worked and done none of it.

## Storefront and language

    X-Storefront: main          (id or code; the default storefront otherwise)
    Accept-Language: ru

Prices, currencies and translations follow those two headers.

## The shop half

`/api/v2/shop/...` is public, exactly as the storefront's own pages are:

    GET   /api/v2/shop/products?q=ноутбук&category=laptops
    GET   /api/v2/shop/products/ST-2002
    GET   /api/v2/shop/categories

It answers with what a visitor may see — listed products, prices through the
same display filter the page uses, no cost prices — and it cannot write:
only GET is routed there.

### The cart

    POST   /api/v2/shop/carts                     → { "token": "..." }
    GET    /api/v2/shop/carts/{token}
    PATCH  /api/v2/shop/carts/{token}/items       { "product": "ST-2002", "quantity": 2 }
    DELETE /api/v2/shop/carts/{token}

The token IS the authorisation: keep it, and it is your cart. An unknown token
is a 404 — the API does not say whether a cart exists to somebody who was not
given its address.

One call sets a quantity, and `0` removes the line: "how many of this do you
want" is one question. The product is named by id, sku or external id, like
everywhere else.

Every amount is in minor units of the shop's books; `currency` in the answer
says what the visitor's prices are displayed in.

### Checking out

A browser answers every checkout question on one page and submits once. A client
cannot: it has to give an address before it can be told what delivery costs, and
choose a delivery before it knows the total. So checkout is a conversation with
the cart — each answer is kept on it, and the client can stop and come back.

    GET    /api/v2/shop/carts/{token}/checkout
    PATCH  /api/v2/shop/carts/{token}/checkout    { "city": "Київ", "place_id": 703448 }
    POST   /api/v2/shop/carts/{token}/checkout/complete

A `PATCH` is MERGED onto the answers already given, so a step that picks a
delivery does not unanswer the address. Sending a field as `null` erases it.
Nothing is validated against what is on offer yet: an address typed one field at
a time is briefly nonsense, and refusing it would make a form impossible to
build.

The `GET` answers with the state of the checkout for the answers so far:

    {
      "answers":  { … what has been given … },
      "delivery": [ { "id": "3", "label": "Courier delivery", "cost": 5000,
                      "delivery_time": "1–2 days", "needs_address": true,
                      "needs_fields": false, "meta": {} } ],
      "payment":  [ { "id": 2, "label": "Card online", "charged_in": "UAH" } ],
      "currency": "USD", "subtotal": 4000, "discount": 0
    }

The delivery options are priced for the address given so far — that is why the
address is the first question. A `cost` of `null` means a carrier answered but
named no price: the shop will quote it by hand, and a client must not print
"free". `needs_fields` says the option wants something more from the customer (a
branch to collect from); `charged_in` is the currency a payment method will ask
the bank for when it cannot take the one being shopped in.

`complete` places the order. Everything is re-derived from the stored answers
and nothing is trusted from the client, so a delivery option with no rate in the
winning zone cannot be bought however the cart was PATCHed. An incomplete
checkout comes back as a `422` naming the fields:

    { "message": "The checkout is not complete.",
      "errors": { "email": ["The email field is required."] } }

and a placed one as a `201`:

    { "data": { "order": { "number": "TG-000123", "status": "awaiting_payment",
                           "total": 4500, "currency": "UAH",
                           "payment": { "state": "unpaid", "currency": "UAH",
                                        "amount": 4500, "url": "https://…" } } } }

`payment.url` is where a browser would have been redirected — a gateway's own
page. Open it in a web view and come back; there is no way to take a card
without one. It is `null` when the method takes no money online.

The order is placed by the same object the storefront's own form goes through,
which is why stock, statuses, currencies, taxes, automations and the
confirmation email all behave identically here. The cart is emptied, not
deleted: its token still points at it.
