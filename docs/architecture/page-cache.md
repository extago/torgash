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
  (`page`, `sort`, `per_page`, `view`). Any other parameter is drawn afresh;
  advert tags (`utm_*`, `gclid`, … `PageCache::AD_PARAMS`) are served the
  clean address's copy but never copied themselves.
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
  pickup point, the tax rates where the visitor is, the consent given, the
  search's scope).
  EVERY copy is filed under EVERY vary's value; `resolve` answers what the
  pages will make of it, cheaply, on every request.
- `under(vary, fn)` — a read of personal state that a vary already answers
  for (the taxes read the visitor's town; the `tax_place` vary files copies
  by the rates that town gets): quiet while a copy is drawn.

What nobody declared closes the door: a session key → the page is drawn
afresh for that visitor and the key is named on the cache screen; a cookie
the shop wrote (it decrypts) → the same. A third party's cookie decrypts to
nothing and is ignored. Cookies cannot be watched (Symfony's bag is final),
so a personal cookie is read in personal parts only, by rule.

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
  and "Viewed recently" costs nothing for a guest on their first product.
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

## Visit actions

What a page does for every visit — counting a view, popularity, "viewed
recently", the town of a geo page — is `visit_action('name', [data])`, its
work registered at boot with `page_cache()->onVisit('name', fn ($data,
$request))`. It runs now, is recorded with the copy, and runs again BEFORE
the parts on every serve, so the parts see what it did. Never from inside a
part (that part is then drawn for every visitor).

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
  a stale page.
- Tags: a page says what it shows (`page_cache()->tag('product:12')`, the
  catalogue tags every listed product); `discard('product:12')` throws away
  those copies only, after the commit. Stock: `catalog.stock.moved` discards
  the product's tag, or everything when it crosses zero (listings filter).
- Code changes, a module switched, the setting switched: everything.

## The clock

- `page_cache()->until($moment)` from a page or a part: the copy is kept
  only until then (a transfer date counted from today, a display rule's
  period — `RuleKinds` `changes` callbacks).
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
