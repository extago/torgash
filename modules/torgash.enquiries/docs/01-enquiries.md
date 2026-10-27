# Enquiries

Everything a visitor asks lands in one inbox: a callback, a question about a
product, "tell me when it is back", a message from a contact page. They are one
table with a `kind`, not four little modules — a shop that takes all four has
one person answering them, and four inboxes is how the quiet one goes unread
for a month.

## What a shop gets without configuring anything

- **A callback dialog on every storefront page.** Anything can open it: a link
  to `#enquiry-callback`, an element with `data-enquiry-callback`, or the
  `{callback_link}` token in an HTML block. Switch it off in the settings and
  that token goes back to dialling the shop's number — content written with it
  keeps working either way.
- **"Ask about this product"** under the buy box, and **"Tell me when it is
  back"** on products that really are out of stock.
- **A form block** for any zone, so a contact page is a page with a block on it.
- **Three emails**, sent by the module itself rather than by a rule you have to
  set up first: a new enquiry to the shop, the shop's answer to the customer,
  and the customer's reply back to the shop. An enquiry nobody reads is the
  thing this module exists to prevent, and that includes the second half of the
  conversation.

## The list opens on the queue

The inbox shows the **open** enquiries by default — new and in progress — and
not the archive of everything ever answered. That is what a manager opens it
for, and a year of answered callbacks on top is how the one unanswered question
goes unseen.

Nothing is hidden to do it: the state is a row of tick boxes, and the two that
are ticked when you arrive are the default itself. Untick them all and press
**Filter** to see every state, tick exactly what you want to see, or press
**Reset** to come back to the queue.

## Why the answers are stored

Because the alternative is what most shops actually run: a form that composes
an email and keeps nothing. When the mail bounces, when it lands in spam, when
somebody deletes it — the enquiry never existed. Here the row is written first
and the email is a copy of it, queued, retried, and visible under Background
tasks if it never gets through.

Stored with each answer is **the label it was asked under**, in the language the
visitor was reading. Rename a field next year and March's enquiries still read
the way they were written.

## The basket

An enquiry keeps a snapshot of what was in the visitor's basket at that moment —
names, quantities and prices, frozen. It is the manager's first question ("what
were they looking at?") and the one thing nothing can answer afterwards.

## The conversation

An enquiry is not a note left on a spike; it is the start of a conversation, and
the conversation lives on the same row. The manager's reply goes into the thread
on the enquiry screen, and the shop's half and the customer's half sit in one
list in the order they happened.

The **first message is the enquiry itself** — what the person typed, field by
field, under the labels they were asked it under. It is not copied into the
thread as message number one: that would be two records of the same words, and
they would disagree the first time somebody edited one of them. So a thread that
says "nobody has replied yet" means exactly that.

Replying does three things beyond writing the message, because they are what a
manager MEANS by replying:

- it ends the wait. `answered_at` used to be inferred from the status
  ("it left the open states, so presumably somebody answered"); now it is the
  reply itself, stamped once and never moved — it measures how long the person
  waited, and a status corrected twice must not keep resetting that;
- a **new** enquiry becomes **in progress**, so the badge stops counting a
  thread somebody is already handling;
- an **ownerless** enquiry gets whoever answered. Making them also pick
  themselves from a select is a step that gets skipped, and then nobody owns
  anything.

Every message announces `enquiries.message.created`, with the enquiry already
saved — so a rule reading the status sees the state the reply left it in.

## In the customer's account

A signed-in customer gets **Enquiries** in their account menu: their own
threads, and a box to add to one. The menu item is the module's own — it is
answered into the list core renders, so uninstalling the module takes the item
away with the pages it pointed at.

Two rules about who may read what:

- the list is filtered by the **account**, never by the email address on the
  row. A guest enquiry left under an address somebody registers with next month
  stays a guest enquiry: an email address is not proof of identity, and matching
  on it would hand one person's conversation to whoever signs up with it;
- somebody else's thread answers **404**, not "forbidden". A thread that is not
  yours must not be distinguishable from one that is not there.

There is no signed "open your thread without logging in" link, and that is a
decision rather than an omission: a guest has no profile to come back to, so a
guest enquiry keeps working the way it always has — a form, and an answer by
email.

The customer sees a **shorter vocabulary** than the inbox does, and it answers a
different question. Theirs is "has anybody answered me", and that is not the
status: the shop replying leaves an enquiry *in progress*, which read outward as
"waiting for an answer" printed directly under the answer. So their state is
derived from the conversation — the shop spoke last, so there is an answer; the
customer spoke last, so the shop owes one; the shop closed it, so it is closed.
"Rejected" is never shown as such: it is a decision about the enquiry, not a
thing to print at the person who wrote it.

A thread holding an answer they have not opened is marked **New answer** in the
list and counted beside *Enquiries* in the menu, on every page. Opening the
thread is what marks it read — there is no button for it, and the stamp is
written without touching the enquiry's own `updated_at`, so somebody reading
their own page never looks like the shop editing the row.

## The three emails

All of them are queued, so nobody waits on SMTP and a mail host that is down
cannot turn a stored row into an error page; a final failure shows up under
Background tasks with the row itself safe either way.

- **a new enquiry → the shop**, at the address in the settings, or the
  storefront's own contact address when that is empty;
- **the shop's answer → the customer**, at the address they left. No address, no
  email — a callback given with a phone number and nothing else gets nothing,
  never a message to an address made up to fit. It is written in the language
  the enquiry was written in, it quotes their original question (this arrives
  days later and out of any context), and Reply-To is the shop, so a guest
  hitting reply reaches somebody. A link to the thread only when there is an
  account to read it with;
- **the customer's reply → the shop**, with Reply-To set to the customer. Their
  reply already put the enquiry back in the queue; this is what stops it waiting
  a day for somebody to open the list.

Everything beyond these three — Telegram, a webhook, a reminder if it is still
unanswered in two hours — is an automations rule off `enquiries.enquiry.created`
or `enquiries.message.created`, both announced whether or not any address here
is configured.

## Ending a conversation

**Question resolved** on the enquiry screen marks it answered and *closes* it:
after that neither the customer nor the shop can add to the thread. That is the
difference from merely finishing one — a *done* enquiry can be written into for
ever, so somebody comes back to a year-old thread and the office finds itself
answering a context nobody remembers.

Closing is a second axis beside the status, not a fifth status, because "have we
dealt with this" and "may anybody still write here" are different questions: a
rejected enquiry is closed without ever having been answered, and an answered one
can be deliberately left open. Rejecting one closes it too — "not worth
answering" cannot mean anything if the row still takes messages.

**Reopen** puts it back, and it is a decision somebody makes rather than a side
effect of typing. The rule itself lives in the one place a message is created,
so every door — the two screens, the API, whatever answers enquiries next — gets
the same answer.

## Closing a conversation that ran out

A shop answers, the customer is satisfied and says nothing, and the thread sits
in the queue for ever — that is how an inbox fills with work that is already
done and the badge stops meaning anything. Set **Close an answered enquiry
after** to a number of hours and a thread the SHOP spoke last in closes itself
once its answer is that old.

The rule is deliberately narrow. A thread where the CUSTOMER spoke last is never
closed by it, however old: that is an unanswered question, and closing it would
be the inbox tidying away the shop's own backlog. And `answered_at` keeps the
moment the answer was written — a sweep running at 3am is not when the person
was answered.

Nothing closes itself until the shop asks for it: the setting starts at 0.

## Photographs

Every form and every reply box can carry photos — the fault, the damaged box,
the label on the parcel. **Setting: "Photos a customer may attach"**, counted per
enquiry and per reply against what that message already holds. Set it to 0 and
attachments disappear from every form at once.

Four things about it are deliberate, and all four are because this is a *public*
upload:

- **No upload endpoint of its own.** Files ride the POST that was already
  happening, so there is no second door to guard and the form's own rate limit
  already counts them. An asynchronous uploader would be a public route
  accepting files before anybody has said anything — a hole with a progress bar.
- **Photographs only, and judged by what they are.** A file goes through the
  same `ImageProcessor` a product photo does: the type is read from the BYTES,
  never from the name, so `shell.php.jpg` is refused rather than renamed; the
  shop's size limit applies; and a 12-megapixel phone photo is shrunk to the
  configured maximum instead of sitting on the disk at 6 MB.
- **They are not on the public disk.** A product photo is meant for anybody and
  is served straight off `/storage`, addressed by a sequential id. Somebody's
  kitchen table is not, so attachments live on the private disk and are handed
  out by a route that asks the same question the thread does: the staff who work
  the inbox, the account that owns it, or a link signed for that one file —
  which is how a guest reading their order by its key sees the photo they sent.
- **A refused file never costs the message.** What somebody typed is the
  valuable half; handing three paragraphs back as an error over one oversized
  photo is how a person gives up and rings instead. The text is saved and the
  refusal is shown above it.

## Spam

A public form that writes rows is the most reliably abused thing a shop puts on
the internet, so three defences are always on and none of them asks the customer
to prove anything:

- a honeypot field, hidden from people;
- the form's own age, carried in an encrypted stamp — a form submitted a second
  after it appeared was not typed into, and one submitted a day later is a
  replay;
- a rate limit per address and per phone number.

There is no CAPTCHA. It taxes every real customer to stop what the three cheap
layers stop most of, and a shop that needs one needs a specific one — so a
module can add it by answering the `core.form.check` hook with a message.

## Rules

Every enquiry announces `enquiries.enquiry.created`, and a hook name is what the
automations module takes as a trigger. So "send it to Telegram", "post it to the
CRM", "remind me if it is still new in two hours" and "assign it to whoever is
on shift" are rules, not settings here.

## Adding a kind of your own

```php
Torgash\Enquiries\Kinds::register('spare_part', [
    'label' => 'Find me this part',   // English; the Russian goes in your module's lang/ru.json
    'icon' => 'wrench',
    'fields' => [
        ['key' => 'phone', 'type' => 'tel', 'required' => true, 'label' => 'Phone'],
        ['key' => 'vin', 'type' => 'text', 'label' => 'VIN'],
    ],
]);
```

Answer the `enquiries.kinds` hook from your provider and it appears in the form
block, the inbox filter and the automations trigger, with a status, a manager
and a notification — without a table of your own.

## Over the API

`/api/v2/admin/enquiries` reads the inbox with the whole conversation inline,
and `POST .../{id}/actions/reply` answers one. The reply is an action rather
than a write, for the same reason an order's status is: it ends the wait, moves
a new enquiry into the queue, gives an ownerless one to whoever answered and
emails the customer. A client writing a message row would have done none of
that, and would have looked like it worked.

`POST .../{id}/actions/close` ends one the same way the button does, and every
enquiry carries `locked` so a client knows which threads are over.

The filter to ask for work is **`awaiting_reply=1`**, not `status=new`: the shop
replying leaves an enquiry in progress, and a customer writing back to a
finished one reopens it — either way the queue is "who spoke last". Every
enquiry also carries `awaiting_reply` in its own payload.

A call is an ordinary logged-in user, so the answer is signed with that user's
name and shows up in the thread exactly as a manager's would. That is what makes
this the honest place to hang an assistant that drafts or sends replies: it
cannot reach around the rules the box on the screen goes through.
