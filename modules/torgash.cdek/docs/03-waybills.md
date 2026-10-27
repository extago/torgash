# Waybills

An order sent with CDEK gets a **CDEK waybill** card on its order screen
(right column). Staff need the **Create and print CDEK waybills** permission —
separate from setting the integration up.

## Before the first one

In the module settings, group **Waybills**:

- **CDEK office you drop parcels at** — its code (MSK2 …). Needed for methods
  where *you hand parcels in at an office*.
- **Sender: company / contact / phone** — printed on the waybill.
- **Pickup address** — needed for methods where *CDEK's courier collects
  them*; the city is the sender city.

## Making one

The card starts with the parcel: weight from the goods' own weights, box from
their sizes (or the module's default parcel), editable. **Collect payment on
delivery** is ticked when something is still owed and the order is not
waiting for an online payment; what is collected is exactly what is still
owed — a discount, a gift card or a part paid already is not charged again.
The goods are declared at their full price either way (CDEK insures them).

Everything else comes from the order as it was placed: the tariff of the point
the customer chose (a locker is sent by a different code from an office), the
point's code or the courier's address, the recipient's name and phone.

## After

CDEK registers an order a little while after it accepts it: the card says
**being registered…** until the CDEK number arrives. Then:

- **Print the waybill** — the receipt, two copies (one stays with you);
- **Print the label** — the A6 barcode sticker for the box;
- **Refresh** — ask CDEK for the status now; the scheduler asks every half hour
  anyway, until the parcel is delivered or returned;
- **Cancel the waybill** — only while CDEK does not have the parcel yet. A new
  one can then be made; it gets the order number with `-2`, because CDEK will
  not take one number twice.

If CDEK accepts a waybill and then refuses it, the card says why, and the
form is back for the next attempt.

The customer sees the CDEK number, with a link to CDEK's tracking page, on
their own order page.

## The test environment

CDEK's public test account **cannot collect on delivery**: such a waybill is
accepted and never numbered, so it cannot be printed. Untick it to try the
whole way through. Its own city is Novosibirsk.

Another module can act on a status change through the hook
`cdek.waybill.status_changed` (`['waybill' => …, 'from' => old code]`).
