# Exchange: exim, external_id, 1C

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- **An exim column is DECLARED once and expanded by exim** (`Torgash\Exim  SchemaRegistry`): a field marked `translatable` becomes one column per
  language of the shop (`name_en`, `name_uk`, … — the old two-language keys are
  those same columns, so files and profiles from before still read), a
  profile narrows them with `locales` (NULL = every language, one added next
  year included), and `group` places it on the form. A module never names a
  language. `after` runs once the row is saved — a price type's OWN price
  (`price_type:<CODE>`, empty = the type computes it again) is a row of its
  own. A profile's "own template" is core's `App\Core\Content\SafeTemplate`
  (automations' `Template` is it), streamed per row and escaped for the
  format — it used to be Blade, which made editing a profile a way to run PHP.
- **A feed address alone opens only PUBLIC data** (audit 2026-10-15). The
  address is a secret that leaks — forwarded, logged, pasted into a chat — so
  `FeedController` answers 403 for an entity no module declared in
  `exim.public_entities` (catalog: product/category/feature; banners; kits;
  options) unless the profile has its own login. Orders, enquiries, reviews
  and anything a module forgot to declare carry names and contacts: failing
  closed costs a forgetful module convenience, never its customers' data. The
  profile form says so before anybody tries. Every feed answer carries
  `X-Robots-Tag: noindex` and `Cache-Control: private, no-store`.
- **An export file keeps itself current, and its address hands out what was
  built** (2026-10-27, PLAN §59). Two reasons to build, both the profile's
  own (`Rebuilds`): its `schedule` — any cron expression (the form's list
  only writes one; "hourly"/"daily" from before are read as theirs), always
  at the times it names, because some changes leave no row (a promotion
  starting at midnight) — and `rebuild_on_change`: the data it reads is no
  longer what the file was built from, but not sooner than `rebuild_every`
  minutes after the last build. "What it reads" is `exim.watch.<entity>`,
  answered by the owner of each table (catalog for products, warehouses and
  video add theirs; with no answer, the entity's own table), and
  `Fingerprint` is the count plus the latest `updated_at` per table — the
  sum of row hashes where there is no `updated_at` (a pivot: a product moved
  between categories keeps the count). NOT Postgres's row counters: a view
  writes popularity into the products row, and `Popularity` leaves
  `updated_at` alone exactly so that a visit is not a change. A table written
  in the last two seconds makes the fingerprint null — "cannot tell" is
  read as "changed", never the other way. The scheduler asks every minute
  (`exim:rebuilds`), changes are looked for every fifth; a build is a
  `BuildExport` background task, one per profile (`build_task_id`), and
  every build — the queue's, "Download now", a fetch — takes the profile's
  lock, so a fetch that waited for one is handed that one's file
  (`unlessBuiltSince`). `last_run_at` is when a build STARTED and the
  fingerprint is taken before the data is read, so a change made during a
  build builds again. Only the newest file is kept (every run used to leave
  one behind; the dev shop had 96 for one profile), and a deleted profile
  takes its folder. The address hands out the stored file of a profile that
  rebuilds itself and builds only for one that does not. Build bookkeeping
  is saved quietly — a line in the audit trail per build buried real edits;
  the builds are on record as background tasks.
- **A CSV cell never reaches a spreadsheet as a formula**: `CsvRenderer::guard()`
  puts an apostrophe before a value starting with `= + - @ TAB CR` (a number
  stays a number — `-5` is a quantity), and `ImportRunner::unguard()` takes it
  off again, so a file goes round unchanged. A customer named
  `=HYPERLINK(...)` otherwise ran in the manager's Excel on opening the order
  export. A profile's own TEMPLATE in CSV is written as typed — its author
  chose the delimiters, so it cannot be guarded for them.
- Anything an exchange moves carries `external_id` — what the OTHER system calls
  the row (products, categories, orders, users, warehouses; nullable + unique).
  Imports match on it BEFORE the natural key (`sku`, `slug`, `order_number`,
  email), because those are the shop's own and the shop renames them. A line
  whose external id is not known yet still matches by the natural key and the
  schema field then writes the id onto it — that is how an existing catalogue
  acquires its links on the first import.
- 1C exchange is `torgash.commerceml` (CommerceML 2): one URL outside `web`
  (`/commerceml`, plus the hard-coded-everywhere `/1c_exchange.php`), four modes,
  answers that are a bare word on the first line. That second spelling needs a
  FILE: a server hands `*.php` to PHP only when it exists and 404s otherwise, so
  the route alone never hears it. `LegacyEndpoint` writes an eleven-line front
  controller into `public/` on install and deletes it on uninstall (the reason
  `ModuleLifecycle` exists) — it corrects `SCRIPT_NAME`, without which Symfony
  reads the whole path as the script's own name and 1C reaches the homepage.
  Deleted by CONTENT, never by name: a shop mid-migration still has the old
  engine's script there, and that one is left alone and said so on screen. NO queue job — `mode=import`
  does a batch and answers `progress`, which is what the protocol is for. Basic
  auth whose PASSWORD is an `ApiToken` (a credential that sits in a config file
  for years must be revocable on its own). Two ways a thing is recognised, and
  the choice is not ours: rows with `external_id` use it (§58); characteristics,
  their values and price types cannot — those are catalog's tables — so their
  link lives in `m_commerceml_links`, which is also the only place that can hold
  "seen, not decided yet". **An unmatched price type writes NO price and an
  undecided characteristic reaches no filter**: a shop sends retail and purchase
  prices down one wire, and "take the first price" publishes the cost one.
  Field mapping is a SETTING (`ЗначенияРеквизитов` is an open bag and no two 1C
  installations name it alike — a list of names per field, last one present
  wins). Money goes through `Prices`, `Статус="Удален"` disables rather than
  deletes, and uploaded file names are checked per SEGMENT, never by stripping.
  Orders go the other way (`type=sale`: `query` hands them over, `success`
  confirms, `file`+`import` brings answers back). WHICH orders is a condition on
  the two axes an order already has — statuses plus "paid", stated by the shop —
  and stating none sends NOTHING, because inventing an answer writes stock off
  in 1C for orders nobody confirmed. Re-sent by the HASH of the document, so any
  change resends and no list of "fields that count" exists; `offered_hash` only
  becomes `confirmed_hash` on `success`. The document ADDS UP: delivery and
  every `checkout.order.extra_totals` line are `<Товар>` service lines and the
  discount is `<Скидки>` with `УчтеноВСумме=Нет`, so lines minus discount equal
  the total by construction — the delivery service's 1C id sits on the SHIPPING
  METHOD (`shipping.method.extra` / `shipping.method.saved`, this module's
  setting keyed by method id) because a shop bills its courier in 1C and its
  parcel carrier nowhere. Amounts are the books; the charge and payment
  currencies are disclosed as attributes only. **Coming back, 1C may move the
  status and say the money arrived, and nothing else** — a warehouse that
  shipped nine of ten is a document with different lines, and importing it
  changes the total of an order the customer already agreed to; anything more is
  a rule in `torgash.automations`.
- **An import reads ANY system's file through a mapping** (PLAN §58): the
  file's column → one of our fields, or skipped, chosen on a screen and kept as
  a preset (`m_exim_import_presets`, recognised by the header). Exim knows no
  other system: a field declares what other systems call it (`aliases`,
  `alias_patterns`), may take many columns (`per_column`, row key
  `field@column`), and may be `import_only` (never an export column). `set` /
  `after` get `$ctx` — `column`, `row`, `options`, `batch`; the batch
  (`Import\ImportBatch`) is the run's memo, its notes for the admin, and the
  work that must wait for the commit (`items('images')`, fetched by
  torgash.media's `FetchImages` on `exim.imported`). The job carries the FILE,
  never its rows. An upload is a DRAFT (`ImportDraft`) until imported or
  deleted — the screen saves every pick into it, never the cache, so a closed
  tab loses nothing and the import screen can list it; swept after a week.
- **A file is a TABLE whatever it is written in** — `Import\ImportFile::read()`
  opens it by its bytes (not its name): `CsvFile` or `XmlFile`, one interface
  (header, rows, count, `settings()` kept in the draft), so the screen,
  presets, formulas and the job never ask which. An XML record is the element
  repeated most that has elements inside and is not inside another such (a
  YML `<offer>`, a CommerceML `<Товар>`; `xml_record` overrides). Columns are
  the file's own names: `@attr`, `tag` (repeats joined by ", "), `tag
  [name]` for a `<param name>` (the bracket is what "characteristic named by
  the column" reads; `alias_patterns` maps it), `tag@attr`, `a/b`; a YML
  `<categories>` list adds `category` — the path, joined by the import's own
  category delimiter. Streamed with XMLReader, `LIBXML_NONET` and no entity
  substitution: a feed is outside input. YML's tag names are aliases on the
  catalogue's fields (`vendorCode` → SKU, `vendor` → brand, `picture`,
  `oldprice`, `count`); `@id` is NOT guessed as the external id — 1C may
  own that column — the admin picks it.
- **A column may carry a FORMULA** (`Import\Formula`, a "modifier" elsewhere, but an
  expression: `ending($value * 1.2, 90)`, `col("Закупка")`, `&`, `if`). Parsed by
  hand, never eval'd. Kept by column INDEX in a draft, by NAME in a preset
  (`Columns::formulasByIndex/ByName`). A sum over an EMPTY cell is empty, not 0;
  a row the formula cannot work out leaves that FIELD of the row untouched and
  notes why — never the bare cell (the purchase price would land as the price).
  A broken formula blocks "Check".
- **A row that matches nothing may be unable to make a record**, and its
  entity's owner says so in the match: `exim.match.<entity>` returns
  `refused` — an English sentence, why — beside `model`. "Check" counts the
  row as a skip and lists it; the commit skips it with a note
  (`Line :line: skipped — :reason`). The catalogue refuses a new product,
  category or characteristic with no `name_*` in any language
  (`CatalogServiceProvider::namedOrRefused`): it used to reach the NOT NULL
  and roll the whole file back — one mistyped SKU in a price list cost every
  other row. A module whose table requires what a file may leave out does the
  same rather than let the save fail.
- **The console is the screen without the screen**: `exim:import <file>`
  (`Console\ImportCommand`) finds the preset (named, else `forHeader`) and
  guesses the columns with `Columns::guess`, exactly as the mapping screen
  does, and the rows are written by `RunImport` — run in the console through
  `TrackedJob::runHere()`, or queued with `--queue` — so a console import is
  a Background task with its counts and notes (the one-off script that filled
  the live shop left no trace there). The task gets its own copy of the file
  on the private disk, as an upload; the file named stays. Reading settings
  and formulas come from the preset — the command line says only what varies
  per run (`--skip-new`/`--create-new`, `--images`). What the screen shows a
  person, the console refuses: a column that goes to no field and that no
  preset skips (`--skip-unknown` lets it), a formula that cannot be read.
  `--dry-run` is "Check"; `--user` makes the import that staff member's,
  audit trail included (otherwise nobody's, as everything from the console).
- **What an import reads from another system, the export can WRITE** (a
  field with `get` + `on_request`): category paths, brand, characteristics in
  the typed-cell spelling, photo links, prices as written — so an export goes
  through a spreadsheet and back. `on_request` keeps them out of "every field"
  (a feed nobody changed grows no columns); `with` names the relations a
  column reads, eager-loaded by `ExportRunner`. A link to the product's OWN
  photo is that photo (`FetchImages`), never downloaded again.
- **A profile's own template is `Template\FeedTemplate`** — Mustache's subset:
  `{{ path | filter }}`, `{{#list}}` (per element, `.` is the element, names
  looked up innermost first), `{{#x}}`/`{{^x}}` as conditions, `{{! }}`; a tag
  alone on its line leaves no blank line. No expressions: a comparison a feed
  needs is a VALUE the owning module computes (`old_price` only when higher,
  `available`). Parsed once per export; `{{#rows}}` streamed. A template reads
  the columns it NAMES (`TemplateRenderer::usedKeys`); list values are
  `template_only`. The shop around the rows comes from `exim.template.context`
  (catalog: `shop.*`). Saved strictly (a broken tag refuses the form), rendered leniently (an old `{{ $x }}` stays text). Ready templates: `exim.template_presets`
  (`templates/*.xml`, `@lang@` = content language). Editor: core's
  `/assets/js/code-editor.js` (`data-code-editor`, format/compact buttons). A CSV is RFC 4180 both ways — no escape character.
