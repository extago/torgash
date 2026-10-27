# Search (torgash.search)

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- **Search is `torgash.search`, a registry of SOURCES** (`search.retrievers`:
  key => class implementing `Torgash\Search\Retriever`, weight, `fallback`).
  `Engine` normalises each source to its best answer and sums by weight; order
  of attempts: as typed → other keyboard layout → fallback (typos), and how it
  read the query is returned and SAID on the page. A meaning-based (vector,
  LLM-written) source is meant to join as its own module. The document is a
  table of this module (`m_search_documents`), built in PHP by `Indexer` —
  name in every language, SKU (as typed and run together), brand, features
  whose `shown_in` holds `search` (a place registered through
  `catalog.feature.places`) — each piece as written (`simple`, for prefixes)
  and stemmed by ITS language. A save only MARKS products (`Pending`); they are
  rebuilt once on `terminating()` / after a queued job. **A typo is a READING
  of the query, not a source**: `Speller` corrects words the catalogue does not
  know into the nearest words of its own dictionary (`m_search_words`, counts
  moved by `Indexer` per document, recounted on a full rebuild) — trigrams
  (GiST KNN) FIND candidates, EDIT DISTANCE decides, because no trigram
  threshold separates a typo from a false friend (measured). A word that NAMES
  products is never corrected; one only written in a text may be, towards a
  naming word; numbers never. Found as typed but a correction finds MORE →
  `didYouMean`, offered, not applied. **The shop's own hand is `Rules`**
  (synonyms, exclusions, pinned phrases — module tables, screens under
  Marketing → Search): a synonym is another READING of the query (every
  reading runs, each product keeps its best, a rewrite ×0.85, `instead` drops
  the typed one), an exclusion is a filter after the readings (trigger = the
  START of a query word; target = a word starting a word of the NAME, or a
  category with its subtree — the surer one), a phrase's products lead
  through a retriever (`pinned`, weight 10) only when the query IS the phrase.
  Everything an admin types is normalised by `Rules::entries()`, exactly as a
  query is. What fired is listed on the trial screen. **Statistics are
  `Stats`, one row per QUERY per DAY** (`m_search_stats`, no person, session
  or address in it) plus clicks per query and product (`m_search_clicks`). A
  search counts once per visitor per query per day — the results page's first
  page, or a product opened from the window; typing never, a crawler never
  (catalog's `Popularity::isBot`). A click is a product page opened (the
  catalogue's `catalog.product.view`) while it was an ANSWER ON SCREEN — the
  last few answer lists live in the session, so no link carries a tracking
  parameter and a product opened from a category is never one. Clicks lift a
  product for THAT query (`clicks` source, weight 0.5, log-scaled); popular
  queries reach the window only when they find something and pass
  `popular_min_searches`. The catalogue's
  `/search` asks `catalog.search` and gets plain data (ids in order,
  `read_as`, `how`), keeping its own query without the module. Gotcha:
  Postgres parses `AB-100` as `ab` + `-100` (a negative number) — a hyphen
  before a digit is turned into a space before indexing.
  The live window is an HTML fragment (`search.suggest`, rate-limited,
  private), attached by the module's script to any `form[data-live-search]`;
  other modules add sections through `search.suggest.sections`
  (`[{title, items: [{label, url, hint?}]}]`) — asked only when the visitor
  searches the SITE (`Torgash\Search\Scope`). The CATALOGUE is the default;
  `search_site` is what puts the catalogue/site choice in the box (`?scope=`
  radios drawn INSIDE the theme's form through `storefront.search.controls`,
  remembered in the session); the site means the other modules' pages, with
  products beside them only under `site_includes_catalog` — a site search
  without them asks the engine nothing and is not counted in the statistics.
  Sections reach the results page as `sections` in the `catalog.search`
  answer. More products arrive as the window scrolls (a
  `data-live-search-more` marker the server writes, replaced by the next
  batch); a buy button draws BOTH states and the script flips them on success
  (never on `HX-Retarget`). Bold is `Highlight` — word starts and shared
  stems only, never the middle of a word. The panel lives in `<body>`,
  never inside the search form — its "add to cart" forms would be NESTED
  forms, and the button submitted the search (measured).

- A product that reached the table past its model's events (a raw insert, a
  restored dump) has no search document and cannot be found. The search screen
  shows indexed / total; `search:index-missing` writes the missing ones nightly
  (`Indexer::missing()`).
