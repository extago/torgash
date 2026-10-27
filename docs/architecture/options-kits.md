# Options, codes, option files, re-configured lines, kits

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- **`torgash.options` is what a product ASKS before it can be bought** (PLAN
  §33.4): a GROUP is one question (`select` / `radio` / `checkboxes` /
  `quantities` / `rows`) and a CHOICE is one answer, of three kinds — `plain`
  (a service; folds into the line's price), `product` (a real product; becomes
  a line of the ORDER with `parent_key`, so stock, 1C and returns see goods)
  and `source` (a whole set from `catalog.product_sources`, and only what is
  STILL in that set can be bought — without that check the value is a product
  id a client types). Prices are an open registry, `options.price_rules`
  (`fixed`, `percent`, `fixed_plus_percent` — the owner's warranty, 500 + 10%
  —, `multiplier`, `product_price`, `free`), and every rule answers ONE
  question: how much does this ADD to one unit, measured against the product's
  own price BEFORE any option. That is what makes a configurator explainable:
  the order of the groups never changes the money and a multiplier can never
  compound. An amount is money and obeys the money rule (`amount` derived,
  `amount_original` + `currency`, rebased on `core.currency.rate_changed`
  through `Prices::row()`); a percent is not money and needs none of it.
  **A shared group (no `product_id`) is placed by core's DISPLAY RULES and is
  never re-priced per product** — its price is a formula read against whatever
  product it is asked on, which is the whole of §33.8's answer to the usual
  global options; a product may refuse it (`m_options_group_exclusions`) or
  COPY it into one of its own, and the copy excludes the shared one so nobody
  is asked twice. `Configurator` is the one place a configuration is
  normalised, validated and priced — validated again at placement through
  `checkout.order.items`, because a cart sits for a week and a choice can be
  switched off in it. The page's price comes from that same class over HTMX
  (`options.quote`), never a price map in JavaScript. A module's tab of the
  product form is `catalog.product.form_tabs` (rendered inside the form;
  a small one — a switch and a field or two — says `'section' => true` and
  becomes a headed section of the shared "Modules" tab instead);
  the buy box's own seam is `cart.buy_box.fields`. **Sold out is an ANSWER;
  gone is not.** A product still in the set that cannot be bought is drawn
  `disabled` with the shop's own word for its status — a list that quietly
  shortens itself leaves somebody hunting for what the page they came from
  advertised — while one deleted, disabled or taken out of its set is not drawn
  at all, there being nothing to tell anybody. The same in all three places a
  set becomes controls (the form, an added row, the search-narrowed list), and
  `disabled` INFORMS rather than guards: `Configurator::validate()` refuses it
  again for anyone posting by hand. **An answer whose product has gone is
  silently not drawn, and that is how a product stops being
  buyable**: when EVERY answer goes, the question is not drawn either, while a
  REQUIRED one is still validated — the customer is told to choose a control
  that is not on the page. So `Invariants\OptionInvariants` states both to
  `guard.invariants` (the required one a refusal, a dead answer a warning), and
  both ask `Configurator` (`productFor()` / `cheapestInSource()`) rather than
  keeping a second idea of what "offerable" means.
- **A row that has to leave the database carries a `code`**
  (`App\Core\Support\HasCode`, beside `HasTranslations` because two modules
  that do not know each other need identical behaviour). An id identifies a
  row only inside one database, and `exim.match.*` asks about ONE column — so
  option groups, option choices, kits and kit slots each have a unique code,
  and their exim profiles reference everything by code or SKU and never by id.
  It is `external_id`'s sibling, not its replacement: that one is what the
  OTHER system calls the row, this one is what WE call it. ASSIGNED, never
  demanded (a shop that exports nothing must not name two hundred warranty
  choices), derived from whatever the row already calls itself with a fallback
  for a name that slugs to nothing, and a code the shop typed is never
  overwritten — including by a rename, or it would stop being the thing it
  exists to be. `replicate()` carries a code into the copy, so the trait nulls
  it on `replicating` and regenerates one already held by another row.
  **A reference that cannot be resolved leaves the row where it was**: an
  empty product SKU on a group means "shared", which is a real answer and not
  the same as "the product was not found". Money in such a file is the TYPED
  amount plus its currency, in minor units, with the currency column BEFORE
  the amount — the books column is derived through `Prices` at both ends, or
  the first import into a shop with another base currency rewrites the promise.
- **A file answer is the thing being BOUGHT, so it is never re-encoded.**
  `Torgash\Options\Files` is the one place a customer's upload is written, and
  it is deliberately not `Enquiries\Attachments`: that one hands a photograph
  of a fault to `ImageProcessor`, which shrinks it, and a print layout shrunk
  to 2000px is no longer a layout. The bytes are stored as they arrived, so
  the safety is elsewhere — the file rides the add-to-cart POST (no endpoint
  of its own), the extension comes from what the BYTES are and must be on the
  shop's list, `Files::NEVER` refuses executables and anything a browser runs
  in the page's origin (SVG included) whatever a shop types, and the disk is
  private under a name of ours. A ROW (`m_options_files`) rather than a path
  in the bag: the line key is a hash of the configuration, so the customer's
  own filename must live outside it, and an abandoned basket's file has to be
  sweepable by query. The configuration carries only a TOKEN. Serving is a
  signature or `orders.manage`, 404 for anyone else, always as an attachment,
  on a route registered OUTSIDE `StorefrontRoutes` (a signature covers one
  exact address). A file an order holds is claimed on
  `checkout.order_placed` and on `checkout.order.changed` — the second because
  a manager re-configuring a placed line attaches a file through no basket at
  all — and everything unclaimed is swept after `files_keep_days`. Cart's buy
  box goes multipart exactly when `cart.buy_box.fields` is non-empty.
- **A placed order's line can be RE-CONFIGURED, and that is a correction, not
  a demolition.** For it to be possible at all a configured order line now
  carries `config` and `base_price` beside the `options` snapshot — that
  snapshot is for READING (labels and money, frozen so renaming a choice never
  rewrites an invoice) and cannot be re-configured from. `OrderLines::forLine()`
  is the ONE derivation, used by `fromBasket()` at placement and by
  `Torgash\Checkout\Orders\LineConfig` in the admin, so a hand-edited order
  comes out shaped like a placed one. **The line's KEY never moves**: in a cart
  it is an identity computed from the answers, in an order it is the name of
  something already sold (the warehouse breakdown, the admin URL, a 1C
  document, a return all point at it) — while the PARTS' keys are built from
  the answers and change with them, which is exactly what lets `OrderStock`
  credit the old module and take the new one through its ordinary before/after
  diff. Money rule: **the order's own price for the product, today's rules for
  the answers** (hence `Lines::parts()`'s optional `$base`, which a cart never
  passes). Checkout names no configurator: `checkout.order.line_configurable`
  is the cheap per-row question, `checkout.order.line_config_form` draws the
  controls once, and refusal is not rewritten at all — the submission goes
  through `cart.line.config`, so what the shop has switched off cannot be sold
  from the back office either. A part is configured where its parent is.
- **A product that demands an answer has no price — it has a cheapest one.**
  `Torgash\Options\FromPrice` builds the cheapest set of REQUIRED answers
  (repeated passes through `Groups::asked()`, since answering one question can
  reveal another required one) and prices it through the very code that will
  price the cart line, so "from 45 000" and the configurator's total for those
  same answers are one calculation rather than two. Optional questions never
  raise it (the product really is buyable without an extended warranty) and a
  kit never could. The catalogue asks through `catalog.price.from` and prints
  through `Torgash\Catalog\Pricing\ShownPrice` — the ONE place the card, the
  product page, `ProductFacts` and the exim `price_from` field agree, because a
  feed quoting less than its own landing page is what a marketplace penalises.
  Both filters in order: `from` first, then `catalog.price.display`, since tax
  is charged on what is bought and not on a price nobody pays. The cheapest
  answer to a `source` choice is found by the `price` COLUMN (the catalogue's
  own "Cheapest first" already answers that question that way, and loading a
  category to ask each row for `effectivePrice()` is the N+1 this engine
  refuses) — so a promotion can leave the "from" slightly HIGH, the safe
  direction. A struck-through old price is never printed beside a "from": it
  is a fact about the bare product and claims a discount nobody gave. In
  JSON-LD this is an `AggregateOffer` with `lowPrice`, the vocabulary's own
  word for it. A card asks per product, so `Groups::prime()` reads the page's
  groups in one query off `catalog.products.listed`.
- **`torgash.kits` is several SEPARATE products that are cheaper together**
  (PLAN §33.5) — the other half of §33.2's split: an option changes what one
  line IS, a kit leaves three goods three goods, so deleting the phone leaves
  the glass in the basket and takes only the discount. A kit belongs to one
  product or is placed by core's DISPLAY RULES, and then the product whose page
  it is drawn on is the main one. A SLOT is a fixed product or a set from
  `catalog.product_sources`, optionally narrowed by `match_features` — a LIST
  of characteristics, ALL of which must hold the main product's own value
  ("a glass for THIS model", "trousers of the jacket's colour AND fabric"),
  which is compatibility with no table of pairs; a main product that cannot
  answer ONE of them is offered NOTHING there, and two slots never fill with
  the same product. **A kit is two different things** (`type`): `simple` puts
  several SEPARATE lines in the basket and carries no discount, `extended` is
  ONE line whose PARTS are the rest of the set and is the only one that may.
  Separate lines plus a discount is the measured hole the split closed — a
  hundred glasses at half price behind one phone — and as a part the quantity
  is arithmetic rather than a rule. **The discount is expressed as the part's
  PRICE**, never a negative beside a full one: the child order line says what
  the glass really cost, so 1C, a refund and a return each read one number.
  Nothing is frozen onto the line but which kit and slot it was bought in, so a
  kit whose dates ran out charges full price for what is already there.
  **One line carries one discount** (§33.8): kits SUBTRACTS the set's own money
  from what promotions may discount (`promotions.cart_lines`) and from a
  coupon's base (`checkout.coupon.base`) rather than dropping the line, which
  would take a coupon off a full-price phone for the sake of a cheap case.
  **A kit breaks SILENTLY** — an unfillable slot is left out, an unassemblable
  kit draws nothing — so two answers: `whole_set_only` is the shop's tick for
  "do not show it half assembled" (a different question from a slot's
  `required`, which is about the CUSTOMER not unticking it), and
  `Invariants\KitInvariants` answers `guard.invariants` with "every slot has
  goods behind it" and "every kit written for a product is shown on it", the
  second asked of `Offer::for()` itself rather than reasoned about.
