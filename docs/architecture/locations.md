# Locations: the town window, case forms, locality packs

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- **"Your town" is ONE window owned by `torgash.locations`** (header corner
  through the theme's `storefront.header.start`; body `locations.dialog`,
  fetched when it opens and again when a town is picked, so the sections below
  follow the choice): GPS (`places.nearest`), country, town suggest, then
  `locations.dialog.sections` — `torgash.warehouses` adds the points in that
  town and the customer's switch "stock at the chosen shop" — and ONE form
  posted to `locations.save`, which announces `storefront.place_chosen` with
  `explicit` (checkout then REPLACES the remembered town) and
  `locations.dialog.saved` (the warehouses module stores the point and the
  switch). `point_availability` is `mark` / `hide` / `choice` (the customer's
  switch, `PointStore::filtering()`); a filtered listing says so through
  catalog's `catalog.listing.notice`. "We will bring it by <date>" is
  `Transfers`: the smallest `transfer_days` of another warehouse holding the
  product, working days by setting, primed per page like `StockAllocator`.
  The old header point picker stands down while the window exists.
- **"Only to visitors from…" are this module's display rules** (PLAN §51,
  `VisitorRules`): `visitor_town` (searched through `places.suggest` — the
  registry holds 174 000 towns, never listed into a page), `visitor_region`,
  `visitor_country`, `visitor_town_chosen`. They ask checkout's
  `storefront.visitor_place` — the town chosen in the window or named at
  checkout — and NOTHING is guessed from the visitor's IP (the owner's call:
  the header already asks). The GUESS lives in one place only, the question
  "Is your town …? Yes / Change" (`town_prompt`: off / the shop's town /
  the browser's location / by IP from an .mmdb file — `TownGuess`, 2026-10-20):
  printed once a visit to a visitor with no town, it stores nothing — "Yes"
  posts to `locations.save` like a hand-picked town, and only then do the
  rules see it. No town chosen is no town: "is Kyiv" is a No, "is
  not Kyiv" a Yes, and "has not chosen a town" is its own condition for the
  banner such a visitor should see instead.
- **A town's case forms are derived and stored only where corrected.**
  **Which cases a language HAS is the language's own setting**
  (`languages.cases`, a jsonb LIST of `{name, example}` numbered by position,
  typed on the language form, read through core's scoped
  `App\Core\Support\GrammaticalCases`; empty = one form, RU/UK defaults filled
  only where nothing was said). The case names are content, never `__()`.
  DERIVING a form is code and a registry, `locations.declension.rules`
  (language => callable(nominative) => [case => form]), which LOCATIONS
  answers for ru/uk in `Torgash\Locations\Declension` — so any language gets
  cases with no code, and one with cases and no rules reads its nominative
  until the shop types a form. The placeholder picker offers a token's
  `argument.choices` (a closure) as clickable values: `{location:N}` lists
  every declining language's cases in words. `m_locations_places.forms` holds `{"ru": {"6": …}}` for
  what a person fixed, and `Place::form($case, $locale)` is the one read path —
  typed, else derived, else the plain name, at ONE locale with no
  cross-language fallback. Derive-on-read rather than a generator writing 192 000
  rows: those guesses would all look like the shop's own decisions and the next
  improvement to the rules would reach none of them. The known rough edges
  (feminine `-ь`, a fleeting vowel, an adjectival name) are named in the class
  and left alone rather than guessed at. `{location:N}` / `{location_region}`
  are registered by LOCATIONS, reading `place_id` out of the context — which a
  record hands over by having `pageMetaContext()`, merged in by
  `Torgash\Seo\Templates::context()`, so neither module names the other's
  classes.
## Locality packs (torgash.locations)

**A new shop gets its country's map on install.** `LocationsServiceProvider`
is a `ModuleLifecycle`: installed into a shop whose core `default_country` is
set, it imports `countries.csv` (on an empty table only the shop's country is
left switched on) and queues `ImportPack` for that country's pack when one is
in the packs folder and the country has no places yet. The installer writes
the country BEFORE the modules and runs the queue right after them, so the
demo's pickup shops find the capital. A shop with no country, and the test
schema, get nothing. The Import screen opens with "Where the towns come from"
(sources, the two builders, licences) while the shop has no places; each pack
shows its `manifest.json` source. Before this a new shop had no country, no
region and no town, and nothing said where they come from (2026-10-27).

Two builders write the same pair of CSVs, and the importer cannot tell them
apart:

- `tools/build-locations.php --country=RU --languages=en,ru --source=D:\AI\database-cities`
  — GeoNames (CC BY 4.0). Names come from `alternateNamesV2.txt`, whose language
  tags lie in three directions, so a name is SCORED, never taken in order: wrong
  alphabet becomes a search alias, `ru`-tagged Ukrainian is re-tagged by its
  letters, and matching the source's current name outweighs the "preferred"
  flag (that is what stops a renamed village keeping its old name).
- `tools/build-locations-osm.py --country=UA --languages=en,uk,ru --pbf=…\ukraine-latest.osm.pbf --match=<old pack>`
  — OpenStreetMap, **ODbL 1.0 (share-alike)**: a pack built from it may only be
  redistributed under ODbL with "© OpenStreetMap contributors" (the manifest
  records this). Needs pyosmium and shapely — venv at
  `D:\AI\database-cities2\.venv`. Both UA and RU are built from it now, and it
  wins outright on both: ~100% local names against 82%/87%, a district for
  ~100% of settlements against 100%-by-derivation and 1%, and real postal codes.

  A settlement is put in its district one of two ways, `--hierarchy=`:
  `codes` (Ukraine — KATOTTG sits on both the settlement and the boundary, so
  the tree is a string prefix join) or `boundaries` (everywhere else — the admin
  boundaries are cut into a small file with `BackReferenceWriter`, assembled
  into polygons and queried point-in-polygon). The cut is what makes that two
  minutes instead of an hour: assembling areas straight from a 4 GB extract
  builds every building in the country.

Every name is one column per language, keyed by the code itself — `name_en`,
`name_uk`, and since 2026-08-29 `district_en`, `district_uk` too. A district is
translated because it is printed beside the town to tell two namesakes apart
("Шевченкове (Броварський район)"), so a reader who cannot read it cannot tell
them apart. Where OSM has no `name:en` the builder ROMANISES the local name
(BGN/PCGN for Russian, KMU 2010 for Ukrainian, both without the apostrophes):
measured, that is the difference between a Russian pack 19.7% readable in Latin
and one that is 100%, and the manifest counts how much was derived. What it
does NOT do is translate the generic word — "Кандалакшский муниципальный округ"
comes out as "Kandalakshskiy munitsipalnyy okrug", not "Kandalaksha District".
A pack from before this (one bare `district` column) still imports: the value
lands under `und`, undetermined, because the file does not say which language it
is in, and `t()` falls through to it in every locale.

A place and a region are identified by `ref` (`geonames:703448`, `osm:n26150422`),
never by the source's own id and never by a region code — ISO calls
Dnipropetrovsk "12" and GeoNames calls Kyiv City "12". A pack switching sources
puts `previous_ref` on each row; the importer moves the key onto the existing
row before the upsert, so the row keeps its id and every shipping zone pointing
at it. Region renumbering runs in two phases (park on `~<id>`, then assign),
because a unique code cannot be handed over one row at a time.
