# Filter pages, city pages, price pages

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- **A filtered address is written in words and read in both spellings.**
  `Torgash\Catalog\Filters\FilterQuery` owns reading one and writing one,
  because the two have to agree exactly. The word comes from `Feature.code` +
  `FeatureValue.code` — NOT `slug`, which is UNIQUE globally because it is the
  brand page's address, where a query parameter needs "unique within this
  characteristic" (`UNIQUE (feature_id, code)`, already there). Several values
  of one characteristic are separated by a COMMA (`?material=steel,wood`): a
  checkbox group cannot say it any other way, since repeating the key leaves
  PHP holding only the last value. The old `feature[id][]=valueId` is read FOR
  EVER and mixes freely with the new form — it is what this shop published up
  to now and what sits in other people's links, and it stays the fallback for a
  characteristic or value with no code. A characteristic coded like something
  the catalogue already owns (`page`, `q`, `sort`, `price_min`, …) keeps the id
  form; `?page=2` is the pagination's word. **A code nobody knows matches
  NOTHING**, never "no filter at all" — an unknown value id already matches
  nothing in the other spelling, and a disagreement there shows the whole
  catalogue where the other spelling shows an empty grid; such a filter is
  named as a chip with the way back, or the empty page explains nothing. The
  parse is memoised on the query string: the grid, the ticked boxes and the
  chips all ask it about the same request. The form still emits the id shape
  (that is what a browser with no JS sends) and carries `data-param`/`data-value`
  beside it, from which its own submit handler builds the spoken address.
- **A yes/no filter can be part of a published ADDRESS.**
  `m_catalog_filter_pages.flags` holds registry KEYS (never a column per
  filter), so a module registering one through `catalog.filters.flags` is
  publishable the same day; they join the fingerprint sorted, and are appended
  only when present so older rows hash unchanged. Applied through
  `Flags::applyKeys()`, never a copy of their SQL — and a key nobody answers
  for narrows to NOTHING, because widening the page into its whole category
  under a heading promising less is the one answer that misleads a visitor and
  a crawler at once. The canonical now matches the WHOLE question (values plus
  flags) instead of refusing whenever a box is ticked, and a page's own flag
  shows as ticked in the panel — that tick is the way off the page.
- **A filter page is a CATALOGUE page, not a `torgash.seo` one**
  (`m_catalog_filter_pages` + `m_catalog_filter_rules`, `/catalog/{category}/{filters}`):
  a category plus a set of characteristic values at an address of its own. In
  catalog for the reason meta columns live on the entity — these are PUBLISHED
  ADDRESSES, and uninstalling the SEO module may not take five thousand live
  URLs with them; what it takes is the writing of titles, which is why a
  generating rule owns only the address and the NAME and the head is written by
  the same templates as everything else (catalog registers `filter_page` through
  `seo.subjects`). The set is canonical — ints, both levels sorted, reduced to a
  UNIQUE `fingerprint`, because jsonb cannot be compared to jsonb. The line it
  all exists for is on the CATEGORY: a listing whose ticked boxes match a page
  EXACTLY points its canonical there, and does not when the visitor has narrowed
  by something the address cannot express (price, stock) — that page holds fewer
  products, and sending it to a wider one is the one misleading answer. Empty is
  `noindex` worked out LIVE, never from the stored `products_count`, and it
  still answers. **A rule never deletes**: it adds what is missing, refreshes its
  own, and takes a thinned page OUT OF THE SITEMAP rather than out of existence
  — a 404 on a page a crawler already holds is the worst of the three answers
  and the least visible; deleting is the admin's act and leaves a 301 to the
  category. One characteristic per rule (two multiplied publish the square of
  the catalogue), and a rule's per-language name is expanded WITH THE LOCALE
  SWITCHED, or a background job writes the English category name under the `ru`
  key. **Publishing an address and advertising it are two decisions**: `enabled`
  owns the first (off = the address goes), `in_list` the second (off = the
  category simply does not link it), and `filter_page_links` bounds each row of
  the strip IN SQL — a shop with a page per town has hundreds of live addresses
  in one category. The rule carries `in_list` too, copied onto a page at
  CREATION and never refreshed (unlike `in_sitemap`, which is recomputed because
  thinning is measured, not editorial). The strip is TWO rows, narrowing first
  and towns second, because one leads inwards and the other sideways. Out of the
  list means out of it EVERYWHERE, its own address included — a filled-in button
  there only says "you are here", and the way back is the breadcrumb and the
  ticked box in the panel; the one page drawn out of turn is one that is IN the
  list and merely did not FIT, while it is the one being read.
- **A city page is a filter page with a town on it** (`place_id` on
  `m_catalog_filter_pages`, no FK — the places are another module's table). One
  table and one route, because both are the same sentence about a published
  address; the difference is that a filter page holds FEWER products than its
  category and a city page holds the same ones under different words. So
  nothing ever canonicalises ONTO a city page (no query string means "in Kyiv",
  so no listing duplicates it), its `values` may be empty, and it is its own
  `seo.subjects` kind (`geo_page`) — a shop writes "{category} в
  {location:6}" for city pages without reaching every filter page. The
  fingerprint appends the town only when there is one, so rows written before
  this still hash the same. A generating rule has ONE axis (`FilterRule::axis()`
  — a characteristic's values, `place_ids`, or price brackets, in that order of
  precedence, derived from which column is actually filled rather than a
  stored "kind" that could disagree with it); towns × makes from one screen
  would publish the square of the catalogue, and "every town" is not a default
  because a pack holds 192 000 of them. The slug is expanded in the BASE
  language whatever locale the job runs in, because an address may not move.
  Arriving on one announces `storefront.place_chosen`, which checkout only
  accepts while the customer has not named a town themselves.
- **The panel's price boxes speak the VISITOR's currency** — the one the
  cards are printed in — and a typed bound travels with `price_currency=RUB`
  (reserved, sent by the panel's script only beside a typed price), so the
  address means the same thing whoever opens it. `FilterPages::priceBounds()`
  is the one place that turns it into the books (÷ rate; lower bound floored,
  upper ceiled, so the product whose price was read off a card is never shut
  out); no word, or the shop's own code, means the books, as every address
  written before it did.
- **A price range is the fourth thing a page can stand for** (PLAN §30.14):
  `price_min`/`price_max` on `m_catalog_filter_pages`, minor units, ALWAYS the
  shop's books like any money column — "up to 30 000" in hryvnia and in euro is
  one page, not two, printed by `format_price()` in the visitor's own currency,
  or the currency switcher would multiply published addresses. NULL is an open
  boundary, and both columns join the fingerprint only when set (`place_id`'s
  own trick), so a row written before this hashes unchanged. Narrows through
  `FilterPages::applyPriceBounds()` — the ONE comparison "under 30 000" means,
  shared by the sidebar's own boxes, a published page's `applyTo()`, and the
  generator's own count, so none of the three can drift into meaning something
  different by the same words. **Canonicalisation by EXACT boundary falls out
  of the fingerprint for free**: "up to 29 999" is a genuinely narrower set of
  products than "up to 30 000", so `canonicalFor()` needs no special case for
  price at all — a bound with no matching page simply fails to match, exactly
  as an unknown characteristic value already did (§102's rule, extended). The
  page's own bound shows in the boxes, the same way a page's own flag shows
  ticked — the way off a price-bracket page is typing over them, since the
  panel's form still posts to the plain category. The generator's third axis
  is a LIST of brackets the shop TYPES (`FilterRule::price_bounds`, one
  "min-max" per line in the admin form), never quantiles computed from the
  catalogue — a boundary that moved with the prices would rewrite an address
  that has already gone out. `{price_min}`/`{price_max}` are bare-number
  placeholders (no currency sign, the same reason `ProductText` never calls
  `format_price()`) for the rule's own name and slug template, which defaults
  to literally `{price_min}-{price_max}` — `Str::slug()` eats the stray hyphen
  an open-ended bracket produces on its own.
