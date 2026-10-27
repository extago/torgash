# Cart, wishlist, comparison

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- **Goods nobody sends** (PLAN §40.10/2): `needs_delivery` on the product
  (`Product::needsDelivery()`, which a module may veto through
  `catalog.product.needs_delivery` — an electronic gift card is never
  delivered) and `Basket::needsDelivery()` — any line that needs it. A basket
  that does not gets an empty Quote from shipping, `PlaceOrder::rules(…,
  $needsDelivery)` asks for no town, street or method, the checkout hides the
  step, and `Order::hasDelivery()` hides the shipping row in print.
- **Nothing goes into a basket that is not there to sell** (audit 2026-10-15).
  `Product::orderLimit()` is the one answer — null when the number does not
  limit it (a preorder, an untracked status), 0 when it cannot be bought (not
  orderable, a disabled card), else the shelf — and `CartManager` asks it at
  every door it has (`add`, `set`, `update`: the page, a list, a kit, one
  click, the API), refusing only an INCREASE past it, so a basket the shelf
  emptied under can still step down. `items()` marks such a line (`problem`),
  the cart page and the API say so, and `PlaceOrder` asks again with the rows
  locked (`OrderStock::refusal()`). Before this the stepper's cap was the
  browser's alone: the server took fifty of a product with none left, and the
  checkout placed it. A cart a MANAGER fills on somebody's behalf (an exchange)
  is `->onBehalf()` and is not held to it, exactly as `PlaceOrder`'s `$as` is
  not. A refusal from a list answers with the unchanged badge and a corner
  notice (`partials/refused`); from the product page, with the buy box.
- The cart is a ROW addressed by a token (`m_cart_carts`), not the session — the
  session holds only `cart_token`. `CartManager`'s surface is unchanged;
  `useToken()` points it at a cart for a client that has no session. Reading
  never creates a row (the header badge asks on every page), `clear()` empties
  rather than deletes, and a pre-token session cart is migrated once on first
  touch.
- **A cart line is a record with a KEY, not "a product and a number"**
  (`Torgash\Cart\Lines`, PLAN §33.3): `{key, product_id, qty, config}`, and the
  key of a line with NO configuration is its product id — which is what keeps
  every link, route and API call that addressed a line by product id
  addressing the line it meant, and keeps a plain shop from ever seeing a hash.
  The old `{product_id: qty}` map is read for ever (it is somebody's basket).
  Cart understands nothing about a configuration: a module normalises it
  through `cart.line.config` (the key is computed from the normalised form, so
  two spellings of one build are one line) and prices it through
  `cart.line.parts`, a list of PARTS (`label`, `qty`, `per_unit`, `amount`,
  optional `group`/`product_id`/`sku`/`weight`/`url`). Asked for EVERY line,
  configured or not — a kit assembling itself prices a line nobody configured —
  and an `amount` may be NEGATIVE, which is how a kit's discount reaches the
  line without the cart knowing what a kit is. Hence three numbers per line:
  `unit_price` (product + what repeats per unit), `extra_total` (charged once
  for the line) and `line_total`, the only one anybody adds up. A line's
  fields go through jsonb, which does not keep key order — read them by name.
  **The ORDER has more lines than the cart** (`Orders\OrderLines::fromBasket`):
  a part that is itself a product becomes its own line with `parent_key`
  (stock, 1C and returns all deal in goods), a per-unit service folds into the
  parent's `unit_price` and is named in its `options` snapshot, a once-per-line
  service gets a line with no product — and the two lists add up to the same
  money by construction. Printing is `OrderLines::tree()`; the admin, the
  invoice, the confirmation, the letter and the summary all use it. A line is
  addressed by key there too (`/admin/orders/{order}/items/{line}`), because
  one order can hold the same product twice.
- **Buying from a LIST is cart's, drawn where catalog says**
  (`catalog.product.card_actions`, a string the card prints beside the price —
  the card is now a DIV holding the link, because a form inside an `<a>` is
  invalid and its click is the link's). `Torgash\Cart\ListBuy` answers per
  product, primed for a page off `catalog.products.listed`: MUST IT BE CHOSEN
  (variations — a `variation_group` with more than one visible member — or
  anything a module says it asks, `cart.product.asks`, which options answers
  from its primed `Groups::has()`) and IS IT IN THE BASKET AS ITSELF (a line
  whose key is the product id). Chosen first → a cart with a gear that opens
  `cart.choose` in the ONE shared dialog (`#torgash-cart-dialog`, drawn on
  every page through `core.footer`); the window reuses the product page's own
  buy box, so a choice there cannot differ from one on the page. What follows
  an add is the setting `after_add` (dialog / toast / nothing), answered
  OUT-OF-BAND beside the badge every buy form already targets; a form may ask
  for another of the three words (`after_add`, the search window does). An add
  made from the dialog that did not refill it closes it; a refusal
  (`HX-Retarget`) touches nothing.
  A caller listing ONE variation as itself (the variants table) passes
  `variation => chosen`; `ListBuy::mustChoose($product, true)` then asks only
  `cart.product.asks`. Under the buy box's row of small actions,
  `cart.buy_box.bottom` (`hook_render`) is the place for a whole-width quiet
  control that is too long for an icon and a word.
  **Quick view** (PLAN §57, setting `quick_view`, on by default) is the same
  window, wider (`data-dialog-wide` → the dialog's `has-[…]:max-w-4xl`): an
  eye in the card's corner (`catalog.product.card_corner`, after the heart
  and the comparison) → `cart.quick`, the pictures, price, short description,
  the variation axes (`partials/variation-axes`, shared with `cart.choose`,
  each redrawing the window it is in), the page's own buy box and the short
  list of characteristics the shop chose for beside a buy button. Switched
  off, the eye is not drawn and the route answers 404.
- **Favourites are `torgash.wishlist`: a list is a ROW addressed by a token,
  as a basket is** (`m_wishlist_lists` + `m_wishlist_items`, rows so "most
  wished" is a GROUP BY and a deleted product leaves by cascade). A guest's
  list lives by the token in their session; signing in (the Login event, a new
  account included) pours it into the account's without duplicates
  (`Wishlists::adopt`). It holds a PRODUCT, never a configuration, with
  `price_at_add` (books) for price-drop notices. Reading never creates a row.
  The heart is one toggling form answered with itself plus the header counter
  out of band; it sits in the photograph's top-right corner of a card —
  catalog's `catalog.product.card_corner`, drawn OVER the figure from outside
  the link, where every `.btn` gets the frosted look and shows on hover (a
  pressed one, `aria-pressed="true"`, always; a phone, having no hover,
  always) — and under the buy button (`cart.buy_box.actions`). Comparison
  joins the same corner. On a hover screen a card lifts and opens its short
  description in a panel beneath itself (`card_description_on_hover`); the
  grid's dividers are therefore each cell's TOP/LEFT border, not right/bottom
  ones clipped by `overflow-hidden`, which cut that panel off on the last row.
  A card with several photos FLIPS through them under the pointer
  (`card_photos_max`, 0/1 = off): one invisible strip of the figure per photo
  and a thin mark per photo just under it, drawn only on a hover screen, driven
  by ONE delegated `card-photos.js` printed through `core.footer` (cards also
  arrive over HTMX), fetching nothing until the pointer arrives. The
  header's counters go through the theme's `storefront.header.icons`.
  **Its letters are the customer's own consent** (`notify_price` /
  `notify_stock`, off until THEY switch them on, only with an account — a
  letter needs an address) and each carries a SIGNED "stop these letters"
  (`wishlist.unsubscribe`, outside StorefrontRoutes). A worker has no request,
  so the list remembers `locale` + `storefront_id` on every write
  (`Wishlists::touch`) and `Letters` builds links in that locale's shape and
  money in that shop's books. A price drop is LOOKED FOR (`CheckPriceDrops`,
  daily and after `core.currency.rate_changed`): `publicPrice()` against
  `price_at_add`, past `price_drop_percent`, only for what can be bought now,
  ONE letter per list, then `price_at_add` becomes the told price — a price
  that rose moves nothing. A return is HEARD (`catalog.product.restocked`)
  and collected per action by `Restocks`, flushed on `terminating()` and after
  a WORKER's job (never a sync one — that would split one save's returns into
  a letter each). Sharing is a `share_token` issued on request and replaced to
  take a link back; the shared page shows products and never whose list it
  is. The admin screen (`wishlist.stats`) is products and numbers, never
  people — its point is "wanted and cannot be bought".
- **Comparison is `torgash.compare`, kept in the SESSION** (`Comparison`,
  `product id => added at`): a comparison is the business of one visit. It
  compares ONE group at a time — a product's DEEPEST enabled category (the
  narrowest is the most comparable; smaller id on a tie, 0 for none) — capped
  by `per_group_max`, where one more pushes out the first and the notice says
  which. The table (`Compare\Table`, rows as DATA) shows exactly what the
  product page's specifications show (`Feature::shows('specs')`, in the
  catalogue's group/sort order, free-form characteristics under "Other"); a row
  DIFFERS when the products do not all say the same, and "no value" is a thing
  to say. Products are the table's COLUMNS; the names' column sticks left, and
  a thin strip of names sticks under the header once the products' row has
  gone by — both read two widths, `--cmp-label` / `--cmp-col`. The button is
  in `catalog.product.card_corner` (after the heart) and `cart.buy_box.actions`;
  the counter in `storefront.header.icons`. `--torgash-header-h` (app.css) is
  the storefront header's height, for anything that sticks under it.

- **"Delivery to <town>" on the product page** (2026-10-09, shipping setting
  `product_estimate`): `Torgash\Shipping\ProductEstimate` asks the CART's own
  path — a `Checkout\Shipping\ProductBasket` (one product, one piece, no
  coupon: a third Basket beside CartManager and OrderBasket),
  `Package::forCart()`, `CartFacts::forCart()`, `ShippingOptions::inZone()`
  — so the page never quotes what the cart then prices differently. The
  town is the visitor's (`LocationStore`), named in the sentence's case
  through `locations.place.forms` (case 6), and changed through the
  locations window (its save reloads the page). The term is the rate's
  `delivery_time` (or the carrier's offer). A module adds a line under an
  option through `shipping.product_delivery.note` — warehouses says, under
  Pickup, whether the CHOSEN shop has it today or by when it is brought.
  Drawn first under the buy panel (prepended to `after_buy_box`).
- **The bill prints cents** (`format_price($minor, exact: true)`,
  2026-10-12): in the books' own currency a gateway is asked for the stored
  cents as they are (`Order::chargeMinor`), so a shop showing whole dollars
  printed $2 874 over a charge of $2 873.76 and parts that did not add up.
  Exact shows the fraction only when there is one; a converted amount is
  charged rounded to its shown places and stays as shown. Used by the cart,
  the basket dialog and badge, the checkout and its shipping prices — the
  catalogue and the product page keep the shop's rounding.
