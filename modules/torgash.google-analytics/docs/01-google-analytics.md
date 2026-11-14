# Google Analytics

The module puts Google's tag on every page of the storefront — Google
Analytics 4 by its measurement ID, or a Google Tag Manager container — tells
it what happens in the shop in GA4's own e-commerce events, and keeps the
visitor's client ID on the order, where a CRM finds it.

## Switching it on

1. In Google Analytics: Admin → Data streams → the site's web stream, and
   copy its **Measurement ID**, `G-XXXXXXXXXX`. For Tag Manager, copy the
   container's ID instead, `GTM-XXXXXXX`, shown beside its name.
2. Here: **Measurement ID or container**, then Save.

If Google's tag is already in **Code snippets**, delete it there: two copies
count every visit twice.

## Consent

Where the **Personal data** module asks visitors before counting them,
**Before the visitor agrees** chooses one of three ways:

- **Wait** (the default): nothing of Google's loads until the visitor allows
  "Statistics". Once they do, the tag starts on the same page, and the visit
  that agreed is counted too.
- **Load at once, under Consent Mode**: the tag loads on every visit, and
  Consent Mode — whose defaults the Personal data module sets before any tag
  — keeps it from storing cookies until the visitor agrees. Meanwhile Google
  gets signals without cookies and models the visits and conversions it
  cannot see. It needs "Google Consent Mode v2" on in Personal data; without
  it the tag waits, whatever is chosen here. Whether such signals may be sent
  before consent where the shop sells is the shop's decision.
- **Do not wait — count everyone**: the tag loads on every visit, as code
  in the "Necessary" category of Code snippets does. While "Google Consent
  Mode v2" is on in Personal data, Google still keeps its cookies until the
  visitor agrees; with it switched off there, everyone is counted in full.
  Whether the law where the shop sells allows that is the shop's decision.

Without the Personal data module, or where the shop's law does not ask, the
tag starts at once. A manager looking at the shop as a customer is not
counted: that visit is the shop's own.

## E-commerce

| Event | Sent when |
|---|---|
| `view_item` | a product's page is opened |
| `add_to_cart` | a product is put into the basket, or its quantity raised |
| `remove_from_cart` | it is taken out, or its quantity lowered |
| `add_to_wishlist` | it is added to the wishlist |
| `begin_checkout` | the checkout page is opened |
| `purchase` | an order is placed; `transaction_id` is the order's number |

A purchase is sent once per order: a reload of the "thank you" page sends
nothing again. Its `value` is what the goods came to after the order's
discounts, and delivery goes in `shipping`, as Google asks.

Prices go in the shop's accounting currency; Google Analytics converts them
into the property's currency itself.

**A product's item ID** is its code (SKU) or its number in the shop. For
Google Ads remarketing choose the one the product feed gives as `id`, so the
two are matched.

## Leads

Sending one of the forms ticked in **Forms that are a lead** is the
`generate_lead` event, with the form's kind in `lead_source`: `callback`, a
question about a product, one of the shop's own forms. In Google Analytics
mark `generate_lead` as a key event to count leads as conversions;
`purchase` is one already.

## Through Google Tag Manager

With a `GTM-…` container the module loads the container and puts the same
events into the data layer in GA4's e-commerce format — `event` and
`ecommerce` — with `lead_source` beside `generate_lead`. In Tag Manager set
up a Google tag with the measurement ID, and a "Google Analytics: GA4
Event" tag with "Send Ecommerce data" from the Data Layer, fired on a custom
event matching
`view_item|add_to_cart|remove_from_cart|add_to_wishlist|begin_checkout|purchase`
as a regular expression, with the event name taken from the event.

## The client ID on orders

The client ID is Google Analytics' number for the visitor's browser. To have
it on the order, tick **Google Analytics client ID** in the checkout
settings, under what an order writes down about the buyer's browser (and in
the enquiries settings, for requests and call backs). It is then

- on the order's card, beside the browser and the address;
- in the API: `client.ga_client_id` of an order;
- in the export of orders: the column "Google Analytics client ID".

That is what a CRM sends a sale or a refusal back to Google Analytics with.
It is written only for an order placed from the storefront, and only where
the tag was allowed to store its cookie. An order a manager creates, or
places while looking as the customer, has none. It is erased with the rest
of what the order knows about the browser, on a customer's request or after
the shop's retention period.

## Pages opened without a reload

"Show more" under a list puts the next page's address into the address bar
without loading a new page. Google's enhanced measurement counts such an
address as a page view by itself ("Page changes based on browser history
events", on by default), so there is nothing to set here.
