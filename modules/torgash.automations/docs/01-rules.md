# Writing a rule

A rule is four things in a row: **when** something happens, **wait** if you
like, **only when** the conditions hold, **then** act.

## When

Three triggers today, all about orders:

* **An order is placed** — once, at checkout.
* **Something about an order changes** — the general one. It carries a diff, so
  it can be narrowed to the fields you care about: status, payment state,
  contents, manager, total, methods. Tick nothing and it fires on any change.
* **An order status changes** — the one merchants think in, kept separate
  because it is the most-wanted trigger of the three.

One admin action is one event, however many writes it took internally: editing
the contents rewrites lines, subtotal and total, and your rule sees that once.

## Wait

Minutes, and it is the queue that waits — nothing in the shop is held up. Use it
for "half an hour after the order is placed, if it is still unpaid…".

**The conditions are checked when the rule actually runs, not when it was
triggered.** In half an hour the order may have been paid or cancelled, and
firing at it anyway is the one behaviour nobody forgives.

## Only when

All the requirements must hold. "One of these" goes inside a single row — a list
of statuses is one requirement, not three. A rule with no requirements runs on
every trigger.

## Then

Today: **send a webhook**. Method, URL, headers one per line, and a body you
compose from the order.

Every attempt is written to the run log with what was sent and what came back —
including a receiver that answered 4xx, which is the failure nobody notices
otherwise. A failed request is retried twice, a minute and then five minutes
later, carrying the same `Idempotency-Key` so the other side can tell a retry
from a second order.

## Composing the body

Variables are `{{ order.total }}`, by dotted path; the editor lists every one of
them under "Available variables". Line items repeat:

```
{"lines":[{{#order.items}}{"sku":"{{ item.sku }}","qty":{{ item.qty }}},{{/order.items}}]}
```

Two things worth knowing:

* **Amounts are minor units** — `order.total` is `15000` for 150.00, which is
  what an API wants. Every amount also has a `_decimal` twin (`150.00`) for the
  systems that insist on a string.
* **Values are escaped for JSON.** A customer called `O"Brien` will not break
  your payload.

This is not Blade and cannot run code: it substitutes paths and repeats blocks,
and anything else you type stays as typed.

## Testing it

"Test" runs the rule against your newest order immediately, ignoring the wait.
The conditions are still checked, so a test that skips is telling you something
true. The result is in the run log, request body and all — which is the fastest
way to find out that the receiver wanted `order_id` and you sent `number`.

## URLs it will refuse

The URL is written by you, but the request is made by the server, from inside
your network. So a webhook may only leave the building: `localhost`, private
ranges and `169.254.169.254` (the cloud metadata service, which hands out
credentials to whoever asks from inside) are refused, and redirects are not
followed.

On a development machine talking to a local receiver, turn on **Allow requests
to private addresses** in the module's settings — and turn it off again before
that database becomes a shop.
