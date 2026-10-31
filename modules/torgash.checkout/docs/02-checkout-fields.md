# Checkout fields of your own

The checkout asks the usual: who the order is for, how to reach them, where
to deliver, how to pay. Anything else your shop needs to know is a field of
your own — **Sales → Checkout fields**.

## What a field can be

| Type | Looks like |
|---|---|
| Line of text | one line |
| Several lines | a box of a few lines |
| Drop-down list | a list to pick from |
| One of several | the answers as buttons in a row, one tap each |
| Tick box | a box with your words beside it |
| Date | a calendar |

Each field has a name, a description under it, a hint inside the box, and
can be **required**.

## Where and of whom

- **Where on the page** — under the recipient, under the delivery, or above
  the order notes.
- **Asked of** — everyone, private persons only, or companies only (when
  the shop takes orders from companies — the checkout settings).
- **Only for these deliveries** — tick the delivery methods it belongs to;
  none ticked means whatever the delivery.

A field is asked only where it applies, and **required only there**: a
courier's question marked required does not stop an order collected from a
branch. Change the delivery on the page and the field appears or goes.

## Where the answers go

Onto the order, as "Name: answer" — never into the customer's notes, which
stay their own words:

- the order's card in the admin;
- the customer's order page;
- the letters — `{order_details}` prints them, `{order_fields}` prints only
  them;
- automations — `{{ order.fields.CODE }}` for one answer,
  `{{ order.fields_text }}` for all of them;
- the order export (the "Checkout fields" column) and the API (`fields`).

The **code** is what an automation names the answer by. Leave it empty and
it is made from the name.

A choice is kept in the shop's base language, whatever language the
customer bought in, so "How did you find us" can be counted: write the
answers of every language in the same order — they are matched by line.

Deleting a field does not touch the orders: each keeps the answers it was
given.

## Examples

**A flower shop — "Who takes the bouquet".** Line of text, under the
delivery, only for the courier, required. Description: "Name and phone of
the person we hand the bouquet to." A pickup order is never asked.

**"How did you find us".** One of several, above the order notes, not
required. Answers: Google, Instagram, TikTok, A friend told me.
Description: "One question — thank you for answering."

**Bank details of a company.** Line-of-text fields asked of companies only:

- Russia: KPP, OGRN, legal address, bank, BIK, account;
- Ukraine: VAT payer (a tick box), IBAN, legal address.

The company's name and tax code (INN / EDRPOU) are already asked by the
checkout itself.

**A gift note.** Several lines, above the order notes: "A card to go with
it — we will write it by hand."

**A delivery date.** Date, under the delivery, only for the courier.
