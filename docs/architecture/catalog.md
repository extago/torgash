# Catalogue: categories, characteristics, filters, sorting, availability

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- **A category IS its subcategories — everywhere, with no switch.** One place
  answers it: `Torgash\Catalog\Support\CategoryTree` (a registered per-request
  singleton, the whole table read once) — `descendants()`, `ancestors()`,
  `ordered()` in tree order and `options()` indented for a picker. The walk is
  breadth-first with a seen-set: a looping `parent_id` is a corrupt row, not an
  impossible one, and a walk that trusts the data hangs the request. Depth lives
  in the service and is never assigned to the model — an Eloquent model has no
  spare properties, and an attribute becomes a column on the next `save()`.
  A parent category page lists its children and shows the products of the whole
  subtree; every rule that names a category (promotions, shipping, labels,
  price-type markups, motivation cards, display rules) takes the subtree; every
  picker is the tree, indented. The one place "and below" is a real choice is
  how many categories a GENERATOR writes pages in (`FilterRule::with_children`)
  — that is a decision about how much catalogue to publish, not about what a
  category means. Two seams keep modules apart: catalog hands `shipping.cart_lines`
  each line's categories WITH THEIR ANCESTORS, so the delivery module never
  hears of the tree; and a `seo.subjects` registration may carry an optional
  `options` (how this kind looks in a PICKER, as against `title`, which is one
  row of a listing), so `torgash.seo` names no catalogue class to get a tree.
  Read upwards, not down, in exactly one place: which characteristics filter a
  category — a filter attached to a branch applies to everything under it.
- **A category has a product's three states** (`active` / `hidden` /
  `disabled`, never the old `enabled` column): ask `isListed()` for anything
  that NAVIGATES (menus, trees, subcategory rows, breadcrumbs, sitemap,
  suggestions, filter-page generation, the public API tree) and
  `isReachable()` for anything that OPENS it (its page, a products block's
  source, a menu item a person placed by hand). `enabled` survives as a
  compatibility attribute — writes keep the old meaning, reads answer
  "listed" — and nothing in the engine reads it.
- **A category's page shows what is under it as the theme says, or as the
  category says** (2026-10-28): `Category::subcategoriesView()` — its own
  `subcategories_view` (tiles / buttons / none), else the theme's
  `subcategories` (section "Category page"), else tiles. Tiles are the
  "Category tiles" block's own view included with the theme's settings
  (`bare`: the page frames it), so a category page and the home page look
  alike; the block got the same `shape` (card / circle / rounded),
  `counts` and `photos`. A subcategory with `shown_in_parent` off is left out
  of its parent's page only — the menus and the list beside the catalogue
  still have it. Counts are `Support\CategoryCounts` (the list beside the
  catalogue asks it too: one query, the subtree, `Product::listed()`); a
  category without a tile picture borrows the photo of its most popular
  product the visitor can order (`Support\CategoryPhotos`: one query, then
  that product's own photo, so a product without one lends nothing). Drawn
  through `Fragment::isolate()` — the grid holds the subtree anyway.
  Buttons keep to two rows behind "Show N more", counted by the script.
- **A product's short description is composed where nobody wrote one.**
  `short_description` is a column on the product; which characteristics stand in
  for it is a FLAG on the characteristic (`in_short_description`), never a list
  of codes in a setting, and only global characteristics compose it — a
  free-form JSONB bag has no row to tick and would put the warranty period in
  the first line. `Torgash\Catalog\Support\ShortDescription` is the one place
  that answers, with the SEO templates' own vocabulary for the mode (`off` /
  `fill_empty` / `override`, and `override` still falls back to what was
  typed). A card gets its own separator, its own limit and no characteristic
  names; what it never gets is a different SOURCE from the page it links to.
  Composed from a relation, so a grid announces its set (`prime()`/`forAll()`)
  — the rule `withCardData()` already lives by.
- **A written product tab is a row in the catalogue, not a block in a zone.**
  The strip of LABELS is printed before any content, and a zone hands back one
  finished string of HTML with nothing left to say which part was called what —
  so `m_catalog_product_tabs` holds title, content, `sort`, and core's display
  rules in the same two columns a block carries. `product_id` is a column
  rather than a rule kind because it is how the tab is EDITED: one product's
  tab is written on that product's form, a shop-wide one on its own screen.
  `sort` interleaves with the built-in tabs (description 10, characteristics
  20), and a module adds its own through `catalog.product.tabs`. A tab with no
  body is not drawn at all.
- **"Show this where the product has nothing of its own to say" is a display
  RULE**, `product_content` (description / short description / photographs /
  characteristics, missing or there), registered by the catalogue because all
  four are its data — so a tab, a block and a motivation card ask it the same
  way. Each part is read as the PAGE would print it: a short description
  composed from characteristics is not missing, and `<p></p>` from an editor is
  not a description — `Product::hasDescription()` is the one answer, asked by
  the rule and by the page deciding whether to draw that section. Several parts
  in one rule mean ALL of them; "either" is two rows and the list's own
  all/any.
- **A section of the product page has TWO names, and the layout picks one.**
  `title` is the word in the strip (six stand in a row); `heading` is the line
  over a SECTION, with the product in it ("Reviews of the TIGER PRO-300") —
  which is why it is a jsonb column and not a setting: a heading must be
  translatable, and settings are one-language. Which layout draws the list is
  `product_details_mode` (`tabs` / `sections`); the list itself is
  `Torgash\Catalog\Support\ProductSections`, answered as DATA so the two
  layouts cannot drift into two different pages. In sections everything is
  open, each with an `id` and a row of plain anchors above — no Alpine, no
  first section. The `section` column (`description` / `specs`) is a row that
  NAMES one of the page's own sections: it carries no body, and it supplies
  that section's words, its position and whether it is shown at all — without
  it the only two sections a shop could not rename would be the two on every
  product. Its uniqueness is two PARTIAL indexes, because Postgres counts rows
  with a NULL in the index as distinct.
- **Two stores of characteristics, and a fact lives in ONE of them**
  (2026-10-27, PLAN §59 — the live shop's agent changed a colour in both and
  could not tell which counted). The SHOP's characteristics —
  `m_catalog_features`, their values, and one `m_catalog_product_features`
  row per product (a picked `feature_value_id` or a typed `value_text`) — are
  the shop's vocabulary: filters and filter pages, variation axes, the brand
  and its page, labels' `[feature:…]`, the composed short description, the
  comparison's grouped rows. The product's OWN rows —
  `m_catalog_products.features` (a base-language map, NOT NULL;
  `features_layout` is its order and sections, `features_translations` its
  words in other languages) — are words for this one product's page:
  printed under the shop's ones (the tab, `.md`, JSON-LD, the comparison's
  "Other"), searched, and read by nothing that filters. Neither is a copy of
  the other and nothing syncs them. Every WRITER picks one: the form's two
  cards; exim — the typed cell (`Name: S[value]`), a column named after a characteristic,
  `brand`, `VAR Feature N […]`, `feature:<code>` → the shop's; a JSON table
  in `characteristics` → own, as written, even a row called like a
  characteristic (an import never coins filter values on a guess); 1C → the
  shop's; the API writes neither and READS both — `characteristics` and
  `features`. Until 2026-10-27 it served `features` alone, which is how an
  agent concluded the own rows were THE characteristics. Four readers take
  either, the shop's first: `{feature:x}` (by code, then the own key exactly),
  delivery conditions (the own map with the shop's merged over it by name and
  code), the weight characteristic (a setting naming a code beats a key), and
  a feed's `item.params` (`ProductFeed::params()`: the shop's, then the own
  rows flagged `own` — one named like a characteristic the product has a
  value of is left out, since a marketplace reads two `<param name="RAM">` as
  a conflict). The one fault is the page printing one thing TWICE: the
  product HAS the shop's value, `specs` shows it, and an own row is called
  like it (a name in any language, case and spaces ignored — never the code:
  "Power" on a kettle is not a charging power coded `power`). Pointed out,
  never refused or "fixed":
  "RAM: 16 GB DDR4, two slots" beside the filter's 16 GB is a legitimate
  longer wording, and the shop's answer is to untick `specs` on the
  characteristic so it only filters. `Features\OwnRows::printedTwice()` is the
  form's warning above the own rows; the invariant
  `catalog.characteristics_in_one_place` is the same rule in SQL
  (`CharacteristicStoresTest` keeps the two agreeing).
- **Where a characteristic is SHOWN is a different question from what it
  FILTERS.** `purpose` says what it IS (plain / variation axis / brand — `info`
  is gone, it was never about that); `shown_in` is a list of place KEYS from
  `Torgash\Catalog\Features\Places`, answered lazily through
  `catalog.feature.places`, and core registers three: the filter panel, the
  specifications, the short list under the buy button (how many fit there is
  `buy_box_specs_max`; WHICH may appear is the tick, not "the first four").
  A list rather than three columns, for the reason `filter_pages.flags` holds
  keys: a module drawing characteristics of its own registers its place and is
  tickable the same day. **Nothing here decides filtering**: every
  characteristic with a code is a word an address is written in, and unticking
  `filters` means "do not OFFER a control" — otherwise a published filter page
  (a SET of values at an address of its own) would silently widen into its
  whole category under a heading promising less, and the way back off such a
  filter is the chip row, which names every filter in force whether its box is
  drawn or not. Deliberately NOT the flags' rule (an un-offered flag does not
  apply): a flag is the shop's own setting with no published address behind it.
  `ProductFacts::specs()` obeys the same tick — a rendition that disclosed what
  the HTML hides is a hole that differs by one `Accept` header.
- **A code is assigned by the MODEL, and every row has one**
  (`Torgash\Catalog\Support\AssignsCode` on Feature / FeatureValue /
  PriceType; the columns are NOT NULL). The rule used to live in the admin
  FORM, so an import, 1C, a seeder and the API all wrote NULL codes — and a
  NULL code is a row nothing can match on. Three answers in order: what the
  shop typed (kept for ever, a rename included), the row's own name
  transliterated in ITS language (`Цвет` → `TSVET`; reading `name['en']` first
  and finding nothing is how a Russian-only shop ended up with ITEM, ITEM-2,
  ITEM-3), and its id — `FEATURE-11`, for a name made of punctuation, readable
  by whoever is matching columns where `ITEM-7` is not. The id is not known
  until the INSERT, so that last row is written with an untypeable placeholder
  and renamed in `created()`. Catalogue codes are UPPERCASE and a value's is
  unique within its characteristic, which is why this is not core's `HasCode`.
  A query-builder `update()` fires no Eloquent events, so it walks straight
  past this (and past the audit) — write through the model.
- **The description section is asked ONCE for its surroundings** (product.blade
  `$descriptionParts`): the zones `catalog.product.before_description` /
  `after_description` and the hook `catalog.product.description_instead` (a
  module's page shown INSTEAD of the text — `torgash.landings`). The section is
  there when the text or any part has something to say; the description
  itself is never replaced in the data — feeds, the `.md` page and the meta
  description keep reading `description`. A landing stands in a declarative
  shadow root (`<template shadowrootmode>`): `@scope` kept its CSS in but
  let daisyUI's `.hero`/`.card` style a designer's elements of the same name.
- **A product's SKU may be left blank; the MODEL fills it** (`Product::booted()`).
  Blank is a placeholder on INSERT; in `created()` — the id known, the
  categories NOT yet (every door attaches them after the row) — the filter
  `catalog.product.sku` is asked with `typed`, default `P-<id>`
  (`hasFallbackSku()`); an answer somebody else holds is ignored, and the row
  is written quietly. `torgash.sku` answers it by a template (a category's own,
  re-made on `catalog.product.saved` once the form synced the categories) and
  renumbers in the background (`Renumber`, saves through the model for the
  audit). Running numbers are one `INSERT … ON CONFLICT … RETURNING` per
  template, so concurrent creates never share one.
- **A variation group is switched by characteristics the shop MARKED, never by
  free-form attributes.** The group is still just the shared `variation_group`
  word (typing it is the whole link, no generator, no group table), but its
  axes are the GLOBAL characteristics with `purpose = variation`
  (`Feature::PURPOSE_VARIATION`) whose values differ inside the group —
  because an import matches characteristics and values by code, and products
  of one group may differ in thirty ways of which the shop chose two.
  `Torgash\Catalog\Support\Variations::for()` is the ONE answer (members,
  offered, axes with options, each member's answers, duplicates), asked by
  the product page, the choice window, `ProductFacts`, the kits picker and the
  admin's Variations tab. Two members with the same answer on every axis are a
  DUPLICATE: the one created first (then the lower id) is offered, the rest
  keep their pages but no picker leads to them, and the admin table marks
  them in yellow. A picker's value leads to the member that keeps this
  product's other answers.
- **A listing with the panel is ONE thing, over any set** —
  `Torgash\Catalog\Listing\Listing` (the panel's options, applying the
  filters, the sorts, the chips, the count on "Show 12 products") and
  `torgash.catalog::partials.listing` (the form, the sheet on a phone, the
  toolbar, the grid, the address builder). Its SCOPE is a category, a query
  a page built (`listed()` products — a sale's, a search's answers; cloned,
  never added to) or the whole catalogue. A set that is not a category gets
  a Category block — the categories its products stand in, each counted with
  its subtree — and narrows by `?in=<slug>` (subtree and all; an unknown
  slug narrows to nothing). `page($scope, $request, $relevance)` gives a page
  everything; `$relevance` is an order of the answer's own (a search's), the
  first sort until another is picked. A page including the partial passes
  every name it reads — an include inherits the caller's scope. The search
  module no longer narrows by category: it returns the whole answer and the
  listing narrows, so the panel can count every category.
- **A filter panel offers only what can narrow THIS page.** Which
  characteristics apply where is the feature↔category assignment (attached to
  nothing = everywhere, which is how Brand and Colour work) and it inherits down
  the tree; on top of that `StorefrontController::narrowToWhatIsHere()` drops,
  in one query, every characteristic and every value no product on this page
  carries — a control that cannot narrow anything still reads as a promise, and
  the Clothing page was offering a choice of memory for a jumper. Measured
  against the CATEGORY, never against what is already ticked: narrowing the
  options by the current selection leaves the picked value as the only one on
  offer, with no way to widen again but editing the address.
- **The yes/no filters are a REGISTRY, not markup** —
  `Torgash\Catalog\Filters\Flags`, answered lazily through
  `catalog.filters.flags`, with the shop's choice of which to offer in the
  `filter_flags` setting (its options come through `core.settings.fields`,
  since manifest.json cannot know what a module registers). `apply` narrows a
  QUERY, never a loaded page — the grid is paginated. Only an OFFERED flag
  applies: a control the panel no longer draws is a filter with no way off it.
  But every REGISTERED flag's key is reserved in `FilterQuery` whether offered
  or not, because what an address MEANS may not depend on a tick box in the
  settings. A flag nothing on the page matches is not offered (§103's rule),
  and a flag IN FORCE is still named as a chip where its box is hidden (§102's).
  **"On sale" is ONE box**: the catalogue owns the branch it can answer from its
  own columns (`list_price > price`) and every other way a price is cut adds an
  OR branch through `catalog.filters.discounted` — `torgash.promotions` answers
  it in SQL through the same `Conditions::applyProductQuery()` its admin preview
  uses, and a tier's "does it cut THIS price" is a comparison against the price
  column, because "everything down to 999" leaves a 500 item at 500.
- **The ORDER a listing answers in is a registry too** —
  `Torgash\Catalog\Sorting\Sorts`, answered lazily through `catalog.sorts`,
  with the shop's choice in `catalog_sorts` / `default_sort` (options filled in
  through `core.settings.fields`, as the flags' are). Every listing asks it —
  the catalogue, a category, a published filter page and the BRAND page —
  because a hard-coded `->latest()` anywhere makes "cheapest first" a setting
  that silently does not apply there; search keeps relevance, which answers a
  different question. `apply` orders a QUERY, only an OFFERED sort applies, and
  `sort` is reserved in `FilterQuery::RESERVED` whatever the shop ticks — the
  three rules `Filters\Flags` already lives by. `Sorts::apply()` closes EVERY
  ordering with `id`, once, for a module's own sort as well: Postgres may
  return tied rows in any order, so a page boundary falling inside a tie shows
  one product twice and another never — which is what an imported catalogue,
  a thousand products sharing a creation second, is made of. **Ordering by
  availability is a tumbler, not a sort** (`out_of_stock_last` — the key kept
  its old name), applied BEFORE the chosen one as `AvailabilityStatuses::
  applyRank()`: every status has a WEIGHT, higher first, equal weights mixed
  and ordered by the chosen sort. Unweighed = 10 if orderable, else 0, which is
  exactly the old "buyable, then the rest". An ORDERABLE status that gates on
  stock and has none left ranks as `out_of_stock` — the same line
  `applyOrderable()` draws, so the box "in stock only" and the tumbler cannot
  disagree, and a preorder stays up; a status that cannot be bought anyway
  keeps its own weight whatever its number. Weights are cast and written INTO
  the SQL: a bound `then ?` is typed TEXT by Postgres and "10" sorts below "2".
  **The shop's word about a status is `m_catalog_availability_statuses`**
  (screen `/admin/catalog/availability-statuses`): for a status registered in
  CODE a row supplies only a rename and a weight — its meaning is what that code
  relies on, and code wins a key collision; a key nobody registers is the
  shop's OWN status and every column applies. A row saying nothing is deleted,
  a status in use is not. Nothing had to be done about the canonical: `canonicalParams`
  holds `page` alone, and sorting changes the order of a set, not the set. The
  sort is ONE set of radios (`name="sort"`, an icon each — a sort may declare
  `icon`, a Lucide name) in the bar over the grid, belonging to the filter
  panel's form through `form=` (a browser with no JS submits it with Apply),
  but its `change` handler edits `sort` in the address it is already AT — the
  form points at the plain category so a box can be unticked off a published
  page, and a sort submitted through it would take that page's address away
  with it.
- **The filter `<form>` holds the panel and NOTHING else.** The grid sits
  beside it, never inside: every card carries forms of its own (basket,
  heart), and a nested form is dropped by the parser with its inputs kept —
  the first card's basket button submitted the filters (measured). Anything
  in the bar that must submit with the filters joins by `form=`, and the
  panel's script reads `form.elements`, never a query inside the form. Its
  address builder is one function, `window.torgashCatalogAddress(form)`,
  shared by Apply and by the floating "Show 12 products" button, whose number
  is the listing's own query (`X-Catalog-Count` →
  `StorefrontController::countAnswer()`), so it cannot promise what the page
  then disagrees with.
- **A filter block is a `<details>`** (opens without a script). Which start
  open is `filter_open_blocks` (all / first N counted from the price / none);
  a block with anything IN FORCE is open whatever the setting or the visitor's
  remembered choice says — a ticked box under a closed heading is a filter
  nobody can see is on. The visitor's own open/closed is localStorage,
  recorded on the heading's CLICK, never on `toggle` (which fires for every
  block that starts open). Past `filter_search_from` values a block gets a
  nameless search box (it must never submit) and scrolls inside itself, with
  the ticked values first.
- **The card's meta line is ONE row** (`catalog.product.card_meta`): the
  card and the row view wrap whatever the listeners print in a single
  `flex-wrap` line, so a rating, a bonus and a video mark stand side by side
  — each listener printing its own block used to stack them into a column.
  A listener prints an inline piece, never a block of its own. Nothing
  interactive in it: the card's text is one link.
- **The product page's strip may hold more than photos**
  (`catalog.product.gallery`): an item with `video => true` brings its poster
  as `thumb`/`stage` (either may be null) and its own `player` markup, which
  the gallery keeps inert in a `<template>` until play is pressed. The viewer
  (lightbox) is handed the photos alone. See media.md, "Video".
- **Page size and cards-or-rows are the VISITOR's** (`Support\ListingView`):
  `?per_page=` / `?view=` first, then a plain cookie the page's SCRIPT writes
  on click (never the server answering a GET — a link prefetcher follows a
  GET on its own), then the shop's `per_page`. Only a size in
  `per_page_choices` (plus the default, always) applies. Both words are
  reserved in `FilterQuery::RESERVED`; neither touches the canonical. The
  rows view (`partials/product-row`) answers the same hooks a card does and
  asks the basket for `quantity => open`.
- **"This can be bought again" is announced once, for every path that can
  cause it.** `catalog.product.restocked`, fired as a CROSSING (was not
  orderable, is now — so a product that was never away announces nothing, and
  a status edit from `discontinued` back counts as much as a delivery does).
  Two halves, because stock moves two ways: a model listener for everything
  that saves a Product (the form, exim, 1C, a cancelled order), and
  `Product::syncStockTo($id, $sum)` for the warehouses module, whose raw
  `update` of the aggregate fired no Eloquent event at all — which made a
  DELIVERY ARRIVING the one stock change nothing downstream could hear. The
  warehouse computes the number; the catalogue decides what it MEANS, which is
  also why `Product::applyZeroTransition()` now runs on both paths instead of
  only inside `adjustStock()`. `torgash.enquiries` listens and keeps the
  promise its own button made (`Jobs\NotifyRestocked`, queued — one import
  restocks hundreds of products): the news is a MESSAGE in the thread through
  `PostMessage::fromShop()`, so the customer is told in their own language and
  can answer. `fromShop()` claims no `manager_id` and stamps no `answered_at`
  — a robot writing into a thread must not make the inbox report that somebody
  answered in four seconds. With an email the enquiry is `done`; with only a
  phone number it STAYS in the queue, because only a person can ring.
- **What a discount is "off of" is decided in ONE place, `Product::oldPrice()`**
  (setting `old_price_source`): the typed RRP (`list_price`, the default) or
  the Omnibus reference — the lowest PUBLIC price of `lowest_price_days`
  before the current price began, from the shop's own history
  (`Pricing\PriceHistory`, intervals in `m_catalog_price_history`, written on
  a price-moving save, from the form's funnel late, and by an HOURLY sweep,
  because a scheduled promotion moves the price with nothing written). The
  reference is a derived column, `prior_lowest_price` (raw update, never
  copied — `Product::DERIVED_COLUMNS`); NULL = no history = no discount
  claimed. The "on sale" flag's own branch asks the same column in that mode.
  Pruning is measured per product from when ITS price began, never from today.
- **Popularity is ONE number with two settable events behind it**, and the
  date a listing calls "new" is EDITABLE. `m_catalog_products.popularity` moves
  only through `Torgash\Catalog\Support\Popularity`: a view (under the same
  once-per-session guard as `views_count`, and never for what looks like a
  crawler — a bot keeps no cookies, so that guard alone does nothing to it) and
  an order (a listener on `checkout.order_placed` living in the CATALOGUE,
  because the column is its own), each worth what `popularity_view_points` /
  `popularity_order_points` say, 0 = off. POINTS, not tick boxes: a shop gets
  two orders and two hundred views, so equal weights would let views decide the
  order alone. An order counts ONCE whatever the quantity — `sales_count` still
  counts units beside it for the labels, which is a different question, and
  that is why popularity is its own editable column rather than an expression
  over the two: a shop typing over `sales_count` to push a product up would be
  falsifying what the "Hit" badge reads. Written with a plain query, never
  `$product->increment()`, so a page view does not move `updated_at`.
  `created_at` on a product is a DECISION, not a timestamp (the usual
  answer): "Newest first" sorts by it, so it is a
  `datetime-local` on the form, a field in the exim schema (an imported
  catalogue of phones is otherwise entirely new the day it lands), and the one
  attribute `Guard\Audit\Auditor::skipped()` records on an UPDATE while still
  ignoring it on a create. An emptied field means "I did not say" — a new
  product gets now, an existing one keeps what it had — and an absent one
  changes nothing at all.
- **Availability status is a SECOND axis from `status`** (PLAN §31.3), the way
  an order's `payment_state` is a second axis from its own `status`: `status`
  (active/hidden/disabled) stays the admin's lifecycle for the card, untouched.
  `availability_status` is an open registry — `Torgash\Catalog\Stock\
  AvailabilityStatuses`, answered lazily through `catalog.availability_statuses`,
  same shape as `Filters\Flags` — and core seeds five: `in_stock`,
  `out_of_stock`, `preorder`, `discontinued`, `unlimited`. Each declares three
  STRUCTURAL facts, never a shop preference: `orderable` (can it be bought at
  all), `tracks_stock` (does the number apply — false makes it purely
  informational: `Product::adjustStock()` never moves it and
  `OrderStock::canCover()` never compares against it), `allow_negative` (may
  the number cross zero without blocking the button — a preorder's whole
  point). **Named `preorder`, never `backorder`**: an ORDER already has a
  `backordered` status (`OrderStock::canCover()`) meaning "this paid order
  outran the shelf" — a different question from "sold ahead of stock on
  purpose", and two "backorders" would confuse support and the logs alike.
  `Product::isOrderable()` is the ONE place this is decided (delegating to
  `AvailabilityStatuses::isOrderable()`) — every place that used to write its
  own `stock > 0` now asks it, including views (`applyOrderable()` is the SQL
  equivalent, for a WHERE clause on a paginated grid, never a PHP filter run
  after the fact). **Reaching zero is the ONLY automatic transition** — the
  one event the system genuinely sees for itself; everything else (a shipment
  arriving, production resuming) is a fact only a person or 1C can supply, so
  it stays an ordinary edit, the same restraint `OrderStatusChanger` already
  shows. Which status zero leads to is a SETTING
  (`availability_on_zero_status`, default `out_of_stock` — preserves
  pre-existing behaviour), not a property baked into `in_stock`'s own
  registration: "preorder by default" and "just close the sale" are different
  shops, not different facts about the status. Never fires for a status that
  already allows negative. **Eloquent/Postgres gotcha**: a column's DB-level
  DEFAULT is not read back into memory after `INSERT` (only `RETURNING id`),
  so a freshly created `Product` reads `availability_status` as NULL — which
  `isOrderable()`'s fail-closed rule for an unregistered status would then
  wrongly refuse — until something calls `fresh()`. Fixed with a PHP-side
  `protected $attributes` default mirroring the DB one, not by loosening the
  fail-closed rule (which exists for a status a MODULE stopped answering for,
  a genuinely different case). Visibility is NOT this axis's job — `status`
  and `noindex` already own it, so a discontinued product can stay `active`
  (still on the site, just not buyable). Every place that prints a badge or a
  buy button for it also carries `torgash-availability-{key}`, from
  `Product::effectiveAvailabilityKey()` — the EFFECTIVE key, never the raw
  column and never the translated label: a plain `in_stock` row with nothing
  left reads (and must be styled) as `out_of_stock`, and with the low-stock
  setting on, one running low reads as `low_stock` — the one key that is
  never a registered status, purely a presentational nuance `stockLabel()`
  already had. A theme styling "Preorder" differently, or a depleted product
  distinctly from an untouched `in_stock` row, has a hook that actually
  agrees with what is on screen (measured: the raw-column version shipped
  first and was wrong for exactly this case).

**A closed assortment is a LIST on the row** (`customer_groups` jsonb on
products and categories — `guest`, `registered`, `group:<id>`; empty =
everybody), answered in ONE place, `Support\Assortment` (per-request
singleton, PLAN §46). A closed category closes its whole subtree, and a product
is open only while it and every category it sits in (with ancestors) are;
staff see everything. `Product::listed()` narrows through it (so every listing,
search, the live window, menus by counts and the API list agree),
`Category::isListed()` / `scopeListed()` ask it, and what has no visitor — the
sitemap — is a GUEST's (`Assortment::asGuest()`, `Product::openToGuests()`),
whoever opens it. A closed page answers by the setting `closed_assortment`:
`notice` (403, the name, a product's photo, "available to …", sign in with the
way back, noindex — no price, no facts, no `.md`) or `hide` (404). The cart
refuses it (`CartManager::normaliseConfig`) and `PlaceOrder` refuses a basket
still holding one, unless a manager places on somebody's behalf. The forms'
"Who sees it" keeps only words `Assortment::choices()` offers and uses a
hidden `customer_groups_set` marker, so a form without the field keeps what is
stored.

### The category list beside the catalogue (`CategoryNav`)

`Support\CategoryNav::rows()` decides the sidebar's rows; the view only draws
them. `category_nav_mode`: `tree` (roots, opened along the visitor's path),
`branch` (ancestors as "back" rows, the current one, its children — a leaf is
shown among its siblings), `off`. Counts (`category_nav_counts`) are the
visitor's — `Product::listed()`, a subtree counted DISTINCT, one query for all
rows. `category_nav_hide_empty` never hides the current row or the way up.

- **What stands BETWEEN the products is asked, not known** (PLAN §51, step 4).
  The listing (`index.blade.php` — the catalogue, a category, a filter page)
  asks `catalog.listing.inserts` with `category`, `filter_page`, `layout`
  (`grid` / `list`), `page` and `count`, and gets `[after => html]`: html to
  stand after that many products of THIS page. `product-grid` puts each in a
  cell of its own in the grid (`.torgash-listing-insert`, which the dividers
  count like a card) or a row in the list; nothing after the last product.
  torgash.banners answers it (`Listing`): a slider "also between the
  products" places its banners one by one — CARD shape in a grid, STRIP in
  the list, a banner without that shape sitting the view out. The page keeps
  its full number of products: an insert is an extra cell, not a product's
  place ("decrease items per page" is not done — it would move every
  later page's offset).

- **Product sources about "this product"** (2026-10-09). A product block's
  source receives the ZONE's context as a fourth argument (`apply` /
  `order`: `$query, $arg, $settings, $context`; `Selection::products($settings,
  $context)`, the block view passes `$zoneContext`) — on the product page,
  `product`. `similar` is what shares one of the product's OWN categories
  (not their branches; never the product itself or its variations); without
  a product in the context it is empty, never "the whole catalogue".
  `viewed` is `Support\RecentlyViewed`: the session only, newest first, at
  most 30, the page's own product left out, nothing remembered or shown when
  the `recently_viewed` setting is off. It is a list of its own beside the
  session's `viewed_products`, which is the popularity counters' "counted
  already" guard in first-seen order — two questions, two lists. The theme
  puts "Similar / Bestsellers / Viewed recently" as tabs in
  `catalog.product.bottom` (a migration seeds them while the zone is empty).

- **An address follows the MODEL, not the screen** (2026-10-27,
  `Support\KeepsAddress` on Product and Category; the first live shop's
  report). A changed slug leaves a redirect whoever changed it — the admin
  form used to be the only one that did, so an import, the API or a script
  renaming in bulk lost every old address. The bin gives an address up
  (`<slug>-deleted-<id>`, written quietly, no redirect to it; a migration did
  the same to what was already there), and a restore takes it back when it is
  still free. Generated addresses go through `Support\Slugs::make()`, where a
  "+" is the word "plus" (Str::slug dropped it: "iPhone 16+" = "iPhone 16").
  Still true: two rows cannot swap addresses in one step (the column is
  unique) — rename one out of the way first. For such a bulk edit the
  redirects can be paused: the core setting `redirect_on_rename` (the whole
  shop, from the admin) or `RedirectManager::withoutRecording(fn)` (one
  script); only `auto` rows are skipped, typed and imported ones never.

- **Values of one characteristic are MERGED, never re-typed** (2026-10-27,
  PLAN §60, `Features\MergeValues`; the first live shop's "тёмно‑синий" with
  a non-breaking hyphen beside "темно-синий"). One transaction moves everything
  that holds a value's id: the products' links (through the model — the audit
  and search hear it), markup rules, labels' "characteristic is" rules, filter
  pages (rewritten in place, or — when the new set is another page's —
  deleted with a 301 to that page and its empty texts filled; a published page
  outlives a switched-off twin), the brand page (empty fields filled, the
  address with a history kept, the others 301). What OTHER modules hold they
  move on `catalog.feature_value.merged` {feature, into, merged, values,
  products}, fired inside the transaction before the rows go — collections,
  promotions, 1C links; a module that stores a value id listens to it. It
  REFUSES to make two products of one variation group the same on every axis
  (a pair that already was does not count). The merged names (every language)
  and codes stay on the value as `aliases` (an ARRAY): the import's lookup and
  `FilterQuery` (for codes no real value has) still find it by them, and a real
  value always wins over an alias.
- **"Written alike" is ONE rule, `Features\Spelling`**: case, ё = е, every
  dash and hyphen = "-", every space = one space, curly quotes and primes
  straight, invisible characters dropped, NFC — nothing else ("1,5"/"1.5",
  "золотой"/"золотистый" are a person's merge). PHP `key()` and SQL `sql()` are
  built from one table and held together by `MergeFeatureValuesTest`.
  `find()` asks the exact spelling first, then one written alike, then
  aliases, so twins nobody has merged keep their own products from a file. The
  values editor refuses a NEW or RENAMED value written like another (old twins
  still save); the characteristic's page and the invariant
  `catalog.values_spelled_once` name the twins.

- **"All variants of this product" is a button, not a tab** (2026-10-27,
  setting `variations_list`, on). Other engines list the group
  in a product tab; here it is secondary, so a whole-width quiet button under
  the buy box's small actions (cart's `cart.buy_box.bottom`, drawn with
  `hook_render`, outside the box's form) opens `partials/variations-list`:
  picture, code, one column per axis from `Variations::for()`, availability,
  price, and the basket's own `catalog.product.card_actions` with
  `variation => chosen` — a row IS one variation, so it adds itself and only
  what a module asks (`cart.product.asks`) still opens the choice window.
  Rows are loaded `withCardData()` and announced on `catalog.products.listed`;
  sorting reorders the rows by their data attributes (no JSON in markup).
  Only drawn when the group offers more than one member.

- **How a characteristic's values LOOK is two columns, and only drawing**
  (2026-10-09).
  `product_style` — the variation picker (`partials/variation-picker`):
  `labels` (chips), `images` (each member's own picture; no picture → its
  swatch → `<x-no-image>`), `swatches`, `dropdown`. `filter_style` — the
  filter panel: `list` (ticks), `chips`, `swatches`. Read through
  `Feature::productStyle()` / `filterStyle()`, which fall back where a style
  cannot be drawn (swatches without `is_color`). The CONTROL never changes:
  the picker's values are links, the filter's are the same checkbox (in
  chips/swatches visually hidden, `peer sr-only`, the label drawn from its
  state), so addresses, filter pages and no-JS are untouched. The picker
  loads the members' media once when a row is drawn as pictures. Pointing at
  a picture previews that member's photo in the gallery's visible slide
  (`[data-gallery-stage]`) until the pointer leaves — setting
  `variation_image_hover`; a look, never a choice.

- **Labels off the photo, and pictograms** (2026-10-09). `position_detail`
  may also be a product-page ROW — `above-description` (under the heading,
  over the short description) or `under-buy` (under the buy button) —
  drawn by `partials/label-row`, where a picture label prints its words under
  it (a pictogram); either place may be `hidden` (a pictogram belongs beside
  the buy button, not on every card). `[feature:<code>]` in a label's words
  is the product's answer for that characteristic ("RAM [feature:ram]" →
  "RAM 8 GB"); a product with no answer gets NO label (`answeredFor()`), and
  `forProducts()` loads the answers for the whole set once when any active
  label asks — never per card. `plainName()` strips the token too.
- **A label may be a TILE** (`Label::STYLES` = text / image / tile,
  2026-10-09): a square (`TILE_SIDES` per size step, wider when its words need
  one line) washed with the label's tone, its picture the label's upload or —
  with none — a Lucide `icon` (kept only if the file exists), its words under
  it (`[feature:…]` included). A label's picture may be an **SVG**, published
  only after `App\Core\Support\SafeSvg::clean()` rebuilds it from an
  allow-list (no script, handler, foreignObject, style sheet, DOCTYPE or link
  out of the file): drawn through `<img>` an SVG runs nothing, but opened by
  its own address it would run on the shop's origin.
- **A product's own characteristics in other languages** (2026-10-09):
  `features` stays the BASE-language map every lookup, filter and exim
  reads; their words in another language live beside it in
  `features_translations` = `{locale: {k: {key: label}, v: {key: value},
  s: {section: heading}}}`, keyed by the base key and heading so moving a row
  never moves a translation. `featureRows()` answers in the page's language
  (`key` stays the identity, `label` is what a reader sees; value and section
  translated, base words where none); the product form's editor asks with
  `$translated = false`. ONE language per save: in another content language
  the editor shows the base rows as placeholders and posts `features_words`
  — the rows themselves (add, remove, order) are the base language's alone.
  Not yet in exim.
- **A global characteristic's TYPED value in other languages** (2026-10-09):
  a picked value is translated in the value catalogue; a typed one keeps the
  BASE word in `value_text` (filters, delivery conditions, measurements,
  kits, variation keys and exchange read it) and its words in other
  languages beside it in `value_translations` = `{locale: word}`.
  `ProductFeature::display($locale)` is the one reader for a page; the SQL
  readers that compose words (`ShortDescription`, the search index) coalesce
  the translation first. In another content language the product form shows
  a TEXT characteristic as `global_features_words[id]` with the base word as
  placeholder (disabled while there is none) — blank removes the word, never
  the value; a pick or a number saves from any language. A memo of words
  (`ShortDescription::$primed`) remembers its language and starts over in
  another one. Not yet in exim / CommerceML.
- **The brand's logo on the product page** (2026-10-09): `Product::brandValue()`
  is the value of the characteristic whose purpose is `brand` (read from the
  loaded assignments). Its logo stands at the far end of the buy panel's first
  line, beside the rating, leading to `/brand/{slug}` — drawn only when there
  is a logo (the name is already in the specifications) and the setting
  `brand_logo_on_product` is on. On the dark theme it stands on a small light
  plate (`.torgash-brand-logo`): a logo is the brand's file, never recoloured.
- **Under a listing: "Show N more" AND the pages** (product-grid,
  2026-10-12). The button hx-gets the next page, keeps only
  `[data-grid-cards] > *` and swaps `#listing-pager` out of band; the address
  moves on to that page. The pages already on screen are lit, told by the
  `X-Listing-From` header (never the address) and passed to the pager view
  as `loadedFrom`. The count stands beside the category's h1, outside it.
- **A long filter list on a phone** is the first eight plus "Show all (N)";
  the sheet's Apply says the count ("Show 12 products") the computer's
  floating button says.

- **A brand's page is `Listing::page()` over `$brand->products()->listed()`**
  — the panel a search and a sale have (Category block by `?in=`, then that
  category's characteristics, price, flags), minus the brand's own feature:
  every product there already is the brand. `X-Catalog-Count` answered as on
  any listing. `BrandPageListingTest`.

