# Money: exchange rates

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- Exchange rates can fetch themselves: `App\Core\Currency\RateSource` answers
  only "what is one unit of each of these worth in MY base currency", and
  `RateUpdater` owns the rest — crossing into the shop's currency
  (`rate(X) = books_in_base / X_in_base`, never the other way round), the markup,
  the guard, the write. **Chosen by the currency it quotes in, never by
  country**: `auto` (RateSources::AUTO) is every source quoting in the books'
  currency, then the ECB; the picker lists those first. A chosen source is
  followed by the same fallbacks (`rates_fallback`), each asked only for what
  is still missing (`RateUpdater::updateFrom`), and the report names who
  answered and who failed. A source that stopped answering is caught by WHEN
  each rate last moved (`Currency\RateFreshness` — the bell and the guard's
  `core.currency_rates_fresh` ask the same class), because last month's rate
  looks exactly like today's. Presets are NBU, CBR and ECB; anything else is `JsonFeed`
  (url / its currency / dotted path with `{code}` / inverted) plus the currency's
  own `rate_key`, which is how `BTC` becomes `bitcoin` — a module registering a
  source through `core.currency.rate_sources` is for one with a secret or a
  signature, not for a plain endpoint. Because a rate change reprices every
  product priced in that currency, `rates_max_change` refuses a rate that jumps
  further than the shop allows rather than repricing the catalogue from a broken
  feed.

- **A derived price is rounded to the books currency's places**
  (`Torgash\Catalog\Pricing\Prices::derive()`): a price typed in euros is
  stored in dollars rounded to what the dollar is SHOWN with (`decimals`) — to
  the cent when it shows cents, to the whole dollar when it does not. Before
  this a shop keeping dollars without cents showed €89 as "$100" on the product
  and charged $100.28 at the till (the bill prints the exact charge). Price,
  list price, tiers and price-type overrides are rounded; the cost price is
  not (nobody's shelf price). Changing the books currency's `decimals`
  announces `core.currency.precision_changed` and every derived price is
  rebased.
- **Money worked out from a percentage is rounded the same way**:
  `round_money($minor)` (`Currencies::round()`, the step is
  `Currencies::step()`, which `Prices` uses too). A customer group's
  discount, a price type's markup, a promotion's tier, a kit part, the
  options' percentage rules, a coupon, an order discount, a deposit and a
  percentage delivery rate all go through it. Before this a shop in whole
  hryvnias showed 51 396 ₴ on the product and asked 51 395.76 ₴ in the
  basket (the first clean install, 2026-10-27). Taxes do NOT: they keep
  `torgash.taxes`' own rounding setting and are counted to the kopeck.
- **An amount never breaks inside itself**: `Currency::format()` writes every
  space in it (thousands, beside the symbol) as a no-break space, so a narrow
  column cannot print "$7" over "440". Tests that expect a formatted amount
  spell that space as `\u{00A0}`.

## A price per unit is finer than an amount (PLAN §65, 2026-11-01)

- **Two scales.** An AMOUNT — a line's total, an order's subtotal/total,
  delivery, a tax, a payment, a refund, a gift card's balance, a coupon's or a
  cart promotion's fixed value — is in minor units (1/100) as always:
  `format_price()`, `round_money()`, `Order::money()`, `admin_price()`. A
  PRICE PER UNIT — a product's price/cost/RRP and their `_original`, a
  quantity tier, a price-type override, a markup rule's amounts, an option's
  amount, a kit slot's discount, a CATALOG promotion's money value, a stock
  document's/batch's unit cost, `prior_lowest_price`, the price history, a
  filter page's price range, a wishlist's `price_at_add`, an order line's
  `unit_price` and its options' `amount` — is in PRICE UNITS, 1/10^P, P being
  core's `price_places` (2–4, default 2 = minor units, so a shop that never
  touches it runs the old arithmetic exactly).
- **`App\Core\Support\PriceScale` (`price_scale()`) is the only arithmetic.**
  A typed price → `fromMajor()` (digit by digit, never a float; `rule()` refuses
  more places than kept); a worked-out price (a percentage off, a markup, a
  converted price) → `round()`; price × quantity → `amount()`, rounded ONCE,
  half away from zero. `Lines::money()` gives `unit_price` / `extra_price` in
  price units and `extra_total` / `line_total` in minor units — anything that
  adds a line's money reads `line_total`, never `unit_price × qty`.
- **Printing:** a price per unit is `format_unit_price()` / `admin_unit_price()`
  / `Order::unitMoney()` / `adminUnitMoney()` — the currency's places, then
  only the places that mean something (0.012 $, 999.00 $; converted: until
  rounding loses < 0.5%). A form field: `price_input()` and
  `step="{{ price_step() }}"`. A machine (schema.org, feeds, 1C):
  `price_scale()->plain()` / `trimmed()`; a feed template's `| price`
  filter, `| money` stays for amounts. The API: `api_price()` (exactly P
  places) and `api_money()` (two), decimal strings with a dot, read back the
  same way.
- **A percentage off a product** (customer group, price type, catalog
  promotion, kit slot, option rule) is taken from the PRICE PER UNIT and
  rounded to a price unit — the card shows the price the line multiplies. A
  discount on the ORDER (coupon, cart promotion, points) is taken from the sum.
  Taxes are worked out on the line's amount.
- **A partial return** owes `Eligibility::share()`: the difference of two
  cumulative shares of the order line's `line_total`, so the parts add up to
  the line exactly.
- **Changing P** rescales every declared column in ONE transaction
  (`App\Core\Support\PriceColumns`, the `core.price.columns` hook — a table and
  its columns with an optional `where`, or `PriceColumns::jsonLines()` for
  prices inside a jsonb list). Fewer places are refused while any price would
  lose a digit (the settings screen names how many and which SKUs); a failed
  move puts the setting back. **A module that stores a price per unit
  declares it there**, or a change of P leaves that price 100× off.
- Columns holding a price per unit are `bigint`: at four places an `integer`
  stops at 214 748 of the currency.
