# Orders

The catalogue comes in; the orders go out. 1C asks for them with
`type=sale&mode=query`, reads the file, and says `success` when it has taken
them. It can then send documents back, the same way it sends a catalogue — a
file, then an import — and those may change two things about an order and
nothing else.

## Which orders go

Admin → 1C exchange → **Orders and what 1C may change**.

There is no default that suits every shop, so the condition is stated on the
two axes an order already has:

- **statuses** — the shop's own workflow. Tick the ones that mean "this order is
  real": *Processing* for a shop whose manager confirms orders, *Open* for one
  that trusts the checkout.
- **paid** — the second axis, which is not a status. A prepaying shop writes
  goods off in 1C when the money arrives, whatever the order's status is.
- **either / both**, and a date before which nothing is sent. The date is what a
  shop connecting 1C to two years of existing orders needs on the first night.

Tick nothing and nothing is sent. That is deliberate: an unanswered question is
not the same as "send everything", and the alternative writes stock off in 1C
for orders nobody has confirmed.

An order is offered again whenever anything on it changes, and never offered
twice for nothing — the marker is a hash of the document itself, so any change
to what would be sent sends it, and no list of "fields that count" has to be
maintained. Nothing is marked as taken until 1C says `success`, so an exchange
that dies halfway loses nothing.

## What the document contains, and why it adds up

Every line of an order becomes a `<Товар>`, identified by the product's
`external_id`. A product 1C has never sent carries a fallback identifier of
ours: 1C will not match it, which is correct — it genuinely is not in their
nomenclature — and matching by name instead would invent links between things
that happen to share a word.

**Delivery is a line**, and so is anything else charged on top (a tax added at
checkout, a fee). The sum of the lines, less the discount, is the order's total
by construction. Dropping the delivery line — tempting, since a parcel carrier
is no business of 1C's — is what produces documents an accountant has to correct
by hand a week later.

The delivery line's identifier is set per method, on the delivery method's own
page (**1C: delivery service identifier**), with a fallback on this screen. A
method with neither still exports, by name and without an identifier.

**The money is the shop's books.** Every amount on the order is in the shop's
own currency and consistent with every other. What the customer was quoted in,
and what their bank was charged, are written into the attributes for anyone who
wants them — never used as the document's own numbers, or the lines would stop
adding up to the total.

## The buyer

1C wants a counterparty, and most orders have no account behind them. Each order
carries a short reference for its buyer, derived from the strongest thing known
about them — the account, else the phone number, else the email — so two orders
from one person are one counterparty in 1C. A phone typed as `+380 67 123-45-67`
one day and `0671234567` the next is the same buyer.

No throwaway accounts are created. If 1C insists on an email address for a buyer
who never gave one, fill in **Made-up email domain** and the export composes one
from the reference; the shop's own record still says, truthfully, that it has no
email for that person.

## What 1C may change coming back

**The status, and the fact that the money arrived.** Nothing else.

1C legitimately holds a document with different lines on it — a warehouse ships
nine of the ten, an accountant corrects a price. Importing that would mean a
nightly exchange silently changing the total of an order the customer has
already agreed to, and possibly already paid. A shop that wants more than this
has Automations, where the rule is written down, visible and logged.

Both facts are read by attribute NAME, from a list typed in the settings, for
the same reason weights are: no two 1C installations spell them alike. An
attribute that is present but empty, or says `Нет`, is not an event — otherwise
the first exchange marks every order shipped.

- **Shipment** moves the order to the status chosen on this screen (*Shipped* by
  default). Through the shop's own status change, so the warehouse moves with it.
- **Payment** settles the order through the same code the manager's *mark as
  paid* runs. It is not a status here — payment is the second axis — and an
  order already paid is left alone.

An order is found by its identifier first and its number second. An order
matched by number acquires 1C's identifier on the way past, which is how a shop
whose orders predate the exchange gets linked, one document at a time.

## What the screen shows

The table at the bottom is the ledger: when each order was offered, whether 1C
has taken it, and what it last said about it. "1C answered, and said nothing had
shipped" is the answer a shop wondering why an order is still open actually
needs, so it is recorded whether or not anything moved.
