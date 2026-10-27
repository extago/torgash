# Torgash

*Торгаш* (TOR-gash), Russian for "a huckster": someone who will sell you
anything. This one sells your things, and it does not cut corners doing it.

Torgash is an e-commerce engine for people who have run a shop on something
older and kept a list of everything that annoyed them. It has a small core,
modules that plug in through hooks and never patch anything, and themes that
can override any template.

**Installing a shop:** take `torgash-X.Y.Z-install.zip` from
[Releases](https://github.com/extago/torgash/releases) and follow the
`INSTALL.md` inside it (`INSTALL.ru.md` по-русски).

## What's inside

**The stack is deliberately boring.** Laravel 13 on PHP 8.5, PostgreSQL,
server-rendered Blade with HTMX and Alpine, Tailwind and daisyUI. There is no
SPA and no Node: the shop's stylesheet is built on the server, and
`vendor/` ships inside the release. You can unzip it and it runs.

**It is quick.** The engine runs under FrankenPHP's worker mode (Laravel
Octane): the application boots once, then serves request after request. On a
live shop that took the admin sign-in page from about 85 ms to about 13 ms.
Every per-request leak found along the way has a test that fails without its
fix.

**53 modules, one distribution.** Catalog with options, kits and variations;
cart, checkout, promotions, gift cards, taxes; shipping with zones and
carriers (Nova Poshta, CDEK); payments (Stripe, LiqPay, monobank, WayForPay);
warehouses and an inventory journal with FIFO costing; reviews, wishlists,
comparison and live search that understands typos; tickets and returns;
automations; blog and pages; SEO; 1C/CommerceML exchange and an API; GDPR-style
personal data tools; and an audit log you can undo from. A module made for one
country says so, and the installer picks the ones that fit your shop.

**Some opinions it holds:**

- Money is integer minor units, always in the shop's own currency. A price
  typed in another currency is kept as typed and recalculated when the rate
  moves.
- Weights and sizes are whole grams and millimetres. "Not measured" is a
  different answer from zero, and shipping treats it that way.
- Content is translatable from the first day. An admin form edits one
  language at a time, so saving the Russian text never wipes the Ukrainian.
- Text written by staff is sanitised before it is printed, every script
  carries a CSP nonce, and admin sign-in supports a second factor. The right
  to edit a product description is not the right to run JavaScript as the
  owner.
- Updates are Ed25519-signed packages that come with a way back. They are
  published on GitHub Releases too, for shops that can only reach GitHub.
- Russian search works properly: the database is created with an ICU locale,
  because under the C locale Postgres quietly stops treating Cyrillic as
  letters.

## Status

Young, but real. The first commit was in August 2026. A live shop runs it on
Debian with FrankenPHP, PostgreSQL 18 and Valkey, and the bugs it finds go
straight back into the engine.

## Built with Claude

Torgash was designed and written by its author in close collaboration with
[Claude](https://claude.com/claude-code) from [Anthropic](https://www.anthropic.com).
That covered roughly five hundred commits: architecture debates, digging
through how other shops do it, hunting N+1 queries, Blade parser traps (`docs/LESSONS.md` has
the scars), and the server it now runs on. The author decided what to build and
had the last word on every decision; Claude did a great deal of the typing,
the checking and the measuring.

Thank you, Anthropic, for building a collaborator worth arguing with. 🛒

## License

MIT for the engine ([LICENSE](LICENSE)). Third-party code is MIT, BSD, ISC or
Apache, so a shop never inherits a copyleft or per-seat licence. The fonts are
under the SIL Open Font License, the bundled towns come from OpenStreetMap
(ODbL) and the country list from GeoNames (CC BY). Every piece, its version
and its notice: [THIRD-PARTY.md](THIRD-PARTY.md).
