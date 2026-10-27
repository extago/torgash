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
