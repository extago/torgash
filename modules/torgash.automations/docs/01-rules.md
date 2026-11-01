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
| The customer's orders | this is their first order / they have ordered before (by the account, or a guest's phone or email) |
| Stock | every item is in stock / an item is not in stock (the product is gone, its stock went below zero — sold past the shelf, a preorder — or the order holds no units yet and there are fewer than the line has) / an item runs low after this order / an item is sold out after this order (see "Running low") |
| How it was placed | is in / is not in: checkout, one click, by a manager, API, exchange for a return. An order placed before this was recorded is in none of them |

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
* **Email the customer** — a subject and a text with the variables, to the
  email on the order or the enquiry, in the shop's letter frame (logo,
  colours, footer) and the theme of the storefront the order came from. A
  blank line starts a paragraph. A customer with no email is a run log line
  "The customer left no email" and no letter. Bank details for a transfer,
  "we could not reach you — ring us back".
* **Add a note to the history** — a line in the order's history (or the
  enquiry's notes) beside the rule's name: "payment reminder sent". Seen by
  the staff only.

An action that makes sense only for orders is not offered on an enquiry's
rule, and the other way round.

Every attempt is written to the **Run log** with what was sent and what came
back — including a receiver that answered 4xx, which is the failure nobody
notices otherwise. A receiver that could not be reached — its host down,
the connection dropped on the way to Telegram, a "try later" (429) or an
error on its side (5xx) — is tried again: that one action, not the rest of
the rule, after **Retry after, minutes**, up to **Retry an action the
receiver did not take, times** (3 and 2 minutes by default; 0 switches
retries off). Each attempt is a row of the log, marked *retry* until the
last one, which is *sent* or *failed*. A refusal — a wrong token, a chat
not found, any 4xx — is never retried: it stays until somebody fixes it.
A webhook carries the same `Idempotency-Key` on every attempt so the other
side can tell a retry from a second order, and `X-Torgash-Event` — the
trigger's name. A Telegram message the network dropped after Telegram had
taken it can arrive twice; a lost one would be worse. The log is kept for **Keep the run
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

A line also knows its product: a link to it (`item.url`, and
`item.admin_url` for the admin), its photo (`item.image_url`), its
variation in one line (`item.variation` — "black, 256 GB"), any
characteristic by its code (`item.features.COLOR` — the code is on the
characteristic's form) or all of them at once (`item.features_text`), the
brand, the weight in grams, and the options chosen with it
(`item.options_text`). A message that links its lines:

```
{{#order.items}}• <a href="{{ item.url }}">{{ item.name }}</a> {{ item.variation }} × {{ item.qty }}
{{/order.items}}
```

A block named after a VALUE rather than a list is printed once when the
value is there and not at all when it is empty — so a label or brackets
around something not every order has go inside such a block, and a product
without options gets no bare "Options:" and no empty "()":

```
{{#order.items}}• {{ item.name }}{{#item.options_text}} (options: {{ item.options_text }}){{/item.options_text}}
{{/order.items}}{{#order.coupon}}Coupon: {{ order.coupon }}{{/order.coupon}}
```

The inverted block — `^` instead of `#` — is printed when the value is NOT
there (or the list is empty). Together they make "if there is — this, if
not — that": an order placed in one click has no town, and instead of a
bare "Town:" the message says why:

```
{{#order.city}}Town: {{ order.city }}, {{ order.address_line }}{{/order.city}}{{^order.city}}No town given (a one-click order){{/order.city}}
{{^order.email}}No email — ring {{ order.phone }}{{/order.email}}
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

## Running low

Stock is asked when an order happens, not watched all the time: a sale is
what empties a shelf, and the message about the sale is the place for
"1 left".

Every line of the order has:

* `{{ item.stock }}` — what is left on the shelf **after this order**. An
  order that has not taken its units yet (awaiting payment) is counted as if
  it had — the same number however the customer pays;
* `{{ item.low_stock }}` — `true` when it runs low: at or below the
  threshold, none left, or sold past the shelf;
* `{{ item.stock_state }}` — the same as a code: `low`, `out`, `oversold`.

And the order: `{{ order.low_stock_count }}` — how many of its products run
low (for a heading printed once), `{{ order.low_stock_text }}` — them in one
line.

```
{{#order.low_stock_count}}⚠️ Running low:
{{#order.items}}{{#item.low_stock}}• {{ item.name }} ({{ item.variation }}) — {{ item.stock }} pcs.
{{ item.url }}
{{/item.low_stock}}{{/order.items}}{{/order.low_stock_count}}
```

The link opens the variation itself. **The threshold** is the module's
setting "Running low at, pieces"; 0 — the catalogue's "Low stock
threshold". The condition "Stock — an item runs low after this order" sends
it as a rule of its own (to a buyer's chat, say); the ready-made one is
"Running low", and "New order to Telegram" carries the block already.

## Formats

A value can go through a format: `{{ value | format }}`, several in a row —
one after another.

| Format | Does |
|---|---|
| `lower`, `upper` | lower / upper case |
| `trim` | no spaces at the ends |
| `digits` | only the digits: "+38 (067) 123" → "38067123" |
| `phone` | a phone as one word: "+38 (067) 123" → "+38067123" |
| `int` | a number without its fraction: "1042.25" → "1042" |
| `url` | for a link's address: spaces and `&` encoded |

```
<a href="tel:{{ order.phone | phone }}">{{ order.phone }}</a>, total {{ order.total_decimal | int }}
```

## What it was, and who changed it

A rule on a change of an order knows what the order was before and who
changed it:

* `{{ before.status_label }}`, `{{ before.total_formatted }}`,
  `{{ before.payment_state_label }}`, `{{ before.manager }}`,
  `{{ before.items_text }}` — the values before the change. A field that did
  not change holds what it holds now, so "was → now" always reads;
* `{{ actor.name }}` — who changed it: a manager's name, "the customer" or
  "the system" (a payment confirmed by the gateway, an order cancelled by its
  own deadline). `{{ actor.kind }}` says it as a code: `staff`, `customer`,
  `system`;
* `{{ changed_text }}` — what changed: "Status, Total".

```
Order {{ order.number }}: {{ before.status_label }} → {{ order.status_label }} ({{ actor.name }})
```

More about the order: `{{ order.source_label }}` — how it was placed ("One
click", "By a manager"), `{{ order.date }}` — when, by the shop's clock
("01.11.2026 14:05"), `{{ order.items_text }}` — the items in one line.

## Ready-made rules

**Automations → Ready-made rules**: a new order to Telegram, payment
received, a large order, one-click orders to a manager, status changed, order
cancelled, order edited after it was placed. The conditions and the message
are written already; the rule asks for the Telegram bot and chat (leave them
empty to use the ones your other rules use) and arrives switched off: check it
with "Test" and enable it. From then on it is an ordinary rule — change
anything.

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
