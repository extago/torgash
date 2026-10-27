# Setting up WayForPay

1. In WayForPay's cabinet → **Store settings**, copy the **merchant login**
   and the **secret key**. To try it first, WayForPay publishes a test
   merchant (`test_merch_n1`) and its key in their documentation.
2. Admin → **Payment** → open the method customers see → in **Taking money**
   choose **WayForPay**, paste the login and the key. **Shop domain** must be
   the domain registered with WayForPay; leave it empty if it is this site's.
   Save. The key box stays empty afterwards and says *saved*.
3. Place an order and pay it.

## How it works

Each order gets an **invoice** (WayForPay's `CREATE_INVOICE`), and the customer
is sent to its page. When the payment settles, WayForPay calls the shop back
server to server — **only that call marks the order paid** — and the shop
answers with a signed `accept`. Without that answer WayForPay keeps resending
the same callback for days; the engine sends it for you.

On a developer machine (`torgash.local`) WayForPay cannot reach you: the
payment goes through on their side and the order stays unpaid until somebody
presses **Refresh** on it.

## What the buttons on an order do

* **Refresh** — ask WayForPay what it thinks now (`CHECK_STATUS`).
* **Refund** — give money back, all of it or part.

There is no hold and no **Capture**: WayForPay's invoice cannot hold money.
There is no **Cancel** either — an unpaid invoice expires by itself.

## Worth knowing

* **The invoice holds one line — the order.** WayForPay checks the product list
  against the amount, and the order's own lines are in the shop's currency
  while the invoice may be in another: a list that misses by a kopeck of
  rounding is a refused invoice.
* **Each attempt is a new invoice**: `123-1727000000`, `123-1727003600`… The
  number before the dash is the order.
* **RefundInProcessing** means WayForPay could not make the refund yet (your
  balance there is too low). The order stays paid; the payment log says why.
* **Currencies**: hryvnia, dollars and euro.
