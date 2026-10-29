# Torgash

*Торгаш* (TOR-gash), Russian for "a huckster": someone who will sell you
anything. This one sells your things, and it does not cut corners doing it.

Torgash is a free, open e-commerce engine for people who have run a shop on
something older and kept a list of everything that annoyed them. One archive
is the whole shop: unzip it, run the installer in a browser, and you have a
catalogue, a basket, checkout, delivery and payments, an admin that works on
a phone, and a demo shop to look at while your own goods are not in yet.

**Installing a shop:** take `torgash-X.Y.Z-install.zip` from
[Releases](https://github.com/extago/torgash/releases) and follow the
`INSTALL.md` inside it (`INSTALL.ru.md` по-русски). The documentation lives
at [extago.com](https://extago.com/solutions/torgash/docs/).

## What it is like to run

- **The admin is one place, and it reads the same everywhere.** Every list
  has the same head, every form the same bar, every button and badge the
  same meaning. It fits a phone: nothing scrolls sideways, lists turn into
  cards, controls grow under a finger. A manager can place an order for a
  customer who rang, with their own prices.
- **Content is translatable from the first day.** An admin form edits one
  language at a time, so saving the Russian text never wipes the Ukrainian.
  The storefront, the admin and every module ship in English, Russian and
  Ukrainian.
- **A broken edit costs a block, not the shop.** A part of a page that fails
  is left out and staff see why; the shop can be closed to visitors while
  you work; a safe mode switches the shop's own code off; a change of a
  product, a page or a setting is put back from the audit log.
- **Updates are signed packages with a way back.** The shop learns of a new
  version itself and installs it on your click, backing the database up
  first. They are published on GitHub Releases too, for shops that can only
  reach GitHub.
- **It is quick.** The engine runs under FrankenPHP's worker mode (Laravel
  Octane): the application boots once, then serves request after request,
  and guests are served whole pages kept ready. On a live shop that took the
  admin sign-in page from about 85 ms to about 13 ms.

## What's inside

**The stack is deliberately boring.** Laravel 13 on PHP 8.5, PostgreSQL,
server-rendered Blade with HTMX and Alpine, Tailwind and daisyUI. There is no
SPA and no Node: the shop's stylesheet is built on the server, and
`vendor/` ships inside the release.

**53 modules, one distribution.** Catalogue with options, kits, variations
and characteristics bound to categories; cart, checkout, promotions, gift
cards, bonus points, taxes; shipping with zones and carriers (Nova Poshta,
CDEK); payments (Stripe, LiqPay, monobank, WayForPay); warehouses and an
inventory journal with FIFO costing and an order's profit; reviews, wishlists,
comparison and live search that understands typos; tickets, returns and
your own forms; automations; blog, pages, banners and sliders; SEO with
templates and an audit; 1C/CommerceML exchange, import and export, an API;
personal data tools; a guard with an audit log you can undo from. A module
made for one country says so, and the installer picks the ones that fit your
shop. Install or remove a module and nothing is left behind.

**Some opinions it holds:**

- Money is integer minor units, always in the shop's own currency. A price
  typed in another currency is kept as typed and recalculated when the rate
  moves.
- Weights and sizes are whole grams and millimetres. "Not measured" is a
  different answer from zero, and shipping treats it that way.
- An order is created in exactly one place, whoever asks for it: the
  checkout, the API, a manager, a return's exchange.
- Text written by staff is sanitised before it is printed, every script
  carries a CSP nonce, and admin sign-in supports a second factor. The right
  to edit a product description is not the right to run JavaScript as the
  owner.
- Russian search works properly: the database is created with an ICU locale,
  because under the C locale Postgres quietly stops treating Cyrillic as
  letters.

## Status

Young, but real. The first commit was in August 2026. A live shop runs it on
Debian with FrankenPHP, PostgreSQL 18 and Valkey, and the bugs it finds go
straight back into the engine. Every release is installed on a copy of a
shop before it is published.

## Built with Claude

Torgash was designed and written by its author in close collaboration with
[Claude](https://claude.com/claude-code) from [Anthropic](https://www.anthropic.com).
That covered roughly six hundred commits: architecture debates, digging
through how other shops do it, hunting N+1 queries, Blade parser traps
(`docs/LESSONS.md` has the scars), and the server it now runs on. The author
decided what to build and had the last word on every decision; Claude did a
great deal of the typing, the checking and the measuring.

Thank you, Anthropic, for building a collaborator worth arguing with. 🛒

## License

MIT for the engine ([LICENSE](LICENSE)). Third-party code is MIT, BSD, ISC or
Apache, so a shop never inherits a copyleft or per-seat licence. The fonts are
under the SIL Open Font License, the bundled towns come from OpenStreetMap
(ODbL) and the country list from GeoNames (CC BY). Every piece, its version
and its notice: [THIRD-PARTY.md](THIRD-PARTY.md).
