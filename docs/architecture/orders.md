# Orders, payments, taxes

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- **An order is created in exactly one place: `Torgash\Checkout\Orders\PlaceOrder`.**
  The web form and the shop API both go through it, which is why stock, statuses,
  currencies, taxes and the confirmation email cannot drift apart. A caller
  gathers the answers and decides where the customer goes next; `PlaceOrder`
  decides what an order IS. `rules()` is built from what is actually on offer
  (`Rule::in($quote->offerIds())`), so a JSON client cannot buy a delivery option
  with no rate in the winning zone, and `shippingIsActive()` is the one answer to
  "is torgash.shipping here" — never re-tested by a caller.
  The web form's only guard is a module's check — a CAPTCHA when the shop
  ticks "Placing an order" (`FormGuard::checkAdded()`, guard.md) — checked
  BEFORE the rules and kept across a refusal; never core's stamp, trap or
  rate limits.
  **Buying in one click is an ordinary order through it** (`Orders\OneClick`):
  a throwaway cart holding the one product (the visitor's own basket is not
  touched, ordered or emptied), placed with the delivery and payment methods
  the shop NOMINATED on those methods' own forms. PlaceOrder is not relaxed for
  it — a hole there is a hole for the API too — so a nominated method with no
  rate covering an unknown address refuses instead. `email` and `city` are
  nullable because such an order has neither, and no confirmation is sent
  rather than sent to an invented address (which is exactly what a typical
  one-click does). **On the cart page it is the other way round**
  (`one_click_cart`, a switch of its own, the same nominated methods): the
  customer asked for THE BASKET, so the basket itself is placed and emptied by
  PlaceOrder, and the answer is the order's own page (`HX-Redirect`). Offered
  only while every line passes `one_click_statuses`; drawn through cart's
  `cart.summary_actions`, so the cart still knows nothing of checkout.
  **A manager's order from the admin is the same call** (`Orders\ManagerOrder`,
  PLAN §63.8), made the way a return's exchange makes it: a throwaway
  `onBehalf()` basket of the typed lines, the deliveries quoted for the typed
  address (the checkout's own town field, so a town picked from the registry
  travels as its place id; the payment methods are those that take the
  shop's currency — `paymentMethods(shop_currency())`, never the admin's
  session currency), `as: [user_id, storefront_id, locale, charge_currency => null]` so
  nothing of the manager (account, storefront, browsed currency) lands on the
  row; the manager's own line prices are applied AFTER placement through the
  editor's recalculation, so stock, a promotion's gift and the tax snapshot
  happen exactly as for a customer. No goods, no order: a service is a service
  product that keeps no stock, or its profit cannot be counted.
## Orders: what changed, and where the money is

An order announces its own edits through **`checkout.order.changed`**
(`['order', 'before', 'after', 'changed']`). Nothing calls it: the funnel is
`Order::updated()` → `Torgash\Checkout\Orders\OrderChanges`, a per-request
singleton that folds every `save()` of one action into ONE diff and fires it on
`terminating()` (plus after each queued job). So a module editing an order —
shipping does, and never mentions the hook — cannot forget to announce, a
consumer never sees the half-updated state between OrderTotals' two saves, and
nothing is announced from inside a transaction that may still roll back. A
just-placed order is not a change (`wasRecentlyCreated`); placement stays
`checkout.order_placed`, and `checkout.order_status_changed` stays as the
immediate, status-specific hook that drives the customer email.

**An order's discount is TERMS, recomputed from what it holds**
(`Torgash\Checkout\Orders\OrderDiscounts`, called by `OrderTotals`). The coupon
is frozen as its terms (`coupon_terms`: type, value, minimum, and `excluded` —
what the coupon did not reach in the basket, a kit's goods, kept out as the
other lines change), the manager's own discount as `manual_discount` (type,
value, a REQUIRED reason, who, and the amount it came to); coupon first, the
manager's after it. `discount_amount` stays the one sum everything adds up, and
`Order::discountLines()` is the one breakdown the admin, the invoice, the
confirmation and the 1C document print. An order placed before terms keeps its
frozen sum. A manager may put a coupon on a placed order past its dates and
limit — deciding that is the point — and the order's history says so; a
disabled one never.

**What a discount was agreed on is fixed to the order's LINES** (PLAN
§40.10/3, the owner's rule: "the customer bought a computer in a sale, the
order is packed, they ring to add screws"). The coupon's terms carry `lines`
(line key → the quantity it covers, `OrderDiscounts::coveredTotal()`); a cart
promotion's row in `m_promotions_order_promotions` carries `shares` (line key →
`{a: amount, q: quantity}`, laid by `CartPricing::shares()` in proportion to
the lines it was taken from, a scoped tier over its scope only) and `target`
(`goods` / `shipping`). An edit moves them only by what LEFT: a removed line
takes its share, a quantity cut takes its part, and nothing added afterwards —
a new line or more units of an old one — gets any; the threshold is not asked
again, the customer met it when ordering. Asking again is a manager's act:
putting the coupon on the order covers what it holds then, "Recheck the
promotions" (`PromotionsServiceProvider::reprice()`) prices today's promotions
over everything. Rows and terms without these keys (placed before) keep the
old behaviour: a coupon covers the whole subtotal, a promotion keeps its sum.

**One order can be POURED INTO another** (`Orders\MergeOrders`, PLAN
§40.10/4 — "the customer ordered twice and wants one parcel"), and that class
is the only place it happens. B's lines join A under keys that never collide
(`<key>-<B's number>`), marked `merged_from`, with their discounts fixed as
they were (B's coupon becomes A's, merges with the same one, or rides as
TERMS on its own lines in `other_discounts`, which `OrderDiscounts` recomputes
like a coupon; B's manager discount the same), their stock left taken (brought
to A's side only when one order held stock and the other did not), and B's
money counted towards A (`paid_total`, `prepaid_total`, the deposits summed).
A's delivery stays; B's is dropped. B remains a shell in the CLOSED `merged`
status (`OrderStatus::MERGED`, `inventory_action = none`; `choosable()` is
every list a person picks from; `OrderStatusChanger` refuses both ways; the
Order model's `updating` asks `MergeOrders::guard()`, which lets through only
the merge itself) with `merged_into_id`, `mergeRoot()` / `mergedIds()` to walk
it, its customer pages redirecting to A's, and 1C told it is `Отменен`. A
module moves its own rows on `checkout.order.merging` (inside the
transaction, before A is recalculated — promotions rename their shares' keys,
gift cards their ledger lines and sold cards, points their spend) and writes
what it moved with `$merge->remember()`, to move it back on
`checkout.order.unmerging`; it says "not these two" on
`checkout.order.merge_refusals` (a return, points already credited) and "no
longer undoable" on `checkout.order.unmerge_refusals` (1C confirmed it). A
payment stays the payment of the order the bank knows it by, but
`payments\Ledger::of()` covers `mergedIds()`, a late callback about B lands on
A, and a refund of A walks B's payments through B's own invoices. **Undo**
(`m_checkout_order_merges`, both orders' columns before) is possible until
ANYTHING happens to either order after the merge — measured by the order
history past the merge's own lines, not by a list of fields that count.

**A page of orders is announced, like a grid of products**: checkout turns
the API's `api.resource.listed` into `checkout.orders.listed` (context
`orders`), and a module printing its own rows about each order reads them for
the page in one query through `Checkout\Support\OrderRows` (`prime($table,
$ids)` / `for($table, $id)`, forgotten per order on
`checkout.order.recalculated`) — taxes and promotions do; payments primes its
own `payment.payments` (`Payments\Api\OrderPayments`) and documents it
through `api.order.schema`. `OrderPaymentsApiTest` holds a page to the same
query count at 3 orders and at 12.

**An unpaid order holds no stock.** An order whose payment method takes money
online (`checkout.payment_method.online` — answered by `torgash.payments`, true
when a gateway is bound to the method) is placed in the `awaiting_payment`
status, which does not decrease inventory; the money arriving moves it to the
shop's default status through `OrderStatusChanger`, and THAT is when the goods
come off the shelf. Everything else places exactly as before. No reservation
table and no timeout: an abandoned order blocks nothing because it never took
anything — the price being that the last unit can be sold while the customer is
on the bank's page, in which case `OrderStock::canCover()` sends the paid order
to `backordered` rather than letting the warehouse go negative. Deleting the
`awaiting_payment` row turns the whole thing off. **What the customer chose is
measured against the shelf at placement, with the products' rows locked**
(`OrderStock::refusal()`, in id order inside PlaceOrder's transaction): a
second basket placing the last unit waits for the first to commit and then
reads the shelf it left. Lines a module appends (a promotion's free gift) are
not a reason to refuse; a manager placing on somebody's behalf (`$as`) is not
held to it. See cart.md for the basket's half of the same rule.

**Payment is a second axis, never an order status.** `status` is the shop's own
editable workflow; `payment_state` is a fixed vocabulary
(`Torgash\Checkout\Orders\PaymentState` — a class, not a table, because the
engine and every future gateway must agree on what "paid" means) plus
`paid_total` in minor units and `paid_at`. Mapping a gateway's statuses onto
ORDER statuses is what leaves a shop on an older engine unable to say "paid and shipped";
don't. `paid_total` is not derivable from the state — an underpayment and a
partial refund are both "some, not all" — and editing a paid order's contents
moves `total` out from under it on purpose: `Order::balance()` shows the
difference, and the payment is a fact about the past. A gateway hangs its own
buttons off `checkout.order.payment_extra`.

**An order carries THREE currencies, and each answers a different question.**
`currency_code` is the shop's books — the units every amount on the row is
stored in, and what its reports are in. `charge_currency`/`charge_rate` is what
the customer was quoted in and what the order is printed in for ever after
(`Order::money()`, at the FROZEN rate: a reprint must match the receipt).
`payment_currency`/`payment_rate` is what the bank is asked for
(`Order::paymentMoney()`, `chargeMinor()`, `baseFromCharge()`) — a Ukrainian
merchant account takes hryvnia whatever the shop prices in and whatever the
customer was browsing. The first two are frozen at placement. The third is
decided by `Torgash\Checkout\Orders\PaymentCurrency`, which answers it at
checkout, live on a page for an order that has nothing frozen (worked out from
the method the order was sold under — `PaymentMethod::forOrder()`, by name), and
freezes it in `Starter` before any gateway is called: it has to stop moving
before the customer is charged, not when the order is placed. NULL means "same
as the one before it", which is every single-currency shop. Folding the third into
the second is the tempting shortcut and is wrong both ways: printing the order
in hryvnia changes numbers the customer already agreed to, printing it in euro
leaves a Pay button quoting an amount no bank will charge.

**Part of an order can be paid IN ADVANCE — a gift card** (PLAN §40.4).
`prepaid_total` is the part of `paid_total` no bank holds; `Order::gatewayDue()`
is what a gateway may ask for and `gatewayPaid()` what it holds — a gateway, a
`Charge`, a refund button or a transaction log that reads `total`/`paid_total`
instead charges or refunds the gift card's money a second time. The state is
worked out in ONE place, `Orders\Settlement` (`afterReport` for a gateway or a
person, `afterPrepaid` when a card is put on or taken off), with its own
`partially_paid`. Checkout names no gift card: a code in the coupon box that is
not a coupon goes to `checkout.code.apply`, the summary asks
`checkout.prepaid.quote`, `PlaceOrder` spends through `checkout.order.prepay`
INSIDE its transaction (less than was shown is a refusal), everything printed
asks `Order::prepaidLines()`, and `Refund` gives back the prepaid part FIRST
through `checkout.order.refund_prepaid` (the owner's rule: no bank fee, no cash
leaves). An order the card covers in full is placed as paid and never goes to a
bank page. **A payment method may take only PART online** (`deposit_type` fixed
| percent + `deposit_value` on the method, "200 now, the rest to the courier"):
frozen onto the order as `deposit_amount` at placement, only for a method that
takes money online; `Order::chargeNow()` is what a gateway asks for (the
deposit while the bank holds nothing, else what is left) — `Charge::for`, the
Pay button and every gateway's log use it — and the deposit arriving is
`partially_paid` and sends the order to work. Everything printed about how an
order is paid is `Order::paymentSplit()`.

**Discounts after the coupon from other modules** — bonus points — reach
checkout through `checkout.cart.discounts` (asked by `CouponManager::otherDiscounts()`
against the goods the coupon left; `discount()` is the coupon PLUS them, so
the Quote, the tax base, 1C and the cart page all see one number) and are
frozen onto the order as `other_discounts` (money, re-capped but never
recomputed by `OrderDiscounts`, printed by `discountLines()`). Anything that
must be TAKEN for an order — points from a balance — answers
`checkout.order.placing`, fired inside PlaceOrder's transaction; throwing
refuses the order. **Statuses that mean "cancelled" are named in a setting,
never "inventory_action = increase"**: `backordered` returns stock too, and a
paid order waiting for a delivery must keep its payment and its points.

**`torgash.points`** (PLAN §40.5): a ledger per customer (`m_points_ledger`,
balance = sum on `m_points_accounts`, locked when spent), `m_points_orders`
per order (planned `earn` shown as waiting, `earned` once credited on the
shop's statuses, `spent`), multipliers per category subtree / product /
customer group (`Earning`, rules read once per request, categories primed off
`catalog.products.listed`). Spent as a discount through `Spending::quote()`
(balance, the share of the goods, with a coupon or not); an optional price in
points buys a whole line (`points_lines` in the cart's answers). Expiry is
FIFO by one rule: the balance may not exceed what unexpired credits add up to.

**`torgash.giftcards`**: a card is found by an HMAC of its code under the app
key (`code_hash`), never by the code; its balance is the SUM of its ledger,
rewritten in the same transaction and spent under `FOR UPDATE`. Issued three
ways — by hand, a batch for printing (`pending`: not money until activated at
the till), or an order that bought one, and only once that order is PAID, one
card per unit, keyed by order+line+number so it is issued once. A card is money,
not goods: coupons and promotions never discount one, and a card does not pay
for a card unless the shop allows it. Its rules (categories with their
subtrees, delivery, a share of the order, a minimum) are `Rules::payable()`,
asked by the basket and by a manager attaching a card to a placed order alike.

**Money arriving with no gateway to say so goes through
`Torgash\Checkout\Orders\RecordPayment`** — the manager's "mark as paid" and
1C's payment date are the same act performed in different windows, and it owns
zeroing an amount that no longer means money, stamping `paid_at` once and the
log line. Not a duplicate of payments' `PaymentApplier`: that arbitrates between
gateway events (dedup by key, no going backwards) because a gateway repeats
itself and arrives out of order; a human is the authority and has nothing to
arbitrate.

**Who the buyer is when there is no account: `customer_ref`**
(`Torgash\Checkout\Orders\CustomerRef`), a short reference DERIVED — not
looked up — from the strongest identity the order carries (user id, else the
phone's last nine digits, else the email), hmac'd with the app key. It is what
lets two guest orders be one counterparty in an exchange, and it is why no
throwaway account with a made-up email is ever created; a system that insists on
an address gets one composed at the edge and stored nowhere. Buying in one click
will make the guest the normal case, which is why this lives in checkout.

An order carries BOTH `payment_method_name` (the record — what the customer was
sold, printed for ever) and `payment_method_id` (the link — which gateway, which
currencies). Resolve with `PaymentMethod::forOrder()` / `Binding::forOrder()`,
never by comparing the frozen name to the current ones: that detached every
older order when a method was renamed, and failed outright when the reader's
locale differed from the buyer's.
Which currencies a method takes (`currencies`) and what it charges in otherwise
(`charge_currency`) live on the PAYMENT METHOD, in checkout — "the courier only
takes hryvnia" is the same question for a method with no gateway at all.
`torgash.payments` only narrows it to `Gateway::currencies()` and warns. An
empty `charge_currency` means the method is not offered for such orders, and
checkout validates the submitted method against what was actually on offer.

## Payments (torgash.payments)

A gateway module implements `Torgash\Payments\Gateway` and answers
`payments.gateways`; this module owns the route, the signature check, the
dedup, the ordering, the transaction log and the admin buttons. The shape is
deliberate: `verify()` gets the raw Request (a signature is over the RAW BODY —
signing a re-encode of the decoded JSON is the reference add-on's bug),
`parse()` takes the order id from the SIGNED payload (never the callback URL),
and the order screen draws only the `capabilities()` a gateway declares.
`PaymentApplier` is the single way an order's payment state moves: a repeat of
the same `event_key` changes nothing, a late event never lowers the state's rank
(a refund is the exception — always later news), and everything is recorded
either way.

**A PAYMENT is a unit of its own** (`m_payments_payments`, `Models\Payment`,
PLAN §40.10/4): one row per (gateway, the gateway's id for it) with its own
state and what it holds now, in the books. A deposit and its rest are two
invoices, and so are an order and one merged into it. The rank rule is the
PAYMENT's — news about the rest's invoice is not "backwards" because the
deposit already arrived — and the order is worked out from all of them in ONE
place, `Ledger` (`held()` summed + prepaid through `Settlement`; `state()`: a
hold anywhere is a hold, a refund anywhere is a partial refund while money
remains). Before this the rest arriving wrote `paid_total` as the rest alone
and a refund or capture reached only the latest invoice. A payment BEGINS at
the row every gateway already writes when it makes an invoice
(`Transaction::created` → `Ledger::started`), so no gateway had to learn this;
an id nobody has seen takes over an open payment the gateway never answered
about (a Stripe session becoming its PaymentIntent → `started_as`). Every
action on a payment is `PaymentActions` — the order screen's buttons (one row
per payment, its own buttons), the refund hook and the nightly check — which
hands the gateway `external_id` and `held` in `$params` and applies the answer
to THAT payment whatever id the gateway writes on it. A refund of an order
walks `Ledger::refundOrder()`: holds first (released, not transferred), then
what was taken, newest first, each asked for no more than it holds. A gateway
module must act on `$params['external_id']` when given and measure a partial
refund against `$params['held']`, never against the order. The callback route is registered OUTSIDE `web` and outside
`StorefrontRoutes` — a machine has no session, no CSRF token and no `/ru/`
prefix, and `web` would answer it 419. The customer's "pay later" link is a
`signed` route: it must survive into tomorrow's email and must not be guessable
from `TG-000123`. Method→gateway wiring lives in this module's own table keyed
by the method id, never a column added to checkout's. A gateway is handed
`Binding::gatewayConfig()` — its fields PLUS the binding's `test_mode`: for
monobank that switch is a label (the token decides), for LiqPay it is
`sandbox: 1` on every payment, and a gateway that never saw it would take real
money while the screen said "test". Gateways today: `torgash.monobank`,
`torgash.liqpay` (envelope `data` + sha1 `signature`; its `order_id` is
`{order id}-{time}` because LiqPay wants one per ATTEMPT), `torgash.wayforpay`
(an INVOICE, so no hold; HMAC-MD5 over a per-message field list),
`torgash.stripe` (Checkout; the order in the metadata of BOTH the session and
its PaymentIntent, API version pinned by header, and the first gateway with a
real `void` — a cancelled intent on a HELD order is REFUNDED at 0, never
EXPIRED, which the applier would refuse as a step back). The order screen
offers `capture`/`void` only on an `authorized` order. A gateway
whose protocol dictates the reply to a callback (WayForPay retries for days
until it gets a signed `accept`) implements `Torgash\Payments\AnswersCallback`
— asked after the event is applied, and only for a callback that verified.

**The split is in the code, not on the screen — nor in the URL.** Payment
methods live under `/admin/payments/methods` (checkout's controller and table,
checkout's route names) and the log under `/admin/payments/log`: two modules,
one address space, distinct paths. A deliberate exception to "a module keeps to
its own prefix", because `checkout` is our module's name and `payments` is what
the shop owner came looking for. The methods themselves stay checkout's on
purpose — every shop has payment methods (cash on delivery, a transfer) and must
keep them with this module uninstalled; gateways are the optional half, which is
the opposite of shipping, where the whole subsystem is optional.

A gateway is configured on the
payment method itself (Admin → Payment), through `checkout.payment_method.extra`
+ `checkout.payment_method.saved` — the same idiom as taxes in the product form.
`torgash.payments` owns exactly one page of its own, the payment log. Every
gateway's fields render at once and Alpine shows the selected one (inputs are
namespaced `gateway_config[<key>][…]`), because making checkout's form submit
itself to reveal a field would save a half-filled method. A stored credential is
never rendered back: the empty box carries a "saved — leave blank to keep it"
placeholder, and a blank password on save means keep.

## Taxes

`torgash.taxes` owns VAT/sales tax; core and checkout know nothing about it.
Whether a tax is INCLUDED in the price or ADDED at checkout is a flag on the
tax (`price_includes_tax`), not a shop-wide mode — that is the whole CIS/US
difference and a shop can have both. Formulas:
included `tax = base − base/(1+rate/100)`, added
`tax = base × rate/100` with the base for later taxes growing. Taxes sharing a
`priority` all charge the original base; a higher priority charges what the
lower ones raised (tax on tax). An exempt product gets a 0% tax, not "no tax".
Orders keep their own snapshot (`m_taxes_order_taxes`), so changing a rate
never rewrites a placed order.

**A rate depends on where the goods GO** (`m_taxes_rates`, `Torgash\TaxesDestinations`): a tax carries rows of country / `UA:12` region / postal
pattern → rate or "not charged", in its OWN table rather than shipping's zones
(a digital shop uninstalls shipping and still owes VAT), and the narrowest row
naming the address wins, as with zones. No row — or no address yet — means the
tax's own rate; `only_listed` turns that into "not charged", which is a US
sales tax owed only where the shop has nexus. "Not charged" is no line; a 0%
row is a line that PRINTS 0%. The answer is the Tax COPIED at the rate that
applies there (never saved), so the calculator, labels and snapshot read
`rate_percent` as before; `Tax::label()` swaps "20%" in a name for the rate
charged. An INCLUDED tax at another rate never moves the shelf price — a
different share of it is VAT. Where is always the ORDER's address for an order
(`Destinations::forOrder()`, so an admin edit re-taxes at the customer's
address), the quoted address in checkout (`location` in
`checkout.extra_totals`), and the visitor's remembered town on a page
(`visitor()`, scoped and forgotten per request). The region is derived from
the town, never stored on the order.

Pricing a delivery asks a `Torgash\Cart\Contracts\Basket` — implemented by
`CartManager` (the live cart) and by `Torgash\Checkout\Orders\OrderBasket` (a
placed order). That is what lets the admin re-quote an order's delivery through
exactly the code that priced it at checkout — same zones, rates, conditions and
carrier — instead of a second pricing path. An order carries `place_id` for it
(no FK: checkout may not own the locations module's table).

An order's tax snapshot is rewritten in exactly one case: an admin edits the
order's own contents. That fires `checkout.order.recalculated`, and the taxes
module replaces the order's rows from what it now contains — the rate did not
change, the order did. Everything that recomputes a PLACED order's money goes
through `Torgash\Checkout\Orders\OrderTotals` (items → hook → total, in that
order); stock deltas from such an edit go through `…\Orders\OrderStock`, which
also maintains the per-line warehouse breakdown.

Extension points it hangs off (added with it, useful for any pricing module):
`catalog.product.pricing_extra` (a field in the product form's pricing card),
`catalog.product.saved` (the one funnel both store() and update() pass through),
`catalog.price.display` (what a storefront price renders as, seeded with the
stored price), `checkout.extra_totals` (lines for the cart being checked out —
`['code','label','amount','included']`, where `included` means "already inside
the prices, disclose only") and `checkout.order.extra_totals` (the same for a
placed order, read from its snapshot).

## Snapshots read in the reader's language (2026-10-13)

An order keeps names as the buyer saw them, in the language they shopped in —
the record, and what the invoice and the letters print. An ADMIN screen shows
such a name in the reader's language only when it is merely another language
of the same words: `Order::sameWordsFor($snapshot, $translations)` — if the
snapshot is one of the thing's current translations, the reader's; otherwise
(renamed since, typed by hand, a configured line's composed name) the snapshot.
Used for lines (`itemLabel()`), the payment method (`paymentMethodLabel()`)
and delivery (`shippingMethodLabel()`, answered by the shipping module on
`checkout.order.shipping_method_name`). The orders list's method filters
collapse the languages of one method into one option and match all of them
(`Orders\MethodNames`; delivery's translations come from
`checkout.order.shipping_method_translations`).

Words the ENGINE would write into an order are never stored in the buyer's
language: a phone order with no name has `customer_name` NULL and screens say
"Ordered by phone" through `Order::customerLabel()`. A name is required only
when there is a parcel to hand over (`needs_address`); otherwise a phone
suffices. 1C gets the phone as the counterparty's name.
