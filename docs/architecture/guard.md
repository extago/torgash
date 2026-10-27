# Guard (torgash.guard)

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

## Guard (torgash.guard)

The one place a shop learns what it would otherwise never hear. Planned in parts
(PLAN §25.2), each switched on separately; the first one is here.

**Errors** are collected by listening to `MessageLogged` — a LISTENER, never a
patch of `bootstrap/app.php`, which is what keeps it a module and also catches a
hand-written `Log::error()` inside a job. One row per KIND of failure with a
counter, never one per occurrence: an exception is fingerprinted by class + file
+ line (not its message — that carries the particular, the line carries the bug),
anything else by its message with digit runs replaced. Counted atomically with
`insert … on conflict do update set count = count + 1`, the only raw SQL in the
module, because `updateOrCreate` races. **Recording never throws and never
re-enters**: the ordinary reason it fails is the database being down, and that
failure is logged, which arrives back here — an infinite loop on the one path
where the shop is already in trouble. The stored context is a sample of the LAST
occurrence and never the exception object (models, closures, a whole container).
"Dealt with" hides a row and a fresh occurrence un-hides it.

**The audit** is the second part and the premise of the rest: an anomaly cannot
be noticed in actions nobody writes down. It listens to four NAMED Eloquent
events (never `eloquent.*` — that catches `retrieved`, which fires for every row
the admin hydrates), and it is armed by an authenticated request under `/admin`
or `/api/v2/admin` and nowhere else — the storefront's writes are the shop's
traffic, and a console command or a queue worker has no actor. Nothing in it
mentions the console: their request path is `/`, so one check covers both.
The one exception is a background task SOMEBODY STARTED (`TrackedJob` — a
renumbering, an import): core's `App\Core\Tasks\CurrentTask` says which task
is running, and its writes are recorded as an act of the task's `user_id`,
with `task_id`/`task_name` on the row and their own cap
(`guard.audit.max_per_task`, 200 000 — a task exists to change many rows). A
task nobody started (the scheduler) still writes nothing. The audit screen
lists one task's rows (`?task=`); putting a whole task back is queued (PLAN §56). It
audits EVERYTHING minus an ignore list — the inverse of display rules'
fail-closed and the safe direction here, because a module somebody else wrote is
the one nothing else is watching. The actor is kept BOTH ways (`user_id` plus a
snapshot of the name, no foreign key: deleting a manager may not delete the
record of what they did), a deletion stores a copy of the row, and a value whose
name says credential is stored masked — INSIDE a structure too, since a
gateway's whole configuration is one `config` jsonb with the secret in it. One
click can legitimately change thousands of rows, so past `GUARD_AUDIT_MAX_PER_REQUEST`
the trail stops and SAYS so in a `truncated` row rather than quietly having a
hole. The screen has no delete button by design; rows leave by ageing out.
**Its knobs are in the .env, never the settings page** (`GUARD_AUDIT`,
`GUARD_AUDIT_RETENTION_DAYS`, `GUARD_AUDIT_MAX_PER_REQUEST`,
`GUARD_AUDIT_IGNORE`; `config('guard.audit.*')`, merged from the module's
`config/audit.php`): each of them can only make the trail say less, and on a
settings page the admin it records could set the retention to one day and be
gone from it by morning. The audit screen prints them read only.
A table that is ALREADY a log (an order's history, a rule's runs, an exchange
session) is declared by its owner through `guard.audit.ignored_tables` — a log
recorded again is the same event twice. The screen names a kind in words: the
class name as a phrase through the owner's lang file, or the model's own
`public static function auditLabel()` where the bare word means something else
in the shared table (`Block` is a verb there); neighbouring entries of one act
(same row, person, action, minute) fold into one line.
**Putting a change back** (`Torgash\Guard\Audit\Revert`, PLAN §56, its
own permission `guard.revert` — reading who changed a price is not the right
to change it back) refuses rather than guesses, per field: only an `updated`
entry, never a creation or deletion (a deleted product's row comes back
without the pictures, prices and categories that went with it); only a model
whose owner says `public static function auditRevertible(): bool` (content;
an order, a payment or a stock row moves through its own doors and a copied
column would skip them) or a setting; only a value the trail kept WHOLE (not
cut at `MAX_VALUE`, not `MASK`ed); only while the field still holds what
the change left (`Auditor::value()` puts the current value into the trail's
shape to compare). It writes through the model's `save()` /
`SettingsManager::set()`, so the revert is itself an entry, and the screen
says which fields were left and why. The trail itself is still never edited.
**A whole background task** is put back by `Jobs\RevertTask` (the button on
the audit filtered `?task=`): every entry of that `task_id`, newest first,
through the same `Revert`, itself a tracked task (so its writes are the
presser's, under its own task id) with a result of counts — put back,
changed since, value taken, not a change. A UNIQUE value (a renumbering hands
SKU-000001 from one product to another) cannot be taken back one row at a
time: those entries wait to the end, the text values they hold are parked
(`~~` prefix, raw), each is tried again with `Revert::apply($entry, $parked)`
reading the real value, and whatever is still parked goes back — all in one
transaction, so a value that cannot return where it was undoes the step and
those entries count as "taken". A value somebody outside the task holds now
stays theirs.

Settings and modules are plain tables with no Eloquent under them, so core
announces them itself — `core.setting.changed` from `SettingsManager` (only when
the value really MOVED, compared loosely: a settings page saves every field, and
`"1"` is not a change from `1`) and `core.module.changed` from `ModuleManager`.

**The invariants** are the third part: statements about the shop that must be
true, checked nightly and on a button. The engine is here; WHO SAYS what must be
true is whoever owns the data — checkout about an order, warehouses about a
stock row, promotions about a counter, catalog about a price, each answering
`guard.invariants`, which costs them nothing when this module is absent and
gives them no dependency on it. The guard itself ships only the statements about
CORE's data, which has no module to speak for it. An invariant is a STATE, not
an event: what has been put right disappears from the screen by itself (the
opposite of the errors table beside it) — but only when the run actually saw
everything, since a run that stopped at its limit knows nothing beyond it and is
marked `truncated` instead. A second table holds when each check last ran, what
it cost and whether it threw, because "sound" and "never looked" must not be the
same empty screen. Every check is bounded by a limit the engine hands it; one
that cannot be a single query bounds its own scan and says so in its sentence
(checkout's arithmetic check reads the most recent 1000 orders and compares
against `OrderTotals` itself, so there is no second formula to drift). The run
is synchronous — a button needing a queue worker does nothing on most installs.
"This one is fine" hangs on the FINDING, not the statement, and goes when the
finding does. A finding's words are stored as a translation KEY plus params, the
rule background tasks already live by: the nightly run speaks the app's default
language and the reader may not.

**What the statements are ABOUT is one thing seen from many sides: the shop
stops working and says nothing.** A delivery method with no rate in any live
zone is offered at no address at all (the zone gate — `ShippingOptions` starts
from `ShippingRate`, so a rate is what PERMITS a method in a zone even when a
carrier prices it), a zone with no rules matches nothing, a payment method is
bound to a gateway key whose module has gone, a rule waits for a hook nobody
announces or calls an action nobody answers for, a denormalised counter (a
review average, a warehouse total, a coupon's uses) drifts from the rows it is
the sum of, a return claims more than the order holds. Every one of those
leaves every admin screen looking correct, because each screen owns one half of
a pair and nothing owns the JOIN — which is exactly the question nobody is
asked. A statement that duplicates a form's own warning shares ONE class with
it (`Payments\Readiness`), never a second copy of the comparison: a screen and
a watch that disagree about whether the shop can be paid is worse than neither.
And a statement the shop can do nothing about gets read twice and then not at
all, so a finding that needs a recomputation gets a button beside the data
(reviews' "Recount the averages"), not an instruction.

**The screen groups by whoever made the statement** — the key's own prefix,
which IS the owner, because the owner of the data is who states it; the heading
is that module's `name` through `__()`, so the translation lives in the
module's own lang file. A sound statement is ONE LINE, a broken one opens into
its explanation and its findings, and "what to do about it" is printed only
where something is actually wrong. The summary is three numbers and "never
checked" is one of them, for the same reason the sound ones are listed at all.

**An executable file where the pictures are** (§26.3) is two more invariants
rather than a screen of its own. The upload path is already safe by NAME
(`ImageProcessor` takes the extension from the sniffed mime), so what is left is
a file written PAST it and a server that runs `*.php` wherever it finds one — so
the answers are a SELF-TEST (write a harmless `.php` into the uploads
directory, ask the web server for it over HTTP, read what comes back; a second
probe carries a double extension; the probe is removed in a `finally`) and a
scan (name checked per SEGMENT, PHP tags inside, media content never read, and
NOTHING is ever deleted — a shop mid-migration has the old engine's script
there). The configuration is printed, never written: under OSPanel the Caddyfile
is generated. The engine's own files are exempt and a module declaring one adds
it through `guard.files.expected` (CommerceML does, and only while the file is
still ours). The self-test is declared `manual` — the invariants engine's one
new idea: a check the nightly sweep neither runs nor tidies up after, because it
asks the shop for a page over HTTP.

**Blocking** is the fourth part and the only one that ACTS, so it is three rules
rather than settings. **A request that presented valid credentials is never
touched** — expressed as a PLACE, not a list of paths: the check
(`Blocking\CheckBlock`) is appended to the `web` group and nowhere else, and
everything credentialed is registered outside it already (`/commerceml`, the
gateways' callbacks, `/api/v2/*` — a machine has no session), so a gateway
written next year is exempt without hearing of this module; inside `web`, a
signed-in request walks through. **It watches first** (`blocking_mode` =
`watch`): every row is written exactly as it would be and counts the requests it
would have refused, and the mode is stamped on the ROW, so switching enforcement
on never applies last week's observations retroactively. **The way out is
`php artisan guard:unblock [--all --watch]`** — the screen lives in the admin,
which is behind the door this can close. A fourth rule came from writing it: a
private or reserved address is never blocked AUTOMATICALLY, because a shop
behind an untrusted proxy sees every visitor as one address and the first
automatic block would shut the whole shop. Three signals, all somebody else's
announcement (`Failed`, core's new `core.form.refused`, the API's
`api.auth.failed` — a token that names NOBODY, never a missing or expired one),
counted into ONE counter per address against ONE threshold. One row per address
with `strikes`/`signals`/`hits`, never one per incident: a row-per-incident
table is biggest exactly when the shop is under attack. Refused with 429 +
`Retry-After` and no rendered page. Honest limit, stated in the docblock and on
the screen: PHP does not stop a flood (that dies at Caddy) — it stops slow
password guessing, form abuse and token scanning.

It does NOT duplicate `FormGuard`: core owns honeypot, form-age stamp and rate
limits, and this module may only ADD a check through `core.form.check` — and
HEAR about a refusal through `core.form.refused`, which is a fact only core has.

**The CAPTCHA is that check.** A provider (`Torgash\Guard\Captcha\Provider` +
the `guard.captcha.providers` registry, the same shape as payment gateways), so
the built-in one is not privileged and a Turnstile module replaces it by being
chosen in a setting. The built-in one draws a small sum as a PNG: the alphabet is
digits and three signs rather than letters, which is harder for a naive OCR bot
per unit of distortion and far kinder to read, and no font file is bundled.
**Fetching the image is what creates the challenge** — the answer goes to the
session and never into the page — keyed by form AND nonce (one page can carry two
guarded forms), good exactly once, expiring, and capped per form so a script
cannot grow a session file. `Captcha\Gate` owns who is asked: only the forms the
shop ticked, never staff, never a trusted customer group, and NOBODY when the
provider cannot run (`unavailable()`) — a guard that cannot work fails OPEN and
says so on its settings screen. Both halves ask the same gate, so a visitor who
was never shown the box is never refused for leaving it empty.

**The checkout takes the CAPTCHA and nothing else.** It is a form in
`core.forms` (`checkout`, "Placing an order") checked by
`FormGuard::checkAdded()` — `core.form.check` alone, never the stamp, trap or
rate limits: a theme drawing the page without the stamp would refuse every
order, an autofill landing in the trap would drop one in silence, and a fourth
order in ten minutes is a good day. Drawn by `<x-form-guard form="checkout"
added-only box="…" />` — `box` frames what modules add in a card and draws no
card when they add nothing. It asks the check to `keep` a right answer:
`Gate` remembers it in the session (`guard.captcha_kept.<form>`, no longer
than `captcha_ttl`) and stops asking, so an order refused for anything
else — a missing field, stock, the code check — does not cost a second sum;
`FormGuard::accepted()` (`core.form.accepted`) spends it when the order is
placed. One sum per ORDER, not per press (`CheckoutCaptchaTest`). The shop
API's `checkout/complete` asks none: the cart's token and
`throttle:torgash-api` are its guard.

**The second factor is a PLACE, not a login form.** TOTP lives entirely in this
module — `m_guard_two_factor`, never columns on `users`, so uninstalling takes
the secrets with it — and the check is `TwoFactor\RequireTwoFactor` appended to
the `web` group exactly as `CheckBlock` is. Intercepting `Auth::attempt` was the
alternative and is wrong here: there are two login controllers in core and a
module may add a third, while everything credentialed is registered OUTSIDE
`web` and takes no session, so one middleware covers every way of becoming
logged in (a remembered cookie next month included) and reaches no machine. The
price is stated in its docblock: between password and code the session IS
authenticated, so it lets through exactly the code box, its POST, logout, a
module asset and — only when the shop is insisting on enrolment — the Security
page. `Requirement` answers who is asked: per audience, `off` / `offered` /
`required`, and the group list (permission groups for staff, customer groups for
customers) is read ONLY in `offered`, where it means "…and of these it is not
optional" — the two would otherwise contradict each other on screen. Requiring
it never locks anybody out: no record plus required sends them to enrol, not to
a refusal. What locks people out is a lost phone, hence recovery codes minted
when it is ARMED (not when the QR is drawn — an unfinished enrolment must stop
nobody) and `php artisan guard:two-factor [email] [--off|--all|--relax]`, the
same rule as `guard:unblock`. `Enrolment` is the one place a row is written, and
`last_timestep` is what makes a code single-use — without it six digits are a
password valid for the rest of their minute. `Totp` is ours (RFC 6238 is sixty
lines with published vectors, and they are in the test suite); the QR is
`bacon/bacon-qr-code` (BSD-2) as SVG, because drawing one by hand is a fortnight
with a subtle bug in it. Both Security pages are one controller
(`Http\SecurityScreen`) reached through two MENU hooks — `core.account.nav` and
`core.admin.user_nav`, the latter added with this: a LIST of items in core's
own dropdown markup, for things about the PERSON, never a section of the shop.
**Wrong codes are counted twice** (audit 2026-10-15): per SESSION
(`Stamp::MAX_MISSES`, five, then the password is taken back) and per ACCOUNT
(`TwoFactor\Attempts`, ten an hour, whatever the session). The first alone was
a limit that waited — whoever holds the password signs in again for five more,
as often as they like. Past the account's count even the right code is not read,
and every wrong one is announced as Laravel's `Failed`, which is what the
blocking counts per address.
**A refused public form answers HTMX with the form again and a 200**, never a
4xx: HTMX does not swap a 4xx at all, so the honest 422 meant the customer
pressed the button and saw nothing happen. Typed values are passed to the view
explicitly — there was no redirect, so `old()` is empty, and a wrong sum must not
cost somebody the question they wrote.
