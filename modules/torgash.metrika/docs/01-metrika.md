# Yandex Metrica

The module puts the Metrica counter on every page of the storefront by its
number, tells it what happens in the shop — the products viewed, the basket,
the orders, the goals — and keeps the visitor's ClientID on the order, where
a CRM finds it.

## Switching it on

1. In Metrica, copy the counter's number — it stands beside the counter's
   name in the list of counters, and in its code as `ym(12345678, "init", …)`.
2. Here: **Counter number**, then Save.

The boxes below it choose what the counter collects; all of them are on, as
Metrica's own code has them.

If the counter is already in **Code snippets**, delete it there: two copies
count every visit twice.

## Consent

Where the **Personal data** module asks visitors before counting them, the
counter waits for "Statistics" and starts on the same page the moment the
visitor agrees — the visit that agreed is counted too. Without that module,
or where the shop's law does not ask, it starts at once.

To count everyone, as a counter in Code snippets under "Necessary" does,
untick **Wait for the visitor's consent**. Whether the law where the shop
sells allows that is the shop's decision.

A manager looking at the shop as a customer is not counted: that visit is
the shop's own.

## E-commerce

What the counter is told:

- on a product's page — which product was viewed;
- in the basket — what was put in and taken out, how many and at what price;
- once an order is placed — its number, its sum (delivery included), the
  coupon and its lines. Once per order: a reload of the "thank you" page
  counts nothing again.

Switch E-commerce on in the counter's settings in Metrica as well, with the
container named `dataLayerYM` (not the usual `dataLayer` — that one is
Google's).

**Currency.** Metrica does not convert: a sum in a currency other than the
counter's comes out as zero. Choose the counter's currency in **Currency of
the counter** — the one set in the counter's settings in Metrica — and every
sum is converted from the shop's accounting currency at the shop's rate.

## Goals

| Identifier | Reached when |
|---|---|
| `basket` | a product is put into the basket |
| `order` | an order is placed; its sum is the goal's revenue |
| `wishlist` | a product is added to the wishlist |
| `buy_with_one_click_form_opened` | the "Buy in one click" form is opened |
| `call_request` | a call back is requested |

In Metrica: Goals → Add a goal → "JavaScript event", with the identifier from
the table. A goal unticked here is not reached at all.

## The ClientID on orders

The ClientID is Metrica's number for the visitor's browser. To have it on the
order, tick **Yandex Metrica ClientID** in the checkout settings, under what an
order writes down about the buyer's browser (and in the enquiries settings,
for requests and call backs). It is then

- on the order's card, beside the browser and the address;
- in the API: `client.metrika_client_id` of an order;
- in the export of orders: the column "Yandex Metrica ClientID".

It is written only for an order placed from the storefront, and only where
the counter was allowed to run — no counter, no ClientID. An order a manager
creates, or places while looking as the customer, has none. It is erased with
the rest of what the order knows about the browser, on a customer's request
or after the shop's retention period.

## The click map and Webvisor

Metrica shows the shop's pages inside its own interface. The module lets
Metrica's addresses show the storefront in a frame — never the admin — so
there is nothing to set up. If the web server adds an `X-Frame-Options`
header of its own, the maps stay empty: remove that header from the server's
settings.

## Pages opened without a reload

"Show more" under a list puts the next page's address into the address bar
without loading a new page; **Pages opened without a reload** counts each
such address as a page view.
