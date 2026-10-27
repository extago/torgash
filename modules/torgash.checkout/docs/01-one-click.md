# Buying in one click

A "Buy in one click" button on the product page: the customer leaves a phone
number, the shop places a **real order**, and the manager rings back to agree
the rest.

Real is the whole point. Many engines make one click a "call request" with an order
attached, and because a call request is not an order it has to invent an email
address for the buyer to place one at all. Here it goes through the same
`PlaceOrder` as the checkout form and the API, so stock, statuses, currencies,
taxes and the order log behave identically — and the email column stays empty
rather than being filled with something made up. No address means no
confirmation email; the shop is phoning them anyway.

## Setting it up takes three ticks

1. Switch **Buying in one click** on in this module's settings.
2. Open the **delivery method** such an order should be placed with and tick
   "Use this one for orders placed in one click".
3. Do the same on the **payment method**.

Both are needed. An order is placed with methods that really exist, so until the
shop says which ones, the button does not appear at all.

The methods to nominate are the ones that mean "we will agree it when we call
you" — a delivery method priced by hand, and a payment method with no gateway
behind it. The manager changes them on the order screen after the call, which is
exactly what the delivery re-quote and the payment picker there are for.

## The one thing that can stop it

The nominated delivery method needs a rate that covers a customer whose address
nobody knows yet — in practice, a rate in the catch-all zone. Without one the
shop genuinely cannot promise delivery, and one click says so rather than
placing an order it cannot fulfil.

## What the order looks like

It arrives in the shop's default status with the product, the phone number and
the two nominated methods, and with `customer_ref` derived from the phone — so
a guest who buys twice is one counterparty in 1C and in any CRM, without an
account ever being created for them.

The form is guarded like every other public one (honeypot, form age, rate limit
per address and per phone). It matters more here than anywhere else: this
endpoint creates orders, and an order that has already moved stock is an
expensive piece of spam.
