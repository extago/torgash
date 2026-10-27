# Setting up Stripe

1. **The secret key.** dashboard.stripe.com → Developers → **API keys**. Use
   `sk_test_…` until you have seen a payment go through.
2. **The webhook.** Workbench → **Webhooks** → *Create an event destination* →
   your account, **API version `2024-06-20`**, and these events:

   * `checkout.session.completed`
   * `checkout.session.async_payment_succeeded`
   * `checkout.session.async_payment_failed`
   * `checkout.session.expired`
   * `payment_intent.succeeded`
   * `payment_intent.amount_capturable_updated`
   * `payment_intent.payment_failed`
   * `payment_intent.canceled`
   * `charge.refunded`

   Endpoint URL: `https://<your shop>/payment/stripe/callback`. Stripe then
   shows the **signing secret** (`whsec_…`).
3. Admin → **Payment** → open the method customers see → in **Taking money**
   choose **Stripe**, paste both secrets, switch **test mode** on while the key
   is `sk_test_`. Save.

The API version matters: the engine pins its own requests to `2024-06-20`, and
the webhook's events are shaped by the version chosen for the ENDPOINT. The
same version on both ends means both describe the same objects.

## How it works

The customer is sent to a Stripe-hosted **Checkout** page. Only the webhook
marks the order paid — it is verified against the signing secret (HMAC-SHA256
over the timestamp and the raw body) and refused when it is more than five
minutes old, so a captured message cannot be replayed later. The order is
named inside the signed event, never in the address.

On a developer machine, use the Stripe CLI (`stripe listen --forward-to
https://torgash.local/payment/stripe/callback`); its `whsec_` goes into the
form while you test.

## What the buttons on an order do

* **Refresh** — ask Stripe what it thinks now.
* **Capture** — take held money (with *Authorize first* on), all or less.
* **Refund** — give money back, all or part. A double click is one refund:
  every refund carries an idempotency key.
* **Release the hold** — let held money go without taking it. At Stripe this is
  a different call from a refund, and it matters: refunding money that never
  left the card costs the shop the fees twice.
* **Cancel** — close an unpaid checkout page.

## Worth knowing

* **A declined card does not fail the order.** The customer is still on
  Stripe's page and may try another card; the log records the decline.
* **Currencies**: any Stripe takes, except those it does not count in
  hundredths (yen, won, dinars…). Those are refused with a sentence before the
  customer leaves your site — sending the shop's hundredths as yen would charge
  a hundred times too much.
* **The page holds one line, the order.** Its lines are in the shop's books; the
  charge may be in another currency.
