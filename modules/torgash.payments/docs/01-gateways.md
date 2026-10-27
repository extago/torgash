# Wiring up a gateway

A **payment method** is what the customer picks at checkout. A **gateway** is
what actually takes the money. A method with no gateway is settled by hand from
the order screen — the right answer for cash on delivery and bank transfers.

You set both up in one place: **Admin → Payment**, open the method, and its
"Taking the money" section is where the gateway, its credentials and its
callback address live. There is no second screen to visit.

A stored key is never shown back to you — the box stays empty and says *saved —
leave blank to keep it*. That is not it being lost: saving the page without
retyping keeps what is stored.

Paste the **callback address** shown there into the gateway's own dashboard, and
leave **test mode** on until you have seen one real payment go through.

## What happens, in order

1. The customer places the order. **It exists before the money does** — that is
   normal, not a bug, and its payment state is "awaiting payment".
2. They are sent to the gateway's page.
3. The gateway calls the shop back, server to server. **That call is the only
   thing that marks the order paid.**
4. The customer's browser comes back too — and that changes nothing. Somebody
   who closes the tab is still paid up, and somebody who types the return URL
   by hand gets nothing.

An invoice has a lifetime, and people do close tabs, so the confirmation page
carries a **Pay** link while the order is unpaid. It is signed, so it works from
an email tomorrow and cannot be guessed from an order number.

## The transaction log

Every message, kept: verified or not, applied or not, with the raw body. When a
bank asks what you received and when, this is the answer — and it is also where
you look when a payment "did not arrive".

Three things it will tell you that nothing else can:

* **Signature did not verify** — the shop was called by something that could not
  prove it was the gateway. Usually a wrong key on this screen.
* **Already seen** — the same message arrived twice. A gateway that gets no
  answer resends; the second copy is recorded and changes nothing.
* **Out of order** — a late message that would have moved the order backwards.
  A "processing" delayed behind the "success" it preceded is exactly this.

## What the buttons on an order do

Whatever the gateway says it can do, and no more: re-ask it, capture a hold,
refund, cancel. A gateway that cannot capture never shows a Capture button.

Anything done from there goes through the same path a callback does, so a refund
made by hand lands in the order exactly like one the gateway announces later.
