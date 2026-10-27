# Personal data

This module does three jobs: it holds counters and advertising code back until
a visitor agrees, it writes down every consent, and it answers a customer who
asks what the shop keeps about them or wants it erased.

It is a tool, not legal advice. The law of your market also asks for things no
software does: the text of your privacy policy, notifying the regulator (Russia
keeps a register of operators), where your server stands.

## Whose law

Personal data → Settings → **Whose law**. Choosing one fills in the rest:

| | Cookies and counters | Answer a request within |
|---|---|---|
| European Union | ask first | 30 days |
| United States | allowed until refused; the browser's privacy signal holds ads back | 45 days |
| Russia | ask first; link a separate consent document | 14 days |
| Ukraine | ask first | 30 days |

Change anything after the preset filled it in, and press Save.

## Counters, pixels, chats

Everything from another service goes through **Snippets**, and each snippet has
a category:

- **Necessary** — runs always. Your own code that talks to no other service.
- **Functional** — a support chat, maps, a reviews widget.
- **Statistics** — Google Analytics, Yandex Metrica, Hotjar.
- **Marketing** — Facebook, TikTok, Google Ads pixels.

Until the visitor agrees, a snippet of that category waits on the page doing
nothing; the moment they press "Accept", it runs — on the same page. The
Settings screen lists your snippets and warns when the addresses in one look
like statistics or marketing but it is marked necessary.

A chat can be set to **load on click**: the page shows your own button, and the
chat's script is fetched only when it is pressed — pressing it is the visitor
asking for it.

To let visitors change their mind later, put a link to `#cookie-settings` in the
footer menu or on the policy page.

## Requests

A customer opens **My data** in their account: they can download everything
about their account at once, and ask for it to be erased. A person who wrote or
called is entered on **Requests → New request** by email or phone.

Erasing is done by a manager, because a person may have an unpaid order or an
open return. It removes the account, addresses, wishlist and saved basket,
empties enquiries, takes the name off reviews and comments — and keeps
**orders**, which the law asks a shop to keep for its accounts, without their IP
address and browser. Orders also live in 1C if you send them there; the engine
cannot reach it, check it by hand.

## How long it is kept

IP addresses in orders, in the audit trail and in the record of consents, and
verification codes, are cleared every night once older than the days set on the
Settings screen. 0 keeps them.
