# Returns and exchanges

A return is a **kind of ticket**, not a second inbox. The customer asks from
their own order page; the conversation happens in the enquiry, in the one inbox
the shop already reads; and the money, the stock and the order status move
through the shop's own code rather than a copy of it living here.

## What the customer sees

On the order page — theirs by account, by the session that placed it, or by the
order's signed link — the conversations already attached to that order and two
buttons: **Ask about this order**, which is checkout's own and is there whether
or not this module is installed, and **Return or exchange**, which is this
one's. Afterwards the thread itself. A guest can answer in it without an
account: the reply form carries a signature of its own, which says which
thread, not who.

The **Return or exchange** button appears only when the shop says it may (see
below). Asking a question never has that condition — a shop that takes no
returns at all still has people asking where their parcel is.

Both forms take **photographs of the fault**, and so does every reply on either
side. That is the enquiries module's doing, not this one's — see its own docs
for the limit and for why the files are not on the public disk.

The button appears only when the shop says it may:

- the order is in one of the **statuses the shop ticked** — nobody outside the
  shop knows which of its own statuses means "the customer has it", so nothing
  ticked means nothing offered;
- optionally, **only orders recorded as paid here**. Off by default, and
  deliberately: plenty of shops watch payment in a CRM or in accounting, and
  there this check refuses a perfectly lawful return;
- optionally, **within N days** of the order;
- and there is something left to send back. Quantities already covered by a
  return that has not been declined are gone from the form, so the same goods
  cannot be claimed twice.

## What the shop gets

A ticket in the enquiries inbox — with a status, a manager, a conversation and a
notification, none of which this module had to build — plus the part an enquiry
cannot hold: which lines, how many, at the price the customer actually paid,
frozen off the order.

## When the shop is the one who decides

A return does not have to start as a return. Somebody writes in with a
**question** about their order, it turns out the thing is faulty, and the shop
says "send it back" — so any ticket carries a **Return or exchange** card for a
manager with `rma.manage`: name the order (already filled in when the ticket was
asked from an order page), pick what is coming back off that order's own lines,
and say what happens next.

It attaches to the ticket that is already there rather than starting a second
one, and that is the whole point: everything already said stays in the one
conversation. The ticket's kind becomes *Return or exchange*, so the inbox's own
filter finds it; not a word of what the person wrote is touched, because the
answers and the labels they were asked under are a snapshot on the row.

**The shop's offer rules do not bind a manager.** The statuses, the days and
"only if we recorded it as paid" answer *when do we show a customer the button* —
taking something back outside that window is exactly what a shop keeps people
for, and refusing them would only mean the return happens in a spreadsheet
instead. What still binds is the arithmetic: quantities are bounded by what is
actually left of the order, because the same unit cannot come back twice however
senior the person asking.

## Handling one

There is no returns screen. The card is on the **ticket**, in Enquiries, where
the conversation already is — and the inbox list carries the return's own badge
so a queue can be read without opening every row. Acting on one needs the
`rma.manage` permission, which is deliberately not the same as being allowed in
the inbox: a shop can let everybody answer questions.

Five buttons, each of which can carry a note. **The note is not decoration** —
it is posted into this very conversation, which is what emails the customer. A
state badge on a page they may never open has told them nothing.

- **Approve** / **Decline** — the answer, and the reason for it.
- **Goods received** — *this* is when stock moves, not the approval: an approval
  is a promise, a delivery is a fact. Two choices come with it:
  - *what happens to them*: back into stock, or **written off**. A warranty item
    goes to the manufacturer or into the bin, and putting it back on the shelf
    would offer a broken thing for sale — which is why this is decided when the
    parcel is opened rather than set once in the settings;
  - *where it arrived*: a named warehouse, or "back where it came from". A
    customer returns to the nearest shop, not to the one that shipped. The
    picker only appears with `torgash.warehouses` installed.
- **Refund the money** — an amount, defaulted to what the goods came to and
  still typed in, because a shop may keep the postage or agree something else.
  Where a gateway took the money it is asked to give it back; where the payment
  is only **on hold it is released rather than transferred**, since paying
  somebody money that never left their card costs the shop twice. Where nothing
  took it — a shop that watches payment in its CRM — the screen says so plainly
  instead of stamping a refund that never happened.
- **Create the exchange order** — a new order for the same goods to the same
  address, placed through the shop's own checkout code and belonging to the
  **customer**, not to the manager who pressed the button. It is priced from
  today's catalogue, so what is left to settle is *recorded* (nothing / the
  customer owes / we owe) and never charged: a difference is settled between
  people, and the engine will not invent a payment.

A finished return can be **reopened**, because a manager will sometimes be
wrong. Reopening moves nothing else: stock that went back stays back and a
refund that was paid stays paid, because those are facts about the past.

### The order's own status

Optional, and empty by default: *when a return is completed, move the order to…*
It applies **only when nothing is left of the order to return** — half an order
coming back does not make the order returned. Statuses that put stock back are
not offered at all, and are refused again when the setting is applied: this
module has already returned the lines, and such a status would count the same
goods twice.

## Over the API

`admin.returns`, with `approve`, `decline`, `receive`, `refund` and `exchange`
as **actions** and no `update` at all. Every one of these does something outside
its own row, so a client PATCHing `state` to "received" would have set a string
and moved nothing while looking like it worked — the same argument by which an
order's status is an action. That is also what makes this a safe place to hang
an agent: there is only one set of rules, and it cannot get past them.

## What it deliberately does not do itself

- **money** goes through checkout's `Refund`, which asks the gateway where there
  is one and records it by hand where there is not — so an order's payment state
  moves in one place, and refunding an order with no return in sight works the
  same way;
- **stock** goes back through `OrderStock::restore()`, which credits the
  warehouses the units actually came from — a return is not an order edit, so
  the order keeps its lines and its breakdown while the goods reappear;
- **order status** moves through `OrderStatusChanger`, never a column;
- an **exchange is a new order**, linked to the old one, never an edit of it:
  editing moves a total the customer, 1C and the accounts already agreed on.
