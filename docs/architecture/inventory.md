# Inventory (torgash.inventory)

Stock accounting without 1C (PLAN §42). An optional module: a shop that keeps
its books in 1C does not need it, and uninstalling it takes the journal, the
documents and the batches away while the stock itself stays where it lives
(the product's column, the warehouses' rows).

**A movement is announced where the number changes, and its CAUSE by whoever
caused it** (`Torgash\Catalog\Stock\StockMoves`, the catalogue's, because
two modules that do not know each other write stock). The number changes in
two kinds of place only: a product not kept per warehouse has its own column
(`Product::adjustStock()` announces with the cause in force and saves
QUIETLY; any other save of the product — form, import, 1C, API — is announced
by the model's `updated` listener as the cause in force, `edit` by default;
a new product with stock is a movement from nothing), and a product kept per
warehouse has one row per warehouse (`StockAllocator` announces every row it
touches; `set()` and `move()` are the two ways to write one row exactly, and
the stock screen and 1C go through the first). `syncStockTo()` announces
nothing: the column of a split product is the sum of rows that already did.
Splitting a product (its first warehouse row) announces its column's units
leaving the column and arriving at the default warehouse, type `split`, so
the journal of a product always adds up to its stock.

A caller that merely CAUSES a movement wraps its work in
`StockMoves::because(['type' => …, 'order_id' => …], fn)`; the OUTERMOST
cause wins, so a return that goes through the order's own stock code is a
return, and a merge is a merge. In force today: `order` (OrderStock's own
methods: placing, a status taking or giving back stock, an edit, the trash),
`return` (rma receiving), `merge`, `import` (exim's commit), `1c` (the
exchange's import mode), `edit` (a person), `split`. What happens to the
announcement (`catalog.stock.moved`) is this module's: `Journal` writes one
row to `m_inventory_moves` (no FK to the product — a deleted product's
history is still history; `sku` keeps it readable; `ref` holds the rest of
the cause). The journal is already a log, so the audit ignores it.

Screens: Logistics → Stock journal (`/admin/inventory/moves`, filters by
product, warehouse, cause, period), and the latest five movements under the
stock on the product form (`catalog.product.stock_extra`). Nothing edits a
movement: a mistake is corrected by the next one.

**Documents** (`m_inventory_documents` + lines; Logistics → Stock documents):
receipt, write-off, transfer, count, numbered by kind (`IN-`, `WO-`, `TR-`,
`CT-` + id). A draft is edited freely — quick entry by exact SKU (what a
scanner types) with Enter, or a search by name; the same product again adds
to its line — and `Posting` is the ONE place a document moves stock: one
transaction, cause `document` (with `document_id`, number, kind), every line
through `StockAllocator::move()/set()` at exactly the warehouse named (never
spilling), a write-off or transfer of more than the warehouse holds refused
line by line, a count SETTING the warehouse and writing what it held in
`expected`. A receipt's price is typed with its currency and FROZEN in the
books at posting with that day's rate (a delivery was paid for once — the
deliberate exception to the rebasing money rule). Posted = closed: every
write answers 409; the next document corrects.

**Revoking** (`Revoking`) is the one way back, for a document that should
never have existed (typed by mistake, posted twice): permission
`inventory.revoke` — in no group by default, though the super role holds it
like every other, so "not even the second admin" means the second admin is
NOT in the `admin` role — plus the person's own password typed at that moment
and a reason. Every movement is undone in reverse under cause `revoke`, which
the journal does NOT write; what went out goes back into the very batches it
was taken from, what came in leaves with its batches, and the document's
journal rows and costs are deleted, so a product's journal still adds up to
its stock. The row stays, status `revoked` (who, when, why, number never
reused), on its own tab out of the working lists. Refused, every reason at
once, when undoing would rewrite what came AFTER: units it brought in that
any later movement took from its batches (a sale's cost was worked out from
them), or a warehouse no longer holding what would leave it. Print is plain HTML for the
browser's Print (receipt: supplier, prices, total; write-off: reason; count:
expected / counted / difference), with signature lines.

**Batches and cost** (`Batches`, `m_inventory_batches` +
`m_inventory_move_costs`), asked by the journal the moment it writes a
movement, so it sees every cause. OUT takes the oldest batches at that place
first (FIFO) and writes each part with its price; what no batch covers is a
part with no batch and no price. IN is a batch whose price depends on why:
a receipt — its line's frozen price; a transfer or a split — the parts the
matching OUT just took, price for price; an order giving goods back (a
return, a cancellation, an undone merge) — back into the very batches that
order took from, most recent first, never more than it took and has not had
back; anything else (import, 1C, typed, a count's surplus) — an UNKNOWN price.
Installing the module turns what is already on the shelves into OPENING
batches at an unknown price (the oldest, so FIFO sells them first).

**Profit** (`Profit`, one class for the order's card and the period):
goods after discounts + delivery charged − cost of the goods (from the parts)
− the order's own expenses + its income (`m_inventory_order_costs`, §42.5а —
the shop's books only, never what the customer pays, printed or sent
anywhere). Taxes are not taken off, and units with no price are COUNTED, never
costed at zero. A merged order's cost and expenses are the order it went into
(`coalesce(merged_into_id, id)`; the rows move on `checkout.order.merging`
and back on undo). The order card is checkout's new
`checkout.order.side_panels` (a list of `{key, position, html}` in the right
column); the report is Sales → Profit, the sales of a period (a status that
takes goods off the shelf, the dashboard's own definition), with totals and
expenses by label.

Not done: a gateway's fee or a carrier's real price written automatically as
an expense (`source`); units sold ahead of stock (negative) are costed as
unknown and a later receipt does not go back to cover them.
