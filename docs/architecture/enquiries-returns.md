# Enquiries and returns

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

## Enquiries (torgash.enquiries)

Everything a visitor asks is ONE table with a `kind` — a callback, a question
about a product, "tell me when it is back", a message. Not a module per reason:
a shop answering all four has one person doing it, and four inboxes is how the
quiet one goes unread. `Kinds::register()` is how another module gets a status,
a manager, a notification and an automations trigger without a table of its own
(answered lazily through the `enquiries.kinds` hook). The inbox opens on the
QUEUE: with no state in the URL it shows the OPEN ones, and the tick boxes show
that rather than hiding it. `status_set` is the hidden marker that makes the
default undoable — a browser sends nothing for an unticked box, so without it
"show me everything" arrives looking exactly like a first visit.

Non-negotiables, each with a test: the **answers are stored** with a snapshot of
the labels they were asked under (a form builder that only composes an email
keeps nothing — a bounced message is an enquiry that never existed, and a label
snapshot is what lets the form be edited without rewriting March); `subject_type`
comes from the KIND, never the request, so a form cannot file a callback against
an order it invented; the **basket is snapshotted**, because "what were they
looking at" cannot be answered after the next click; and everything goes through
`Submit`, the one place an enquiry is created, exactly as `PlaceOrder` is for an
order. Statuses are a fixed vocabulary (`Status`, a class) — an order's workflow
differs per shop, "have we rung them back" does not.

**An enquiry is the start of a conversation, and the conversation is on the same
row** (`m_enquiries_messages`, `Torgash\Enquiries\PostMessage` — the one place a
message is created, exactly as `Submit` is for the enquiry). The FIRST message is
the enquiry itself: what the person typed is already stored field by field with
the labels they were asked under, and copying it into the thread would be two
records of the same words. A staff reply is not just a row — it stamps
`answered_at` (once; it measures how long they waited), moves `new` to
`in_progress` and gives an ownerless enquiry to whoever answered; a customer
writing again reopens a `done` enquiry but never a `rejected` one. Every message
announces `enquiries.message.created` AFTER the enquiry is saved.

**What the customer is told is derived from the CONVERSATION, not from
`status`** (`Enquiry::customerState()`): the shop replying leaves an enquiry
`in_progress`, and printing that outward said "waiting for an answer" under the
answer itself. An unread answer is `customer_seen_at` on the enquiry — one stamp,
written down at the base query so reading a page never moves `updated_at` — and
the badge asks about the LAST message, exactly as the row marker does.
`auto_close_hours` closes a thread the SHOP spoke last in; one where the customer
spoke last is backlog and is never closed by age. **Ending a conversation is a
second axis, never a fifth status** — `locked_at` (plus `rejected`, which locks by
itself), answered by `Enquiry::isLocked()` and enforced in `PostMessage` so every
door gets the same answer: a closed thread takes no message from anybody, the shop
included, and reopening is a deliberate act. Over the API a reply is an
ACTION (`actions/reply` → `PostMessage`), never a written message row, and the
queue is `awaiting_reply=1`, never `status=new`.

**An order carries its own key** (`Torgash\Checkout\Orders\OrderKey`, used by
`Order::customerUrl()` / `invoiceUrl()`), and the confirmation page accepts it
beside "your account's" and "placed in this session" — a guest who closed the tab
could otherwise never see their order again, and the order number is sequential
so it can never be the key. It is an HMAC over a canonical string
(`order-key:<route>:<number>`), NOT a Laravel signed URL: a signed URL hashes the
whole address, and every storefront page exists once per locale, so the language
switcher turned a working link into a 404. Do not compute it with
`URL::signedRoute()` either — the url generator rewrites a route name into the
current request's locale shape, which reintroduces the same bug one floor down.
Each page has its own key (the order's does not open the invoice). The link goes
in the order emails, in the reader's own language. A thread can be answered by signature too
(`enquiries.signed_reply`, registered outside StorefrontRoutes because a signature
is one exact address): the signature says WHICH thread, not who — there is still
no stitching by email.

**A module's settings can offer options its manifest.json cannot know** — the
shop's own order statuses, a warehouse, a customer group — by answering
`core.settings.fields`. Applied both on the way to the screen and on the way back
in `SettingsController`, or a ticked box would save nothing.

**A ticket is LISTED in the account and nowhere else** — the list filters on
`user_id`, NEVER on the email (that would hand a guest's conversation to whoever
registers with their address). One thread at a time can be read and answered
without an account, but only behind a key that names it: the order page's signed
link, or `enquiries.signed_reply`. Somebody else's thread answers 404, not 403,
and the customer
is shown a shorter vocabulary than the inbox (`Status::customerLabel()`:
waiting / answered / closed). The menu item comes from the module through
core's `core.account.nav` filter — a LIST of `label/url/icon/active` items, so
core keeps the markup and never learns a module's name; the account sidebar and
the header dropdown both render it.

**Photographs attach to an enquiry and to a message** (`Enquiries\Attachments`,
the one place a file is written, called from `Submit` and `PostMessage` so no
door into a thread can forget one). Four rules, all because this is a public
upload: NO upload endpoint of its own (files ride the POST that was already
happening, so the form's rate limit counts them); images only and through
`ImageProcessor`, which reads the type from the BYTES; the disk is PRIVATE
(`local`) and files are served by `enquiries.file` — staff, the owning account,
or a signature naming that one file, so a guest sees the photo they sent and
nobody else does; and a refused file never costs the message. `attachments_max`
= 0 turns it all off. `ImageProcessor` also refuses bytes that sniff as an image
but cannot be read as one — that used to store fine and then 500 out of the
thumbnail pass, which on a public form threw away everything typed.

`App\Core\Support\FormGuard` is not optional decoration: a public POST that
writes rows is the most reliably abused thing a shop puts online, and an inbox
full of adverts gets the module switched off, which is the same outcome as never
shipping it. Honeypot, an encrypted form-age stamp (too fast was not typed into,
too old is a replay), and a rate limit per IP and per identity, counted per FORM
so asking a question does not use up buying. **No CAPTCHA in core** — a module
adds one by answering `core.form.check` with a message, and `torgash.guard` does.
In CORE because the second such form arrived within the hour: one click writes an
ORDER, and two copies of these rules would drift. What a guarded form carries is
one component, `<x-form-guard form="…">` — the stamp, the trap, and whatever a
module draws into `core.form.fields` — because six lines copied into five
templates is five chances to ship one without them. The form NAMES itself there,
and the names are a registry: `FormGuard::forms()` over the `core.forms` filter,
which is what lets a settings screen offer "ask on this form and not that one".

The three emails (a new enquiry to the shop, the shop's answer to the customer,
the customer's reply back) are sent by the module itself through
`Enquiries\Notify` — the one place that knows whose address is whose — not left
to a rule: an enquiry nobody reads is what this exists to prevent. No address
means no email, never an invented one; the customer's copy carries the enquiry's
own `locale`, set in the mailable's CONSTRUCTOR because `Mailable::send()` reads
`$locale` before it calls `build()`. Everything beyond it —
Telegram, a webhook, a reminder — is an automation off
`enquiries.enquiry.created`. `{callback_link}` in admin-written content points
at the dialog through the `core.contacts.callback` filter, so core still does
not know this module's name and content keeps working when it is uninstalled.

## Returns (torgash.rma)

A return is a KIND of enquiry, and this module is the half an enquiry cannot
hold: which lines, where the goods went, what happened to the money. There is
NO returns screen — the card is on the ticket (`enquiries.admin.extra`, with a
state badge in the list off `enquiries.admin.badge`, primed by
`enquiries.listed`), because a second list of the same rows filtered differently
is the second inbox this whole design refuses. Acting needs `rma.manage`, which
is deliberately not "allowed in the inbox".

**The conversation attached to an ORDER is checkout's, not this module's**
(`Torgash\Checkout\Enquiries\OrderEnquiries`): the `order_question` kind, the
`enquiries.subject.describe` answer for orders, the panel on the order page and
the `checkout.enquiry` route all live there, because checkout owns orders and a
shop that takes no returns still has customers asking where their parcel is.
Returns adds exactly two strings to that panel, through
`checkout.order.enquiry_actions` (its button) and `checkout.order.enquiry_extra`
(a return's state and lines). The dependency is soft both ways — the kind is
registered lazily off `enquiries.kinds`, and the panel asks the route table
(`Route::has('enquiries.signed_reply')`), the same trick as
`PlaceOrder::shippingIsActive()`.

**A return need not start as one.** `RequestReturn::attach()` hangs one on a
ticket that already exists — a question that turned out to be a fault — rather
than creating a second ticket that would split the conversation in half. The
enquiry's kind becomes `return` so the inbox filter finds it; what the person
wrote is untouched, because the answers and their labels are a snapshot on the
row. The shop's OFFER rules (statuses, days, paid-only) gate the CUSTOMER's
button and deliberately do not bind a manager; what binds both is `lines()`,
shared by the two paths, bounding every quantity by what is left of the order.

`Torgash\Rma\Handling` is the one place a return moves — the screen and the API
call the same methods, and `ReturnRequest::allows()` is the one answer to "what
next", so the API can never permit what the card forbids. Receiving is allowed
straight from `requested`: parcels arrive before anybody clicks Approve. Every
act can carry a note, which is posted into the ticket — that is what emails the
customer, and a state badge tells them nothing.

**None of the arithmetic is here.** Money goes through
`Torgash\Checkout\Orders\Refund` — in CHECKOUT, because refunding is not a
returns concept (a cancelled order, an overcharge). It asks
`checkout.order.refund`, which `torgash.payments` answers, and records by hand
when nobody does. **A payment on hold is RELEASED, not transferred** (paying
back money that never left the card costs the shop twice); which call that is
belongs to the gateway, so `Gateway::capabilities()` gained an optional `void`
and checkout only states the fact (`hold`). An order with `paid_total = 0`
refuses rather than inventing a payment, and an amount above what is held is
refused rather than clamped.

Stock goes back through `OrderStock::restore($order, $lines, $warehouseId)`.
The disposition (restock / write off) is a DECISION taken at receipt, not a
setting — a warranty item goes to the manufacturer and must not reappear on the
shelf — and the warehouse is the one it came back TO, listed through the
`catalog.stock.warehouses` hook so this module never names the warehouses
module's classes.

An exchange is a NEW order through `PlaceOrder`, which learned `$as` for it:
everything about identity (user, storefront, locale, charge currency) is
otherwise read from the ambient request, which is right for a customer and wrong
the moment a manager places an order on their behalf. It is priced from today's
catalogue, so the difference is RECORDED (`surcharge`) and never charged.

The order's own status moves through `OrderStatusChanger`, only when nothing is
left of the order to return, and never into a status whose `inventory_action` is
`increase` — this module has already credited the lines, and such a status would
credit the whole order on top.

## The shop's own forms (PLAN §48)

**A form the shop builds is a KIND made of data** — `m_enquiries_forms`, read
per request by `Forms` (a singleton reset with the request, flushed when a form
is saved), and offered by `Kinds::all()/find()` as `form.<id>` beside the kinds
code registers. Never held in `Kinds`' static array: a static outlives the
request. So inbox, statuses, manager, letters, codes (`core.verified_forms`),
automations and the API need nothing new. The kind is the ID, never the code —
an enquiry keeps its kind forever, a code is renamed; for the same reason a form
that has brought enquiries cannot be deleted, only switched off (switched off =
404 on POST, nothing where it was placed, label still readable in the inbox).

Fields (`Form::TYPES`): `name`/`tel`/`email` are the person's own details, keyed
`name`/`phone`/`email` (one each) so they land in the enquiry's columns and the
code-check reads them; a form with neither phone nor email does not demand
"some way to reply" (`contact: none`). `file` is PHOTOS through `Attachments` —
images only, private disk, the module's own limit; a PDF is a different upload
path with its own risks and is not here. Options are a list of {value, label},
matched across languages by POSITION (a translation never mints a value); a tick
box stores `yes`. The consent box is a form setting: required `accepted`, and
its WORDS go into the label snapshot — what was agreed to is kept as it read.

**jsonb sorts keys**, so `fields.order` (the keys in the order asked) is stored
beside the values, and `Enquiry::answers()` reads it; an old row without it
reads as stored.

Placement: the `enquiry` block's kind list is a CLOSURE (forms are rows; boot is
no place to read them), and `{form:code}` is a core placeholder registered with
`block: true` — alone in a paragraph it replaces the `<p>` (a `<div>` inside a
`<p>` is split by the parser). Pages and blog articles expand placeholders;
the embed carries `data-text-skip`, and `Markdown::fromHtml` drops it (and any
form, script, button), so a .md rendition never reads "Your name Phone Send".
`enquiries.forms` is its own permission and its routes are registered BEFORE the
inbox's `/{enquiry}` wildcard.
