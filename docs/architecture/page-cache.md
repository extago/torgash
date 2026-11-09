# Page cache: whole pages kept ready for guests

PLAN §61. Core (`App\Core\PageCache`), switched on in Settings → Store →
"Page cache"; what is kept and why a page was not is on System → Cache and
sessions. Read this before adding a storefront page, anything a page shows
that is the visitor's own, a session key, a cookie or a table.

## How a page is kept

- A storefront route opts in with the middleware
  `page.cache:<kind>,<param>,…` — the kind (declared by its module with
  `page_cache()->kind('product', 'Product pages')`, a declared word: English,
  translated by lang files) and the address's parameters the page READS
  (`page`, `sort`, `per_page`, `view`). Any other parameter is drawn afresh.
- Advert tags (`utm_*`, `gclid`, `yclid`, … `PageCache::AD_PARAMS`) are no
  part of the address: `withoutAdTags()` takes them off the request first, so
  a click on an advert is served the clean address's copy or, when there is
  none yet, is drawn as that address and leaves it. Off the parsed query AND
  the raw `QUERY_STRING`, pair by pair: the language links and the sorting
  print the address they were drawn for. The visitor's own counters still
  find the tags — they read the browser's address bar.
- Only guests: a signed-in visitor, a POST, htmx / XHR / JSON, a page with a
  message to show (flash) or a form that came back are drawn afresh
  (`bypassReason()`). A copy is taken only from a visitor who holds nothing
  personal (`takeRefusal()` / `personalHeld()`), and only of a 200 HTML page
  that sets no cookie (`unfit()`).
- `X-Torgash-Cache: hit | stored | miss | bypass` on every page that has
  the middleware — with the reason after a semicolon while APP_DEBUG is on
  (`miss; reads the session's cart_token outside a personal part`). A copy
  that cannot be served (it throws) is forgotten and the page drawn again —
  a broken copy costs one slow page.
- The copy is written AFTER the response (`defer()`, `storeAfterResponse()`):
  packing a page and committing it cost 33 ms on average and up to 650 on a
  live shop, and the visitor who drew it gets nothing from the wait. So
  `stored` in the header is what was asked for. Anything thrown away while
  the page was drawn is seen before the header leaves (`miss; changed while
  drawn`); anything thrown away in the milliseconds after that is refused by
  the INSERT's own check of `page_cache_changes`, and the next visitor draws
  the page.
- Served pages get the visitor's own form token, CSP nonce and form stamp
  (`FormGuard`): in the copy they are placeholders, random per copy.

## What a visitor carries is DECLARED by whoever owns it

Every session key and every cookie the shop writes has an owner, and the
owner says what it means for a page — at boot, in its service provider:

- `harmless(session:, cookies:)` — changes nothing on any page (the
  language already in the address, "counted already" lists, the CAPTCHA's
  sum, which only the image and the check read).
- `personal(session:, cookies:)` — drawn ONLY inside personal parts (the
  basket's token, the wishlist, the comparison). A visitor holding it is
  served copies but gives none: whatever was worked out about it before the
  page began would be in the copy. `copies: true` for a flag that nothing
  reads before the page is drawn and no memo keeps ("asked already", the
  chosen town, viewed recently): its holder still gives copies.
- `vary(name, resolve, session:, cookies:)` — the page is drawn differently
  for it, each value its own copy (the currency, a listing's layout, the
  pickup point, the tax rates where the visitor is, the search's scope).
  EVERY copy is filed under EVERY vary's value; `resolve` answers what the
  pages will make of it, cheaply, on every request — so a vary with many
  values divides every page's visitors. When only a small part of the page
  differs, make it a personal part and declare the state `harmless` (what the
  visitor agreed to: the banner, the Consent Mode defaults and each gated
  snippet are parts; one copy serves every answer).
- `under(vary, fn)` — a read of personal state that a vary already answers
  for (the taxes read the visitor's town; the `tax_place` vary files copies
  by the rates that town gets): quiet while a copy is drawn.

What nobody declared closes the door: a session key → the page is drawn
afresh for that visitor and the key is named on the cache screen; a cookie
the shop wrote (it decrypts) → the same. A third party's cookie decrypts to
nothing and is ignored. Cookies cannot be watched (Symfony's bag is final),
so a personal cookie is read in personal parts only, by rule. Whoever owns
such an answer says it was asked — `page_cache()->visitorRead('…')`, which
`Consent::allows()` does: outside a part the page is not kept, inside one the
part is drawn for every visitor, whatever it said about being reusable.

While a copy is drawn the session is WATCHED (`WatchedSessionManager`): a
personal read outside a personal part, or any write to the session, and the
copy is not kept — the first reason is noted for the cache screen. A
forgotten declaration costs speed, never a stranger's basket.

## Personal parts

- `personal('view', [data], reusable: bool)` in PHP, `@personal('view',
  [data], true)` in Blade. Drawn as an ordinary view outside a capture; while
  a copy is drawn, between markers, and the copy keeps only its place.
- The data is what the copy keeps: plain values, and models (kept as class +
  key, fetched again FRESH, one query per class for all parts). The view is
  drawn ALONE — it sees its data and what is shared with every view, never
  the template around it. A part must not push to a stack (refused), and
  must reach the page as drawn (printed escaped → refused).
- `reusable`: the part depends on nothing of the visitor's but their session
  — not the address, a header, the IP or the clock (or it bounds the copy
  with `until()`, see below). It records the personal keys it read and WHAT
  THEY HELD; a visitor holding the same gets it as drawn, without drawing.
  So the heart on every card costs nothing for a guest without a wishlist,
  and a tab that waits costs nothing for a guest on their first product.
- A part is not reusable when it writes the session, reads the whole of it,
  or reads a key a part before it wrote. A visitor holding any `personal`
  key (not `copies`) or a personal cookie gets NO part as drawn: a memo of
  their state may sit behind a part that never asked the session.
- Data that names the visitor's own state goes in the part, not the call:
  the part works it out itself (the header's town, the delivery estimate).

## Memos of personal state

The watch sees the SESSION being read. A per-request singleton that keeps
what it read (a list loaded once for every heart on the page) hides every
later read — the second heart would be kept as drawn for everybody. So a
service that memoizes personal session state still ASKS THE SESSION on every
call (`Wishlists::current()` reads its token before looking at its memo), or
does not use its memo while a copy is drawn (`page_cache()->current() !==
null`: `Shortcuts::all()`, `MenuBuilder::nodes()`). `ListBuy::inCartMap()`
answers nothing at all while drawing (`page_cache()->drawing()`); the
in-cart marks come from a reusable part at the end of the page.

## Zone blocks

A shop's block can be personal without anybody declaring it (a rule asking
the visitor's town, "Viewed recently"). `ZoneRenderer` draws every block
through `Holes::probe()`: one that read anything personal becomes a personal
part (`torgash.blocks::personal` draws it again), kept as drawn for whoever
holds what it read. A block TYPE or a display rule KIND that reads what no
session shows declares `'personal' => true` — never reused. A row or tabs
with one personal member is ONE part (the others close up around it). A
personal block must not push to a stack.

A block that need not be drawn with the page WAITS instead (`later` on its
type, storefront.md): only the name of its tab, or its place, is a personal
part. It is what a copy costs someone with a browsing history: "Viewed
recently" in a row of tabs made the row one part, 24–36 cards drawn again on
every page they opened — 310–440 ms against 12. Waiting, it is one `exists`
query: 13 ms.

## Visit actions

What a page does for every visit — counting a view, popularity, "viewed
recently", the town of a geo page — is `visit_action('name', [data])`, its
work registered at boot with `page_cache()->onVisit('name', fn ($data,
$request))`. It runs now, is recorded with the copy, and runs again BEFORE
the parts on every serve, so the parts see what it did. Never from inside a
part (that part is then drawn for every visitor).

A counter it moves is written after the response (`defer()`,
`Popularity::viewed()`: `views_count` and `popularity` in one statement).
Nobody needs the number on this page, and a commit in front of the visitor is
a large part of what serving a copy costs. What the page and the next one must
see stays in front — the session guard "once per session" is written at once.
The columns are still declared `unseen`, so the late write throws nothing away.

## Throwing copies away

- Every write statement (`Writes`, from `DB::listen`) throws EVERY copy away
  once its transaction commits (a generation counter: cheap). What a
  transaction pended is kept on THAT transaction: rolled back (a savepoint
  too), it is gone with it — `Writes` lives as long as the worker, and a
  reason left behind would turn the next tag into a full flush.
- A table no page shows is declared by its owner: `unseen('m_orders')`, or
  only some columns: `unseen('m_catalog_products', ['views_count', …])`.
  Orders, baskets, logs, counters, sessions are unseen. When in doubt, it is
  seen: a needless flush costs the next pages' drawing, a missing one serves
  a stale page. A statement counts whether or not it changed a row, so what
  a background job keeps rewriting (the search index, the carriers'
  directories) is unseen if no page outside a part reads it.
- A note the engine keeps for itself in a table pages are drawn from (the
  last update check, in `settings`): `page_cache()->unseenWrite(fn () => …)`,
  for that write alone — a statement cannot tell that row from the shop's
  name, and the table as a whole stays seen.
- Everything thrown away is written down with why (`update
  m_catalog_products (price)`) and from where (`POST /admin/…`, `queue:work`):
  `flushes()`, the last 30, "Why pages were thrown away" on the cache screen.
  The same reason from the same place within ten minutes is one line, counted.
- Tags: a page says what it shows (`page_cache()->tag('product:12')`, the
  catalogue tags every listed product); `discard('product:12')` throws away
  those copies only, after the commit. Stock: `catalog.stock.moved` discards
  the product's tag, or everything when it crosses zero (listings filter).
- Code changes, a module switched, the setting switched: everything.

## The clock

- A copy lives `page_cache_minutes`, a day unless the shop says otherwise:
  the typical return to a page is hours, and whatever changes throws it away
  by itself. So words that go stale by themselves bound the copy.
- `page_cache()->until($moment)` from a page or a part: the copy is kept
  only until then (a transfer date counted from today, a display rule's
  period — `RuleKinds` `changes` callbacks; "3 days left", "5 minutes ago",
  today as a date field's earliest).
- `page_cache()->changes(fn () => ?DateTimeInterface)` at boot for what a
  module switches on and off by dates with nothing written (banners, sales
  and their weekly windows, collections, kits, landings, labels and "new for
  N days"); `nextDateIn($table)` answers it for a `starts_at`/`ends_at`
  table. Asked once per copy kept.
- Scheduled blog posts: `Post::listed()`/`reachable()` keep the page only
  until the next publication.

## Tests

`tests/Feature/Core/PageCacheTest.php` (fixture views in
`tests/fixtures/views/pagecache`): kept and served, each visitor's token and
nonce, parts drawn or kept as drawn, refusals with reasons, writes and
rollbacks, tags, varies, parameters and advert tags, the clock, visit
actions, the switch, a broken copy. Tests share ONE application across
requests: `flushSession()` between visitors, and take the copy before a
visitor builds up state (a memo singleton holds the last visitor's state in
a test, never in production).
