# Sharing a rule, and changing the order

## Export and import

**Export** (the button on a rule's row) puts a rule in a JSON file: the
trigger, the delay, the conditions and — the part that took the afternoon —
the message or the body you composed.

**It does not contain the URL, a single header value, the bot token or the
chat id.** They are always yours: the endpoint belongs to your account, the
key and the bot belong to you. The template carries the header *names* so
the import knows what to ask for, and **Import** asks for each of them —
plus the address, or the bot token and the chat id — before it creates
anything. That is why a template you post in a forum thread cannot take
your key with it.

An imported rule **arrives switched off**. It points at an endpoint nobody
has tested from this shop yet, and **Test on the latest one** is one click
away — a template whose first act is a wrong POST about a real order is not
a good introduction.

Anything the importing shop cannot run is dropped rather than stored: an
unknown condition, an action from a module that is not installed. What
travels but may not exist — a status code, for instance — stays, and the
run log says so out loud the first time it is used.

## Changing the order

Two actions do not send anything anywhere:

* **Change the order status** — through exactly the same code the status
  select on the order screen uses, so the stock is taken (or given back)
  once, and the change is announced like any other. An order already in
  that status is skipped, not changed again.
* **Assign a manager** — with the same rule the screen enforces: only a
  staff account can hold an order.

Both write a line into the order's own history saying which rule did it. A
manager who finds a status they did not set can see why.

An enquiry has the same two: **Change the enquiry status** (the first move
to a finished state stamps when it was answered, as the inbox's own select
does) and **Assign the enquiry to a manager**.

## Rules do not chain

**A change made by a rule does not trigger rules.** "When the status
changes, set the status" would otherwise run forever, and two rules
pointing at each other is the same loop with an extra step.

This reads as a limitation until you have watched a shop send four thousand
webhooks about one order. A rule that has to do two things does them as two
actions, in order — which is what the actions list is for.

## Many rules at once

Tick rules on the list to switch several on or off, or delete them. A
deleted rule's run log stays.
