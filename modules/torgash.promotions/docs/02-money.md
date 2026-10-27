# How the money adds up

## The order of things

A cart is priced in one direction, and each step sees what the one before it
left:

1. The goods, at whatever the catalog-zone promotions already made them cost.
2. The **coupon**, if there is one.
3. **Cart-zone promotions**, off what is still owed after the coupon.
4. Delivery, and whatever else the shop adds (tax, a fee).

Step 3 is worth stating plainly: a "50% off" promotion on a cart that already
has a 40.00 coupon takes half of what remains, not half of the sticker price.
The alternative — both discounts computed from the same untouched subtotal — is
how a cart ends up discounted twice over.

Two pools cap all of it. Goods discounts can bring the goods to zero and stop
there; a free-shipping tier can cancel the delivery once, however many
promotions asked for it. **An order can be free. It can never be worth less
than nothing.**

## What a placed order keeps

An order stores what it was actually given, frozen at placement, exactly as it
stores its own tax breakdown. Editing or deleting the promotion afterwards does
not move a single number on it — a customer agreed to a total, and that total is
a fact about the past.

The one thing that rewrites the snapshot is a manager editing the order's own
**contents**. Then the discount is recomputed for what the order now contains —
the promotion did not change, the order did — and no redemption is charged a
second time.

## Side effects are not discounts

A **gift product** and a **customer group upgrade** never happen while a cart is
merely being re-priced — only when an order is really placed. A page redraw must
not promote anybody or hand out stock.

A gift goes onto the order as an ordinary line at zero, through the same stock
deduction as everything else, and is **withheld if the shelf cannot cover it**:
a gift is a bonus, not what the customer came for, and refusing the whole order
because a freebie ran out would punish someone who is paying. When it is
withheld, nothing is recorded and no redemption is spent.

## Where the numbers go afterwards

- **The order screen and the invoice** show each promotion as its own line,
  under the name it was taken under.
- **1C (CommerceML)** receives them as `<Скидки>` with `УчтеноВСумме=Нет`,
  beside the coupon — never as goods with a negative price, which some 1C
  configurations refuse outright. The document adds up: lines, less discounts,
  is the total.
- **The API** answers with them under `adjustments` on the order, and the
  promotions themselves are readable at `/api/v2/admin/promotions`.
- **The Usage log** totals what was given away for whatever the filter selects.

## Two limits worth knowing

**The usage cap is advisory under simultaneous checkouts.** It is read, and the
counter raised, just after the order is committed — so two checkouts landing
together can both take the last redemption. The counter is then allowed to show
501 of 500 rather than hiding it: the shop sees the truth, one order late. (The
coupon has the same property, in the same place.)

**"Once per customer" is exact for accounts, best-effort for guests.** A guest
becomes recognisable only once their order exists, so the check is real at
placement and optimistic in the cart preview before it.
