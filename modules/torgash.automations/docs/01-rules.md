# Writing a rule

**Sales → Automations.** A rule is four things in a row: **when** something
happens, **wait** if you like, **only when** the conditions hold, **then**
act — message a manager in Telegram, send the order to another system, set
a status, assign a manager.

## When

A rule is about an order, or — with the enquiries module on — an enquiry.

About an order:

* **An order is placed** — once, at checkout.
* **Something about an order changes** — the general one. It carries a diff,
  so it can be narrowed to the fields you care about: status, payment state,
  amount paid, total, contents, manager, payment method, delivery method,
  delivery cost. Tick nothing and it fires on any change.
* **An order status changes** — the one merchants think in, kept separate
  because it is the most-wanted trigger.

About an enquiry (`torgash.enquiries`):

* **An enquiry arrives**
* **The customer writes again** — a new message on an enquiry that was
  already there.
* **An enquiry status changes**
* **An enquiry waits for an answer too long** — past the enquiries' setting
  **Answer within, hours**.

One admin action is one event, however many writes it took internally:
editing the contents rewrites lines, subtotal and total, and your rule sees
that once.

## Wait

**Wait, min**, and it is the queue that waits — nothing in the shop is held
up. Use it for "half an hour after the order is placed, if it is still
unpaid…". `0` runs at once (still in the queue).

**The conditions are checked when the rule actually runs, not when it was
triggered.** In half an hour the order may have been paid or cancelled, and
firing at it anyway is the one behaviour nobody forgives.

## Only when

All the requirements must hold. "One of these" goes inside a single row — a
list of statuses is one requirement, not three. A rule with no requirements
runs on every trigger.

For an order:

| Condition | Asks |
|---|---|
| Order status | is in / is not in the statuses ticked |
| Payment state | is in / is not in |
| Order total | is at least / is at most |
| Product category | has an item from / has every item from / has no item from (a category includes its subcategories, as everywhere in the shop) |
| Payment method, Delivery method | is / is not — the name as the order has it |
| Country | is one of / is none of — codes, `UA, PL` |
| City | is / contains |
| Manager | somebody is assigned / nobody is assigned |
| Coupon | any coupon was used / this coupon was used / no coupon was used |

For an enquiry: **Kind of enquiry** and **Enquiry status** (is in / is not
in), **Contact left** (a phone number, an email, only a phone number, asked
from an account), **Manager** (assigned or not) and **What they wrote**
(contains a word).

A condition nobody understands any more — its module was removed — never
passes: a rule that fires on everything is worse than one that never fires.

## Then

One action or several, run in order:

* **Send a Telegram message** — "a new order" on the manager's phone. See
  below.
* **Send a webhook** — method, URL, headers one per line, and a body you
  compose from the order or the enquiry.
* **Change the order status** and **Assign a manager** — the order's own
  changes, see "Sharing a rule, and changing the order".
* **Change the enquiry status** and **Assign the enquiry to a manager** — the
  same for an enquiry.

An action that makes sense only for orders is not offered on an enquiry's
rule, and the other way round.

Every attempt is written to the **Run log** with what was sent and what came
back — including a receiver that answered 4xx, which is the failure nobody
notices otherwise. A failed request is retried twice, a minute and then five
minutes later, carrying the same `Idempotency-Key` so the other side can
tell a retry from a second order. The webhook also carries
`X-Torgash-Event` — the trigger's name. The log is kept for **Keep the run
log, days** (30 by default); the list shows a red count of what failed in
the last 24 hours.

## Telegram

1. Create a bot with @BotFather and copy its **Bot token**.
2. Add the bot to the chat or group the message should land in, and write
   anything there.
3. Open `https://api.telegram.org/bot<token>/getUpdates` — the number after
   `"chat":{"id":` is the **Chat id** (a group's is negative).

The token is a credential, which is why Telegram is an action of its own
rather than a webhook with the token in its URL: it is never drawn back into
the form (a blank box keeps the saved one), it is masked in the run log, and
it never travels in an exported rule.

The message is written as a person writes one, with the variables below.
`<b>`, `<i>` and `<a href="…">` work; the values are HTML-escaped, so a
customer's name with angle brackets cannot break the message — Telegram
refuses outright a message whose HTML does not parse. A longer message is cut
at 4096 characters, the most Telegram takes.

## Composing the text and the body

Variables are `{{ order.number }}`, by dotted path; the editor lists every
one under **Available variables** — click one and it lands at the cursor.
For an order there are the number, the status, the link to the order in the
admin, the customer's name, phone, email and address, the money, the
delivery and payment methods. For an enquiry — its number, kind, status,
what it is about, what the customer wrote, how long they have waited and the
link to it in the admin.

Line items repeat:

```
{{#order.items}}• {{ item.name }} × {{ item.qty }} — {{ item.line_total_formatted }}
{{/order.items}}
```

or, for an API:

```
{"lines":[{{#order.items}}{"sku":"{{ item.sku }}","qty":{{ item.qty }}},{{/order.items}}]}
```

Two things worth knowing:

* **Amounts are minor units** — `order.total` is `15000` for 150.00, which
  is what an API wants. `order.total_decimal` (`150.00`) is for the systems
  that insist on a string, `order.total_formatted` (with the currency, in the
  shop's own way of writing money) is for a person.
* **Values are escaped for where they go** — for JSON in a webhook, for HTML
  in Telegram. A customer called `O"Brien` will not break your payload.

This is not Blade and cannot run code: it substitutes paths and repeats
blocks, and anything else you type stays as typed.

## Testing it

Save the rule, then **Test on the latest one** runs it against the newest
order (or enquiry) immediately, ignoring the wait. The conditions are still
checked, so a test that skips is telling you something true. The result is
in the run log, request body and all — which is the fastest way to find out
that the receiver wanted `order_id` and you sent `number`.

## URLs it will refuse

The URL is written by you, but the request is made by the server, from
inside your network. So a webhook may only leave the building: `localhost`,
private ranges and `169.254.169.254` (the cloud metadata service, which
hands out credentials to whoever asks from inside) are refused, and
redirects are not followed.

On a development machine talking to a local receiver, turn on **Allow
requests to private addresses** in the module's settings — and turn it off
again before that database becomes a shop. **Request timeout, seconds**
(10) is how long a receiver may take; a customer never waits for it, but a
receiver that hangs holds a worker.
