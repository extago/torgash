# Setting up monobank acquiring

1. Get a token. In production it comes from **web.monobank.ua**; for trying
   things out, the **personal-account test token** works against the same
   endpoints with play money.
2. Admin → **Payment** → open the payment method customers will choose → in its
   **Taking the money** section pick **monobank Acquiring**, paste the token,
   save. The box stays empty afterwards and says *saved* — that is by design,
   a credential is never shown back.
3. Run the check:

       php artisan monobank:check

   It asks the bank three questions in the order they fail: is the token
   accepted, can the signing key be fetched (without it no callback will ever
   verify), and what callback address is being sent with every invoice. It
   creates a real 1 ₴ invoice and prints its link.

## The callback has to reach you

Every invoice carries a `webHookUrl` built from `APP_URL`. The bank calls it
server to server, and **that call is the only thing that marks an order paid**.

On a development machine (`torgash.local`, `localhost`) it cannot: the bank is
outside. Payments will still go through on monobank's side, and the order will
sit unpaid until somebody presses **Refresh** on it. To see the whole flow
locally, put a tunnel in front of the shop and set `APP_URL` to the tunnel's
address.

## Hold, or straight debit

With **Authorize first** on, the invoice is a two-phase payment: the customer's
card is held, nothing is taken, and the order arrives as *Authorized* rather
than *Paid*. A manager decides, then presses **Capture** on the order — for the
whole amount or less. Use it when you cannot promise the goods are in stock.

Without it, the money is taken at once.

## What the order screen can do

* **Refresh** — ask the bank what it currently thinks. This is also the answer
  when a callback did not arrive.
* **Capture** — take a held amount.
* **Refund** — give money back, all of it or part. Each refund goes with its own
  reference, so a second partial refund is not mistaken for a repeat of the
  first.
* **Cancel** — remove an invoice nobody paid.

Everything here goes through the same path a callback does, so a refund made by
hand lands in the order exactly like one the bank announces later.

## Two things worth knowing

**A partial refund does not change the status.** monobank keeps it as `success`
and lowers `finalAmount`. The gateway reads that, so the order becomes
*Partially refunded* with the remaining amount, rather than staying fully paid.

**The basket is only sent when it adds up.** The bank requires the lines to sum
to the amount charged when fiscalisation is on. Goods plus delivery does that;
an order with a discount does not, without spreading the discount across lines.
Rather than guess at that arithmetic, the gateway sends no basket in that case —
which is fine unless you are fiscalising, in which case tell us and we will do
the spreading properly.
