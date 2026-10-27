# Setting up LiqPay

1. Get the keys: **liqpay.ua** → your shop → **API**. There are two pairs —
   the live one and the **sandbox** one (its public key starts with
   `sandbox_`). Start with the sandbox.
2. Admin → **Payment** → open the method customers see → in **Taking money**
   choose **LiqPay**, paste the public and the private key, and switch **test
   mode** on while you use the sandbox keys. Save. The private key box stays
   empty afterwards and says *saved* — a stored secret is never shown again.
3. Place an order and pay it. In the sandbox any card number LiqPay lists in
   its documentation pays, and nothing is taken.

## Test mode is a real switch here

For monobank the test switch is only a label — the token itself decides. For
LiqPay it is sent with every payment (`sandbox: 1`), so it matters which way
it is:

* sandbox key, test mode **off** — every payment is refused;
* live key, test mode **on** — LiqPay takes nothing and still reports success,
  and the order is marked paid. This is the dangerous one.

The payment method's form says so when the two disagree.

## The callback has to reach you

Every payment page carries a `server_url` built from `APP_URL`. LiqPay calls it
server to server, and **only that call marks the order paid**. It names the
gateway and nothing else: the order is read from the SIGNED data, never from
the address, and the private key is never in it.

On a developer machine (`torgash.local`) LiqPay cannot reach you. The payment
still goes through on LiqPay's side; the order stays unpaid until somebody
presses **Refresh** on it.

## What the buttons on an order do

* **Refresh** — ask LiqPay what it thinks now.
* **Capture** — take held money (with *Authorize first* on), all of it or less.
* **Refund** — give money back, all of it or part. A hold is released by the
  same button: LiqPay does both with one call.

There is no **Cancel**: a LiqPay page is not an invoice, there is nothing to
remove, and it expires by itself.

## Worth knowing

* **Each attempt is a new LiqPay order.** LiqPay refuses to take a second
  payment under an `order_id` it has seen, so a customer paying again after a
  failure gets `123-1727000000`, `123-1727003600`… The number before the dash
  is the order.
* **`wait_accept` counts as paid.** The customer's money has been taken; LiqPay
  is only still checking your shop.
* **Currencies**: hryvnia, dollars and euro. An order to be charged in anything
  else is refused with that sentence before the customer leaves your site.
