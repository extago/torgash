# Personal data (torgash.privacy)

PLAN §49. Read before touching consent, snippets' categories, or anything a
module keeps about a person.

**Core owns the vocabulary, a module owns the policy.** `App\Core\Privacy\Consent`
holds the four categories (necessary / functional / statistics / marketing) and
asks `core.consent.allows` — answered by nobody, everything is allowed, which is
the shop as it was. `Consent::given()` announces an agreement
(`core.consent.given`); keeping the record is the privacy module's job. Neither
`torgash.snippets` nor `torgash.privacy` names the other's classes: snippets
asks core whether a category may run and listens for the DOM event
`torgash:consent`; the banner fires that event and has never heard of snippets.

**Third-party code waits inside a `<template>`.** A snippet whose category is
not allowed is printed as `<template data-consent="statistics">…</template>` —
the browser parses it and runs nothing — and `snippets/assets/consent.js`
re-creates its scripts on consent, on the same page, without a reload (a script
moved out of a template as it is never runs). Printed the same way for every
visitor, so the page does not depend on whose it is; on the next page the server
prints allowed code plainly. "Load on click" snippets wait in the FOOTER behind
their own button (`click-button` view) whatever their placement. Only code
added through Snippets is gated: our own scripts need no consent, and code
pasted into a theme or an HTML block is invisible to this — third-party code
goes through Snippets, that is the rule.

**Google Consent Mode v2** defaults go into `core.head` at priority 1, before
any snippet (they listen at 10), from the same `ConsentState`; the banner sends
`gtag('consent','update')`.

**`ConsentState`** (singleton forgotten per request — one visitor's choice must
never reach the next under a worker) reads the `torgash_consent` cookie
(`1:statistics,marketing`; a different version = no choice, how a shop asks
everybody again). Modes: `opt_in` (EU/RU/UA: nothing optional until chosen; the
banner shows), `opt_out` (US: allowed until refused, no banner, the `Sec-GPC`
header holds MARKETING back when `respect_gpc`), `off`. A cookie wall is not an
option: "only the necessary" never blocks buying. `#cookie-settings` anywhere
reopens the choice (also the `{cookie_settings_link}` placeholder).

**Who keeps what is a registry, `App\Core\Privacy\PersonalData`**
(`core.personal_data`, asked lazily once). Each module registers ITS rows:
`export(Subject)`, `erase(Subject)`, `keep` (why some stays), `retention(before)`
+ `default_days`. Registered today: core (account, addresses, verification
codes), checkout (orders: export; erase strips IP and `client` only — kept for
the books and they are in 1C too; `order_ip` retention), enquiries (contacts,
answers, the customer's messages and photos emptied, the row kept — a return's
refund hangs on it), reviews / comments (name → "Customer", link to the account
cut), wishlist and cart (deleted), guard (`guard_ip` retention), privacy itself
(consents, `consent_ip` retention, requests). Mass updates, not saves: this is
housekeeping, not a change TO an order.

**`Subject`** is the account plus the contacts that may be trusted: a customer's
own download is the ACCOUNT's rows only (a guest order under the same email is
not provably theirs — the inbox rule); an erase request from the account carries
the email/phone only if confirmed; a manager opening one by email/phone vouches
for it. Phones compare by their last nine digits. Nothing to match on matches
nothing. A staff account is refused — closed on the Administrators screen.

**Requests** (`m_privacy_requests`) keep name and contacts as written, because
erasing deletes the account they pointed at; `report` is what each owner did.
The account goes LAST, through the model, so every `core.user.deleting` runs.

**Retention** is days per registry entry in the `retention` setting (a preset
fills it; 0 keeps), cleared nightly by `privacy:retention` (`core.schedule`).
Presets (`Presets`) only FILL the settings form — never a lock, and never legal
advice: the policy text, the regulator, where the server stands are not code.
