# Payment surcharge and who a method is for

A payment method can cost the customer something extra: a 10% surcharge on
an invoice for a company, a 2.5% fee for paying by card on delivery. It can
also be offered only to some customers or with some deliveries. Both are
set on the method itself: **Admin → Payment**, open the method.

## The surcharge

The **Payment surcharge** card:

- **Percent** and **fixed amount**. Use either one or both: 2.5% + 10.
- **Calculated from**:
  - **Goods after discounts**: the goods once every discount is off (a
    coupon, bonus points, cart promotions).
  - **Goods after discounts + delivery**: the delivery the shop charges.

  Taxes never count towards it.
- **Title**: what the line is called in the bill, for example "Invoice
  fee". Empty means "Payment surcharge".

There is no negative surcharge. A discount for paying a certain way is a
cart promotion with the "Payment method" condition. Make it unpublished, and
it will have no page of its own.

**What comes first.** Cart promotions are worked out first, and their
thresholds look at the goods, never at the surcharge. Then the surcharge is
taken from what is left. Then comes its tax.

### Where the customer sees it

There are two separate settings, because a shop often wants one without the
other:

- **In the list of payment methods**: the percent ("+10%"), the amount
  ("+100"), or nothing.
- **In the order total**: a line of its own, or no line at all. With no line,
  the surcharge is still part of the total. It is just not named in the
  checkout, on the order page or on the invoice.

### Taxes on the surcharge

With the taxes module on, the method has a **Taxes on the surcharge** card.
The taxes ticked there are charged on the surcharge as well, and they appear
in the same tax line as the goods' taxes. With nothing ticked, the
surcharge carries no tax.

### On a placed order

The order keeps the terms it was placed with. Editing the goods works the
surcharge out again by those same terms, while editing the method in the
settings leaves older orders alone. Changing the order's payment method in
the admin takes the new method's terms.

## Who a method is offered to

The **Who it is offered to** card:

- **Buyer**: anybody, private persons only, or companies only. This needs
  orders from companies switched on in the checkout settings. A typical
  setup is "Invoice for companies", with a surcharge, for companies only.
- **Customer groups**: visitors without an account, any customer with an
  account, or particular groups. Nothing ticked means everybody.
- **Only with these delivery methods**: for example "Cash to our courier".
  Nothing ticked means any delivery.

At the checkout the list of payment methods changes as the customer picks a
delivery or ticks "Company". If the method they had chosen is no longer
offered, the first one that is gets ticked instead, and the total follows
it.

A **manager** creating an order in the admin sees every method. The limited
ones carry a note, and the order is never refused: the order screen only
warns. A one-click order uses the method the shop named for it, whatever
the limits say.

## A delivery cost for information

A shipping method has a **Delivery cost** setting. **Admin → Shipping**,
open the method:

- **Charged**: the cost is in the order total. This is how every method
  works by default.
- **For information**: the customer pays the carrier on receipt (Nova
  Poshta "from 80"). The order total, the shop's statistics, the online
  payment, 1C and the profit report all leave it out. The customer sees
  "≈ 80, paid to the carrier on receipt" instead of "Free". A free rate
  stays free, because then the shop pays.

## Where else it goes

- **The bank's receipt**: the surcharge goes as a line of its own or spread
  over the goods. This is set on the gateway, see the payment gateways'
  guide.
- **1C**: the surcharge is a service line. Its 1C nomenclature id goes in
  **Exchange with 1C → Orders**. A delivery for information goes as a note
  with its estimate, not as a line.
- **Order export**: the columns "Payment surcharge", "Delivery paid to the
  carrier" and "Delivery estimate".
- **Profit of the order**: the surcharge is the shop's income. A delivery
  for information is not.
