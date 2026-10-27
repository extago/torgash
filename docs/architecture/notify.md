# Verification codes, phones, letters

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- **A code that proves an address is core's, and ONE place issues it**
  (`App\Core\Verification\Verifier`, table `verification_codes`, PLAN §44):
  6 digits, 10 minutes, 5 attempts (a wrong one fires `Auth\Events\Failed`,
  which the guard's blocking counts), bound to the BROWSER that asked (a
  session token hashed on the row) — while the letter's own button carries the
  code and works on any device. Re-sending waits 60 s, doubling to 10 min;
  hourly ceilings per address and per IP. Only a hash is stored. A PURPOSE
  says what it is for (`core.verification.purposes`); a CHANNEL is how it
  travels (`core.verification.channels`, `Channel` + `SendResult`): core ships
  email (sent NOW, not queued, with an MX check first), and a channel answers
  at once `sent` / `invalid_address` (told to the customer — they typed
  nonsense) / `unreachable` (offer another channel) / `temporary`. Getting
  back into an account (`/account/code?for=password_reset`, always on) and
  signing in by code (`sign_in`, core setting `sign_in_by_code`, off) are the
  same act; customers only — staff sign in through the admin and its second
  factor. The rows are the log on Admin → Customers → Verification codes.
  **Every other channel is ONE kind: an HTTP request the shop composed**
  (`torgash.automations`, `m_automations_code_channels`, Admin → Customers →
  Code delivery, key `http:<id>`), never a module per gateway: a PRESET
  (TurboSMS, Telegram Gateway, blank — `Codes\Presets`) only fills the form
  and is forgotten. Placeholders `{{ phone }}` `{{ phone_plus }}` `{{ code }}`
  `{{ text }}` (core's whole message, `Verifier::text()`, in the reader's
  language) and `{{ secret.<name> }}` — secrets are an encrypted column,
  never drawn back (blank keeps), and masked out of every error kept. The
  answer is read by the row's rules (`path = a|b`: success ALL, invalid /
  unreachable ANY; non-2xx is never success). Refused before sending: a
  private host (the webhooks' `Ssrf`), a country outside `prefixes`, a day
  past `daily_limit` (counted from `verification_codes.sends`). "Check the
  connection" is the row's free GET (a balance); "Send a test code" goes
  through the real Verifier (purpose `channel_test`), limits and log included.
  The day budget counts only what the provider did not refuse outright.
- **A phone is read by its own country's rules** (`App\Core\Support\Phones`,
  libphonenumber-for-php-lite, Apache-2.0) — never a length check, never
  Ukraine assumed. Stored E.164 plus its COUNTRY (`users.phone` /
  `phone_country`), because a calling code is not a country (+1, +7). A number
  typed without its plus is read in the visitor's town's country
  (`core.visitor.country`, answered by locations), else core's
  `default_country`, else it must carry its code. A landline takes no SMS
  (`mobile`), and a code channel names COUNTRIES (`UA PL`), not codes. An
  account's email and phone change only through a code sent to the NEW one
  (`Account\ContactController`; the profile form takes the name alone), the
  old email is told, and a phone is written only once proven — so a stored
  phone signs in (password or code) and restores access. The phone row is
  drawn only while some channel can carry a code to a phone
  (`Channels::offers('phone')`). **Which contact an account must have is the shop's setting**
(`core.account_contact`: `email` — the default, what a shop without an SMS
provider keeps —, `phone`, `either`, `both`), read only through
`App\Core\Access\AccountContact` (PLAN §44, 3b). `users.email` is NULLABLE
and never filled with an invented address (letters would go to it, exports
would carry it, and "has an email" would become a rule every reader must
remember); `Letters::send` answers "not sent" for no address. A phone on an
account is proven by a code on the form that puts it there — registration
uses the always-on verified form `account.phone`, whatever `verify_forms`
says — and the phone choices fall back to `email` while no channel offers a
phone. Registration and the admin's customer form ask what the setting says
(a phone the shop types counts as proven: it vouches for it); the profile
lets go of an email or a phone only while what is left still satisfies the
setting and still signs in (`removalRefusal()`). Display an account as
`AccountContact::label($user)` (email, else phone), never `$user->email`.
An ORDER has its own rule, checkout's `order_contact` (same four words,
`email` by default; `PlaceOrder::orderContact()` / `contactRequired()`):
with `phone` a guest orders with a number alone — no email, no letter, no
code unless `verify_forms` includes checkout — and a phone that must do
the job has to parse (`Phones`). A signed-in customer needs any one
contact (`either`), because an account may have no email.
- **Every letter is an EVENT in core's registry, written in one frame**
  (`App\Core\Mail`, PLAN §41): a module answers `core.mail.events` with
  `key => [label, group, audience customer|shop, required?, subject, heading,
  body, button{label,url}, tokens(ctx), blocks(ctx), help, sample(), recipients()]`
  and sends with `Letters::send($event, $to, $context, $locale)` (queued;
  `now: true` for a code) or `Letters::toShop()`. The default words are
  English SOURCE strings with `{tokens}`, translated in the READER's language;
  what the shop types (`mail_texts`, Admin → Letters) replaces them only in
  the language it was typed in (`MailText::own()` — never another language's).
  Words are plain text: tokens escaped, a blank line a paragraph, a BLOCK
  token alone on its line drawn as the module's own HTML (an order's lines).
  The frame (colour, logo, footer) is core's `mail_*` settings. `EventMail`
  carries models as references, fetched fresh when written. A per-status
  letter is `checkout.order_status.<code>`, so each status has its words and
  its own off switch. Never write a new `Mailable` with its own markup.
  An event may also say `reply_to(ctx)` (a letter to the shop about a
  customer replies to the customer) and `append(ctx)` — HTML the shop cannot
  edit away (a wishlist's "stop these letters"). A shop letter's addresses
  are its `recipients()` default (a module's notify_email) unless typed on
  its page; `checkout.new_order` has none and goes nowhere until one is.
  The mail SERVER may be typed on Admin → Letters (`MailServer`, password
  encrypted with the app key, applied when the mailer is first built; off =
  .env). A password is never in a letter: `App\Core\Access\PasswordLinks`
  sends a link — Laravel reset tokens, `users` broker (an hour) for a lost
  admin password (`/admin/password/forgot`, the same answer for anybody,
  setting it does NOT sign in, the second factor follows), `invites` broker
  (a week) for a new manager or a customer made in the admin
  (`/account/password/set/{token}` signs a customer in).
  **A letter is drawn in the THEME of the storefront it is about**
  (`Letters::compose` binds that storefront and `setActive`s its theme for the
  duration, found from the context's first `storefront_id`), so a worker with
  no request still honours a theme's `views/mail/frame.blade.php` or a
  module block it overrides (`views/modules/<id>/mail/…`) — that override is
  the way to re-lay a letter; there is deliberately no letter BUILDER. The
  frame also takes `mail_background`, `mail_topline` (beside the logo) and a
  per-letter `preheader` (empty = the heading).
  An event with `editable: false` takes the frame, theme and domain but its
  words are its sender's own — it has no page on Admin → Letters. The
  newsletter's campaigns are one (`newsletter.campaign`, PLAN §57.4): a
  campaign's letter is composed per delivery through `Letters::compose`,
  sent as a thin `CampaignMail` carrying List-Unsubscribe / one-click
  headers, its links through `/newsletter/c/{token}/{link}` — which redirects
  ONLY to a link stored for that campaign, never to what the request asks.
