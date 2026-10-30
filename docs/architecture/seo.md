# SEO: head, robots, redirects, sitemap, machine-readable pages

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- **What a machine reads is composed, never a file.** `/robots.txt` and
  `/llms.txt` are core routes (`App\Core\Seo\Robots` / `LlmsMap`) — the static
  `public/robots.txt` is GONE, and a file put back there would silently win over
  the route, since the web server hands over only what it cannot serve itself
  (the same rule `/1c_exchange.php` relies on, in the other direction). Core
  names only its own paths; a module adds its own through `core.robots.disallow`
  / `core.robots.sitemaps` / `core.llms.sections` (asked lazily at request time,
  because provider boot order is not ours). Two audiences, two settings: an
  ASSISTANT fetching a page for a person is allowed by default, a TRAINING
  crawler is not — and WHICH agents are in each list is a setting, not a
  constant, because the list changes monthly. A named robots group REPLACES the
  `*` group for that agent, so an invited one is handed the same disallows
  again. No invitation, no map: `/llms.txt` 404s when both stances deny.
- **A page has ONE head, so one object fills it**: `App\Core\Seo\PageMeta`
  (`page_meta()`, a scoped binding), printed once by the theme through
  `<x-seo.head>` — title, description, H1, robots, canonical, hreflang,
  OpenGraph. Nothing prints a head tag of its own. The filling order IS the
  precedence — controller → the entity's own columns → a `torgash.seo`
  template → the `core.page.meta` filter, asked at PRINT time — and it works
  because `set()` IGNORES an empty value: a blank column is not an answer and
  may not erase what the controller already said. `@section('title')` remains
  the fallback. **canonical is worked out, not declared**: the current address
  minus every query parameter that is not part of what the page IS
  (`page` only, by default — so `?page=2` is its own canonical and `?utm_…`
  never reaches a crawler; a controller adds its own with `canonicalParams()`),
  and `rel=next/prev` is not printed at all. **hreflang is core's alone**,
  because only core knows a route's shape per locale, and a locale is offered
  only when the route table really has it. Breadcrumbs are DATA
  (`breadcrumbs()`, `<x-breadcrumbs>`), which is what lets the same rows be a
  `BreadcrumbList`; the last crumb is the page and carries no URL. JSON-LD is
  ONE script holding a `@graph` (`structured_data()`): core seeds
  `Organization`/`WebSite`, a module adds its node (catalog's `ProductSchema`,
  which therefore carries no `@context` of its own) — three scripts are three
  graphs nothing connects. The storefront's opening hours are free text and
  stay so on the page; where the whole line reads as days and times
  (`App\Core\Seo\OpeningHours`: "Пн–Пт 9:00–18:00", "Ежедневно 10–20",
  "Mon-Fri 9-18", "24/7"), the shop's node becomes `["Organization",
  "LocalBusiness"]` — schema.org gives hours to a place — and carries
  `openingHoursSpecification`; a line with anything else in it yields no
  schedule rather than a wrong one, and the node stays an `Organization`. **Pages that are not for an index are ONE registry**,
  `core.seo.private_routes`, naming ROUTE NAMES (`cart.*`, `checkout.*`), from
  which core prints both `<meta name="robots">` and the `Disallow:` lines, in
  every locale shape; `core.robots.disallow` is left for what no route
  expresses (`/admin`). A module reaches the page through `core.head` /
  `core.body_start` / `core.footer`. Address normalisation (`NormaliseUrl`) is
  a GLOBAL middleware — the `web` group runs only after a route matched, and
  `//page` matches nothing — with each of its three rules off by default.
- **A page's own metadata is COLUMNS on the entity**, never a table in the SEO
  module: `meta_title` / `meta_description` / `h1` (jsonb), `noindex`,
  `canonical_url` on products, categories, brands and pages. A meta description
  is a property of the product — it must survive `torgash.seo` being
  uninstalled, reach an export and the API for free, and be edited where the
  rest of the product is. Core owns only the CONTRACT, `App\Core\Seo\
  PageSubject` (+ the `HasPageMeta` trait, `MetaFields` for the form, one
  `<x-seo.fields>` card), because two modules that do not know each other have
  to read those fields; a translatable meta field is read at the CURRENT locale
  with NO cross-language fallback, unlike `t()`. A controller says
  `page_meta()->describe($record)`, which also REMEMBERS the record — the next
  link in the chain has to be able to ask it whether its own field was empty.
  Its own words take the placeholders a template takes (`{min_price}` in a
  filter page's own title, 2026-10-29): `describe()` expands them with
  `PageMeta::contextOf($record)` — the context `torgash.seo`'s templates use
  too, so a token means one thing in both — and a text of the record printed
  on the page passes the same context: `safe_html($text,
  PageMeta::contextOf($record))` (a filter page's introduction, a category's
  text), as does the description a controller makes of it.
- **The home page's words are the STOREFRONT's** — `storefronts.home_title`
  / `home_description` (jsonb per language, Admin → Storefronts, one
  language a save): two storefronts, two home pages, and the home page is no
  catalogue entity with meta columns of its own. Empty title: the
  storefront's name, without the suffix; empty description: none.
- **The picture a shared link shows (og:image)** (2026-10-29), nearest
  wins: the record's own from the SEO card (`share_image` column,
  `<x-seo.fields picture>`) → the page's natural one, set by its controller
  (a product's photo, a category's tile or its first product, a filter
  page's first product, an article's cover, a brand's logo) → the
  storefront's (`storefronts.share_image`, Admin → Storefronts → Home page:
  the home page's, and every page's that has none) → the core setting
  `og_image`, an address, the way it was done before. One file under
  `share-images/` on the public disk (`App\Core\Seo\SharePicture`), kept as
  uploaded, never an SVG; `<share>_file` uploads, `<share>_image_remove`
  drops, a form without the field never touches the column. A copied
  record gets a file of its own (`SharePicture::copy()`), or removing the
  picture from one would take it from both. A module putting the picture on
  its record's card adds the column and makes the form multipart.
- **`torgash.seo` is templates, an audit screen and image alt — nothing else.**
  One table (`m_seo_templates`): `subject` + `scope` (`global` /
  `category:{id}`, nearest wins, up the tree) + `mode` (`fill_empty` /
  `override` / `skip` — the last one is how a category opts out of a shop-wide
  rule). **`fill_empty` is measured against the RECORD, not the values already
  collected**: the controller has put the product's name in the title by then,
  so asking the merged values means never firing. Applied through
  `core.page.meta` at an early priority, leaving that filter the last word for
  geo and filter pages. Which kinds of page exist comes from the `seo.subjects`
  registry answered by the data's owner (catalog, pages) — this module names no
  other module's class. Image alt/title ride the same template row and reach
  media through `media.image.alt` (`Gallery::altFor()`), so neither module
  knows the other.
- **Redirects are core's, and core answers for them last.** The table fills
  itself on every slug change; `/admin/redirects` is where a migration's file of
  "old address → new address" goes (paste or upload, comma or semicolon).
  Serving them is `App\Core\Seo\RedirectManager::redirectFor()`, called from the
  404 renderer in `bootstrap/app.php` — not a fallback route, because the pages
  module already holds the one fallback slot. The exception is the shape that
  never 404s: `/index.php?dispatch=…` arrives with `getPathInfo()` of `/` and
  the home page answers 200, so `NormaliseUrl` checks it BEFORE routing (only
  when the path is empty and a query string is present). A target is built from
  `getSchemeAndHttpHost()` — on that request the base path is `/index.php` — and
  the query is stored and looked up normalised.
  A row knows who wrote it (`source`: `auto` from `recordChange()`, `manual`
  from `put()`, `import`) and whether anybody still arrives through it (`hits`,
  `last_hit_at`, counted in `resolve()` — which is therefore ONLY for serving a
  redirect; a module asking "is there one" for any other reason would count a
  visit that never happened).
  `auto` rows are the only ones that come and go by themselves (2026-10-27):
  `redirect_on_rename` (core, on) or `withoutRecording(fn)` pause recording
  them for a bulk edit, and `prune()` — daily at 03:40 — forgets those nobody
  has arrived through for `redirect_forget_months` (12; 0 = never), counted
  from `last_hit_at` or the row's date. `manual` and `import` rows are kept
  for ever; "Keep for ever" on the screen turns an `auto` row into `manual`.
  Rows from before `source` existed (NULL) are left alone.
- The sitemap names each address's translations (`xhtml:link`, from
  `App\Core\Seo\Alternates`, the same class the page's `hreflang` uses) and each
  product's photographs, and becomes an INDEX past 50 000 addresses
  (`/sitemap-1.xml`). SEO invariants follow the usual ownership rule: the
  catalogue states what must be true of a product (no two titles alike, a page
  with something to say, a photograph), `torgash.seo` states the one about
  ADDRESSES — and that one is `manual`, because a fallback route makes every
  path match, so the only honest implementation asks the shop over HTTP.
- **Any page a machine can read is the same page plus `.md`** — `/product/x.md`,
  `/ru/product/x.md`, `/info/delivery.md`. Registered BEFORE the HTML route
  (a slug matches `[^/]+` and swallows the suffix), answered as
  `text/plain` (the honest `text/markdown` makes a browser download it), and
  limited per address by core's `torgash-agent` limiter — the API's own is keyed
  by a token these callers do not have. A page at a root address is a FALLBACK
  route and cannot carry route middleware, so it calls
  `App\Core\Seo\AgentTraffic::enforce()` instead of growing a second rule.
- **`Torgash\Catalog\Seo\ProductFacts` is what is TRUE about a product for a
  machine; `ProductText` and `ProductSchema` only print it.** Both answer for
  NOBODY IN PARTICULAR (`publicPrice()`, never `effectivePrice()`), and stock is
  `stockLabel()` rather than the number — a text rendition that disclosed what
  the HTML page hides is a hole that differs only by an `Accept` header. The
  JSON-LD lives in PHP, not in the blade that used to hold it: the keys it needs
  are an at-sign followed by a word. A module adds to it through
  `catalog.product.structured_data`.
- **`App\Core\Content\Markdown` reads rich-text HTML back as text.** In core
  because the rich-text FIELD is core's. Not a general converter: it converts
  our own editor's output, whose toolbar names every tag it can make — and a
  table (which the toolbar cannot make and a person can paste) passes through
  as HTML rather than being flattened.

**A page is a translation only where its record has words.** `HasPageMeta::pageLanguages()`
reads the languages whose name, title or value is filled (a column, never a method
of that name). PageMeta then advertises only those as `hreflang` alternates. On an
address whose language the record does not have (it shows another language's text
through `t()`'s fallback), the canonical points at the page in a language it has,
the storefront's default first. The sitemap gives the same answer: only those
alternates, and such a record is listed at its address with words. Before this,
"Дрели" was served at the English address as English (audit 2026-10-15).
Russian text typed into the English slot is still "English" to the engine; that is
a data problem (2 categories and 3 products in dev).
