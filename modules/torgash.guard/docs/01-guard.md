# Guard

The one place a shop learns about things it would otherwise never hear about.

Planned in parts, each switched on separately — a guard nobody can turn down is
a guard that gets uninstalled. All of them are here: **it collects errors**, **it
records what is changed in the admin**, **it checks that the shop's data still
makes sense — and that nothing executable is lying where the pictures are**, **it
can put a CAPTCHA on a public form**, **it can ask a second factor of the people
who sign in**, and **it can block an address** — that last one starting,
deliberately, by only watching.

## Errors

A 500 on the storefront goes into `storage/logs/laravel.log`, which a shop owner
will never open. As far as the shop is concerned, it did not happen — the
customer saw a broken page, went away, and nobody found out. **Settings →
Guard**, and the list is under **Errors**.

It costs nothing in ordinary life: it only runs on the path where something has
already gone wrong.

### One row per kind, not per occurrence

A thousand identical failures are **one line** saying "1000 times, last seen
three minutes ago", with a sample of the most recent one. That is what makes the
screen readable, and it is also what stops this becoming the largest table in
the database.

What makes two failures "the same":

- an **exception** — its class and where it was thrown. Not its message: the
  message carries the particular ("no query results for model [Order] 41") while
  the class and the line carry the bug;
- **anything else** — the message itself, with runs of digits replaced. "Order
  512 not found" and "Order 4096 not found" are one problem; without that rule
  they would be one row per customer.

### Dealing with one

**I have dealt with this** hides the row. It does not delete it, and the next
time the same thing happens the row comes back onto the list with its count
still rising — a problem that returns has to return, or marking things resolved
is just a way of not looking at them.

### Settings

- **Collect application errors** — off, and nothing is recorded at all.
- **Record from this level** — errors and worse by default. Warnings include
  deprecations and other noise a working shop produces anyway.
- **Ignore anything containing** — one per line, matched against the message and
  the exception's class. For the one noisy thing a shop cannot fix and has
  decided to live with, not for making the list look tidy.
- **Keep errors for, days** — counted from when a group was **last** seen, so a
  problem that is still happening is never pruned. Runs daily, and needs the
  scheduler (see below).

## Audit

Who changed what, and what it was before. **System → Audit**.

It answers questions the engine otherwise cannot answer at all: who put that
price at 1 ₽, who turned stock control off in March, who deleted the category
that used to be there. Every entry carries the person, the time, the field, the
old value and the new one.

### What is recorded, and what is not

Everything that changes **through the admin** — a form on an admin screen, or a
call to `/api/v2/admin/*`, which is the same act performed by a CRM or an agent
rather than a person (the trail names the token's owner).

Nothing else. Not the storefront: it writes carts and orders on every visit, and
a copy of your shop's traffic is not a trail anybody will read. Not the queue or
a console command: nobody clicked, so there is nobody to record.

Two things that are not rows in a table are recorded too, because they are among
the most important: **a setting changing** (only when it really moved — saving a
settings page does not record the forty fields you did not touch) and **a module
being installed, removed, switched on or off**.

### Everything is audited by default

There is no list of watched models. A module somebody else wrote is audited the
day it is installed, without knowing this module exists — which matters most for
exactly those modules, since nothing else is watching them. What is left out is
machinery that changes on its own (queues, sessions, this module's own tables),
plus whatever table names you list in `GUARD_AUDIT_IGNORE` (see below).

### A deletion keeps a copy

For a change, the entry holds the fields that changed, before and after. For a
**deletion** it holds a copy of the row — which is often the only surviving one,
and is the single most useful thing this screen ever shows you.

### Credentials are never written down

A field whose name says credential — a password, a token, a secret, an API key —
is recorded as **changed**, never as a value, and that holds inside a structure
too: a payment gateway's whole configuration is one field with the secret buried
in it, and the secret is masked where it sits. The trail must never become the
second place your keys are written down.

### There is no delete button

Deliberately. A trail an administrator can tidy up from the admin is not a
trail. Entries leave exactly one way — by ageing out after
`GUARD_AUDIT_RETENTION_DAYS` (180 by default) — and that runs on the scheduler
(see below).

### When one click changes thousands of rows

A bulk edit legitimately does. Past `GUARD_AUDIT_MAX_PER_REQUEST` entries (200
by default) the trail stops for that request and says so in an entry of its own,
rather than quietly having a hole in it.

### Its knobs are in the .env, not here

`GUARD_AUDIT` (on/off), `GUARD_AUDIT_RETENTION_DAYS`,
`GUARD_AUDIT_MAX_PER_REQUEST` and `GUARD_AUDIT_IGNORE` (tables, comma
separated). Every one of them can make the trail say less, and on a settings
page the admin whose actions the trail records could blind it from the very
screen it watches. The Audit screen shows what they are set to.

## Invariants

Things that must be true about a shop, checked every night and whenever you
press the button. **System → Invariants**.

Every one is a sentence you can read — "no order has a negative total", "a
product kept in warehouses has a total equal to the sum of them" — and every one
that fails comes with a second sentence saying what to do about it, and a link
to the thing that is wrong. Nothing here is "anomaly detection": a rule nobody
can act on is a rule that gets ignored, and then so are the ones next to it.

### Why this exists

Every bug worth finding leaves data that says so. An order that totalled −20.00,
a stock of −1, a coupon used past its limit — each of those was in the database
for as long as it was wrong, and the only reason nobody knew is that nobody
looked. This looks.

### What is actually watched

The statements come from the modules that own the data, so what you see depends
on what is installed. Today they are about one thing seen from many sides: **the
shop stops working and says nothing.**

* **Delivery.** A method that is offered at no address at all — a method is
  offered in a zone where it has a rate, so one without a rate anywhere is
  invisible however right its own screen looks. A zone that names no country,
  region, town or postcode, and so matches nothing, leaving the rates written
  in it unreachable. A method naming a carrier whose module is no longer there.
* **Payment.** A method wired to a gateway nothing answers for any more, and a
  gateway that says outright it could not take the money — no credential, or a
  currency it does not accept. That second one is the same check the payment
  method's own form runs; here it is made about a shop nobody is looking at,
  which is where a currency changed six months after the gateway was set up.
* **Numbers kept beside the rows they are the sum of.** A product's review
  average and review count against its published reviews, a "was this helpful"
  counter against the votes actually cast, a warehouse total against its rows, a
  coupon's uses against its limit. These drift silently by design: they exist so
  a listing does not have to count, and they are only ever as right as the last
  piece of code that remembered to move them.
* **Rules.** An automation is the one thing in a shop that is supposed to be
  silent, so one that has stopped looks exactly like one with nothing to do. A
  rule with no actions, a rule whose action belongs to a module that has gone,
  a rule waiting for an event nobody announces any more.
* **Orders, the catalogue, returns.** A total that does not add up, a negative
  price or stock, an order marked paid with no money recorded, two products
  offering a search engine the same title, a product page with nothing to say,
  a return claiming more of something than the order held.

Where a finding needs a recomputation rather than a decision, there is a button
next to the data — reviews have **Recount the averages** — because a statement
you can do nothing about is one you read twice and then stop reading.

### Grouped by whoever said it

The statements are grouped under the module that made them, which is also the
module somebody has to go and fix. A sound statement is one line; a broken one
opens into what to do and what it found. The summary at the top is three
numbers — broken, sound, and never checked.

### Every statement is listed, including the sound ones

A screen that shows only problems cannot tell "nothing is wrong" from "nothing
was ever checked", so each row says when it last ran and how long it took. A
check that failed on its own account says that too, in place of a false all
clear.

### What has been put right disappears

An invariant is a state, not an event: it is either true now or it is not. So
there is no "I have dealt with this" here — fix the thing, and the next run
removes it from the list on its own. (The errors screen works the other way
round, and for the opposite reason: an error is something that happened.)

**This one is fine** is for the row that is genuinely allowed to be odd. It
hides that row, never the statement, so the next problem of the same kind still
reaches you — and it goes away by itself the day the row stops being wrong.

### At least this many

A check stops at the limit in the settings and then says "at least N". It also
stops the tidying: a run that did not see everything must not delete findings it
never looked at.

### Uploads: the panel at the top of that screen

Two of the statements are about files rather than data, and they answer the
question "what if somebody uploads a picture with a shell in it".

Most of that is already handled: an image goes through the engine's own
pipeline, which names the file from what the bytes actually are, so a script
pretending to be a photograph lands on disk as a `.jpg` and no web server will
run it. What is left is a file that arrives some OTHER way — an import, a
parser, an archive — and a web server willing to execute whatever it finds.

**The self-test** is the only real evidence: it writes a harmless script where
uploads go, asks your web server for it over HTTP, and reads what comes back. If
the server sends the text, nothing there can run. If it sends the script's
output, it can — and the panel has the configuration to add, for Caddy, nginx
and Apache. A configuration file that looks right is not evidence; press the
button again after changing it. (It is a manual check for a reason: it asks your
shop for a page, so it is not something to do unattended every night.)

**The scan** lists what is already lying in the public directories — an
executable extension anywhere in the name (`photo.php.jpg` counts), or code
inside a file being served to the public. It never deletes anything: a shop
half-migrated from another engine legitimately has that engine's script there,
and deleting it breaks something that works. Look, then decide. The engine's own
files are not listed.

### Adding your own

A module declares what must be true about its own data by answering the
`guard.invariants` filter — the same shape as the other registries in the
engine. It gains no dependency on this module: with the guard uninstalled,
nothing ever asks. That is deliberate, and it is what lets a module written by
somebody else be watched at all.

## CAPTCHA

The last cheap layer under the guards that are always on. **Settings → Guard**,
and nobody is asked anything until you both choose a CAPTCHA and tick at least
one form.

### Per form, on purpose

A CAPTCHA is a tax on every real customer, so it goes on the one form that is
actually being spammed rather than on the shop. The list is the forms your shop
really has — each module names its own, so installing one adds it here and
uninstalling takes it away.

### Who is never asked

- **Staff.** Anybody signed into the admin has proved more about themselves than
  a picture of a sum ever could.
- **Customer groups you trust.** A wholesale buyer placing their fifth order this
  week should not be doing arithmetic.
- **Everybody, if the CAPTCHA cannot actually run.** No GD extension, no site
  key: the setting screen says so in words and the forms keep working. A guard
  that cannot run must fail open — failing closed would take your contact form
  off the air for a reason nobody could see.

### The built-in one

A small sum — "3 + 1 = ?" — drawn as a picture. Digits rather than letters
because a ten-glyph alphabet is harder for a script reading pixels and much
easier for a person than six warped letters. It needs no account anywhere and
sends nothing about your customers to anybody; it needs PHP's GD extension,
which almost every host has.

What it is **not** is protection against a human solver farm or a modern OCR
service — nothing at this price is. It is the last layer under the honeypot, the
form-age stamp and the rate limits, for a form that is being spammed in spite of
them.

Another module can add reCAPTCHA, hCaptcha or Turnstile; it appears in the same
list and everything above (which forms, which exemptions) keeps working
unchanged.

### The answer

The picture is fetched from its own address, and fetching it is what creates the
sum — the answer lives on the server, never in the page. Each answer works
exactly **once** and expires after the minutes you set, which is also why a
mistake always comes back with a **new** picture rather than the same one.

### Placing an order

The order form is on the list too, with two differences. It carries only the
CAPTCHA — not the hidden trap, the form-age stamp or the rate limits the other
forms always have: a browser that fills in every field it finds must not lose
a customer's order in silence, and a fourth order in ten minutes is a good day,
not abuse. And a right answer there is good for one **order**, not one press of
the button: an order sent back for a mistyped street does not ask a second
sum — the box is gone until the order is placed or the answer's minutes run
out.

The shop API (`torgash.api`) places orders too, by the cart's token, and asks
no CAPTCHA: it has its own limit on requests.

## Blocking

The last part, and the only one that can act. **Settings → Guard** switches it
on; the list is under **Blocking**.

It starts in **watch mode**, and that is not caution for its own sake: the
addresses it lists are your own traffic, and nobody knows what that looks like
until they have looked. Leave it watching for a week, read the list, then decide.

### What it counts

Three things, and each is an act with no innocent reading:

- **a wrong password** — on the admin's door and on the customer's;
- **a public form refused** by the shop's own spam guard: the honeypot, a form
  submitted a second after it was drawn, a rate limit, a wrong CAPTCHA;
- **an API token that names nobody.** Only that. A call with *no* token is a
  misconfigured integration and an expired one is yesterday's valid credential —
  a token that never existed is a guess.

One counter per address covering all three, and one threshold: ten refusals in
ten minutes is a sentence you can reason about, four separate thresholds are
not. What kind they were is on the row; it just is not what decides.

### The three rules it will not break

**A request that proves who it is, is never touched.** Twice over: the check
sits in front of the shop's own pages and nowhere else, so 1C, the payment
gateways' callbacks and the API — which are all machines with no session — never
meet it at all; and inside the shop, anybody signed in walks through. Your
manager on the office address that just got blocked keeps working, and so does
the customer halfway through checkout.

**It watches before it acts.** In watch mode every row here is a rehearsal: what
would have been refused and — the useful number — how many requests that would
have been. Nobody is refused anything. Switching enforcement on later does not
apply last week's observations retroactively; new blocks bite, and any older one
you agree with has a **Block for real** button.

**The way back in is a console command.** Locking yourself out of the admin with
a module that lives in the admin is one wrong number away, so the escape hatch
is somewhere the block cannot reach:

    php artisan guard:blocks              # what is standing
    php artisan guard:unblock 203.0.113.9 # let one address in
    php artisan guard:unblock --all --watch

The last one is the emergency: everybody back in, and the module back to
watching, so you can reach your own settings screen and think.

### A private address is never blocked on its own

If the shop sits behind a proxy that has not been added to the application's
trusted proxies, every visitor arrives as `127.0.0.1` or as one address on the
local network — and the first automatic block would shut the whole shop. So an
address that is private or reserved is recorded and never enforced, with a row
that says why. By hand it is allowed, because then somebody has looked.

The **Never block** setting takes addresses and networks (`203.0.113.9`,
`10.0.0.0/8`) — the office, the warehouse, the monitoring service. Those are
not counted at all, so they never even appear here.

### How long

The number you set, multiplied by how many times that address has been back:
coming back after being let in is the clearest evidence there is that the first
block was right. Never longer than a week — an automatic block that outlives the
attack is indistinguishable from a customer who cannot reach the shop. A block
by hand can have no end date at all.

A blocked visitor gets a plain **429** with a `Retry-After`, not a themed page:
booting the storefront to tell a scanner it is not welcome is exactly the cost
this is meant to avoid.

### What it honestly cannot do

It does not stop a flood. A real one dies at the web server, or upstream of it,
long before PHP starts — by the time this code runs the request has already cost
you everything it was going to cost. What it stops is the slow, patient,
small-volume abuse nobody notices: a password being guessed over a week, a form
being milked, a scanner working through token strings.

## Two-factor authentication

A six-digit code from an app on a phone, on top of the password. It is the one
part of this module that stands between a person and the shop rather than
watching or counting, and it is switched on separately from everything else.

**Settings → Guard** has two of them, one per audience, because staff and
customers are different questions:

- **Off** — not offered at all. No Security page, no menu item, nothing to
  explain to somebody who asks what it is.
- **Offered** — each person decides for themselves, *and* it is required of the
  groups ticked in the list under the setting. For staff that list is your
  permission groups; for customers, your customer groups.
- **Required** — of everybody in that audience.

Staff are **offered** it out of the box and customers are **off**. A password is
the whole of the admin panel — the catalogue, the orders, every customer's
address — so offering it to the people who hold that is the sensible default;
an account area that grows a security page nobody asked for is a support
question, and the shop can turn it on the day it wants to.

The group list is read only in the middle mode, where it means "…and for these
it is not optional". That is deliberate: a shop that had chosen "required of
everybody" *and* ticked two groups would have said something that cannot be read
one way.

### Requiring it does not lock anybody out

Somebody who must have a second factor and has none is sent to their own
Security page and nowhere else — the same thirty seconds they would have spent
voluntarily, brought forward. They are not refused, they are enrolled.

What *can* lock a person out is a lost phone, and there are three answers to
that, in order:

1. **The recovery codes.** Eight of them, made at the moment the second factor
   is armed and shown once. Each one signs in exactly once and then no longer
   exists. They go in the same box as the code from the app — there is no
   separate screen to find.
2. **Somebody else in the admin.** The Security page is per person, so a manager
   cannot reset a colleague's from there — but any account can have its own
   turned off from the shell.
3. **The console**, which is the way back when both of the above are gone:

       php artisan guard:two-factor                          # who has one, and what is required
       php artisan guard:two-factor manager@shop.test --off  # take theirs away
       php artisan guard:two-factor --relax                  # stop REQUIRING it

   `--relax` is for the other half of the same emergency: a shop that ticked
   "required of everybody" and then found its one administrator cannot enrol is
   locked out by a setting it can only reach from inside. Nothing is taken away
   without `--off` being asked for, so looking is safe.

### Setting one up

Security → Two-factor authentication → **Set it up** shows a QR code and, beside it,
the same key in groups of four for a phone that will not scan. Nothing is
switched on until a code from the app has been typed back in: an enrolment
nobody finished costs nothing and stops nobody signing in, so a camera that will
not focus is not a locked door.

Turning it off asks for the password, and so does replacing the recovery codes —
both of those weaken the account. Turning it *on* does not: the person is
already signed in and is making themselves harder to impersonate.

### What it does not do

- **No "trust this device for 30 days".** It asks once per session and never
  again inside it; a remembered login picked up next month is a new session and
  is asked again.
- **No code by email or SMS.** An emailed code makes the second factor exactly
  as strong as the mailbox the password reset already goes to, which is to say
  it is not a second factor at all.
- **It does not touch machines.** 1C, the payment callbacks and the API are
  registered outside the shop's own request group and take no session, so a
  token-authenticated integration is unaffected by anybody's phone — including
  when the account behind that token is one the shop requires a second factor
  of. This is the same arrangement as blocking, and for the same reason.
- **It adds nothing to the `users` table.** Everything lives in this module's
  own table, so uninstalling the guard takes the secrets with it.

One honest limit: rotating `APP_KEY` makes every stored secret and every
recovery code unreadable, because both are encrypted with it. That also
invalidates every session and every "remember me" cookie in the shop, so it is
already a deliberate act — but if you do it, expect to run
`php artisan guard:two-factor --all --off` afterwards and have everybody enrol
again.
## What it deliberately does not do

- **It does not duplicate the spam guard.** Public forms are already covered by
  core's `FormGuard` — honeypot, form-age stamp, rate limits per IP and per
  identity. This module may only *add* a check through the `core.form.check`
  hook that exists for exactly that — which is exactly what the CAPTCHA is.
- **It does not touch a request that presented credentials.** Not as a setting
  and not as a list of exceptions: 1C, the payment callbacks and the API are
  registered outside the shop's own request group and the check is only in front
  of that group, so it cannot reach them even by mistake — and a signed-in
  session walks through the front door.
- **It does not patch the application skeleton.** Errors are collected by
  listening to `MessageLogged`, so uninstalling the module leaves nothing behind
  to undo — and a `Log::error()` somebody wrote by hand inside a queued job is
  caught too, which an exception handler would have missed.
- **It never makes a bad moment worse.** Recording is wrapped so it cannot
  throw, and it cannot re-enter: the ordinary reason a write fails here is that
  the database is unavailable, and *that* failure is logged, which would arrive
  back here and try the same write — an infinite loop on the one path where the
  shop is already in trouble.

## The scheduler

Pruning runs through the shop's scheduler, which needs `php artisan
schedule:run` firing every minute (a Task Scheduler entry on Windows). Without
it nothing is pruned and the table grows for ever; everything else works.
