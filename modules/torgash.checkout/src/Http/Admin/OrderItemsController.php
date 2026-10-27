<?php

namespace Torgash\Checkout\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Orders\LineConfig;
use Torgash\Checkout\Orders\OrderStock;
use Torgash\Checkout\Orders\OrderTotals;

/**
 * Editing what is IN a placed order.
 *
 * A line is addressed by its KEY, not by its position and no longer by its
 * product: the lines are a jsonb array, and since a product can be configured
 * (PLAN §33.3) one product can hold two lines of the same order — a processor
 * inside a server and the same processor on its own. An index would break the
 * moment a concurrent edit removed an earlier row; a product id would edit
 * whichever of the two came first. A line stored before keys existed has none,
 * and is found by its product id, which is what it used to be addressed by.
 *
 * Every change goes through the same three steps, in this order, inside one
 * transaction:
 *   1. stock — OrderStock applies the difference and keeps the per-line
 *      warehouse breakdown current;
 *   2. money — OrderTotals rewrites subtotal, lets the extra-total modules
 *      resnapshot (taxes rewrites m_taxes_order_taxes from the new contents,
 *      so a hand-edited order still adds up), then the total;
 *   3. history — one log line saying what a human did.
 * Doing them in any other order leaves the tax snapshot describing lines that
 * are no longer there.
 */
class OrderItemsController extends Controller
{
    public function __construct(protected OrderStock $stock, protected OrderTotals $totals) {}

    /**
     * Which line the URL means — by key, falling back to the product id for a
     * line written before keys existed. Returns the index into the order's own
     * array, or false.
     *
     * @param  list<array<string,mixed>>  $items
     */
    protected function indexOf(array $items, string $line): int|string|false
    {
        return collect($items)->search(fn ($item) => (string) ($item['key'] ?? $item['product_id'] ?? '') === $line);
    }

    /** Swap the items card into (or out of) edit mode. */
    public function edit(Request $request, Order $order)
    {
        return $this->editor($order, $request->boolean('editing', true));
    }

    public function store(Request $request, Order $order)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:m_catalog_products,id'],
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $items = $order->items;

        // Already on the order: one more of it, not a second line — the same
        // rule the cart follows, so an edited order looks like a placed one.
        // Only a PLAIN line of that product, though: adding a processor to an
        // order must not quietly put it inside the server that already holds
        // one, which is what matching on the product alone would do.
        $existing = collect($items)->search(fn ($item) => (int) $item['product_id'] === $product->id
            && ($item['parent_key'] ?? null) === null
            && ($item['options'] ?? []) === []);

        if ($existing !== false) {
            $items[$existing]['qty']++;
            $items[$existing]['line_total'] = $items[$existing]['qty'] * $items[$existing]['unit_price'];
            $line = ['Item added: :name (now ×:qty)', ['name' => $items[$existing]['name'], 'qty' => $items[$existing]['qty']]];
        } else {
            $price = $this->priceFor($order, $product);
            $items[] = [
                'key' => (string) $product->id,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->t('name'),
                'qty' => 1,
                'unit_price' => $price,
                'line_total' => $price,
            ];
            $line = ['Item added: :name ×1', ['name' => $product->t('name')]];
        }

        $this->commit($order, $items, ...$line);

        return $this->editor($order->fresh(), true);
    }

    public function update(Request $request, Order $order, string $line)
    {
        $validated = $request->validate([
            'qty' => ['required', 'integer', 'min:1', 'max:100000'],
            'unit_price' => ['required', 'string'],
        ]);

        $items = $order->items;
        $index = $this->indexOf($items, $line);
        abort_if($index === false, 404);

        $was = $items[$index];
        $items[$index]['qty'] = (int) $validated['qty'];
        $items[$index]['unit_price'] = $this->minorUnits($validated['unit_price']);
        $items[$index]['line_total'] = $items[$index]['qty'] * $items[$index]['unit_price'];

        if ($was['qty'] === $items[$index]['qty'] && $was['unit_price'] === $items[$index]['unit_price']) {
            return $this->editor($order, true);
        }

        $this->commit($order, $items, 'Item changed: :name ×:from_qty @ :from_price → ×:qty @ :price', [
            'name' => $was['name'],
            'from_qty' => $was['qty'],
            'from_price' => format_price($was['unit_price'], $order->currency_code),
            'qty' => $items[$index]['qty'],
            'price' => format_price($items[$index]['unit_price'], $order->currency_code),
        ]);

        return $this->editor($order->fresh(), true);
    }

    /**
     * The build of one line, as a form — opened in place of the row's own
     * controls, because a configurator is a page of questions and does not fit
     * in a table cell.
     */
    public function configure(Order $order, string $line, LineConfig $config)
    {
        $items = $order->items;
        $index = $this->indexOf($items, $line);
        abort_if($index === false, 404);

        return $this->configureView($order, $items[$index], $config);
    }

    /**
     * "Make it 32 GB instead of 16."
     *
     * Everything that decides what the line becomes is `LineConfig`; what is
     * left here is what every other edit on this screen does — one transaction,
     * stock, money, a log line saying what a person did.
     */
    public function reconfigure(Request $request, Order $order, string $line, LineConfig $config)
    {
        $items = $order->items;
        $index = $this->indexOf($items, $line);
        abort_if($index === false, 404);

        $was = $items[$index];

        try {
            $items = $config->apply($order, $items, $line, (array) $request->input('config', []));
        } catch (ValidationException $e) {
            // The same answer a refused storefront form gets: the form back,
            // with what was typed still in it and the reason beside the
            // question — never a 4xx, which HTMX would not swap at all.
            return $this->configureView($order, $was, $config, $e->errors(), (array) $request->input('config', []));
        }

        $now = $this->indexOf($items, $line);

        if ($now !== false && $items[$now] == $was) {
            return $this->editor($order, true);
        }

        $this->commit($order, $items, 'Build changed: :name — :from → :to', [
            'name' => $was['name'],
            'from' => LineConfig::describe($was),
            'to' => LineConfig::describe($now === false ? [] : $items[$now]),
        ]);

        return $this->editor($order->fresh(), true);
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>|null  $submitted  what was typed, when a refusal is being redrawn
     */
    protected function configureView(Order $order, array $line, LineConfig $config, array $errors = [], ?array $submitted = null)
    {
        $form = $config->form($order, $submitted === null ? $line : ['config' => $submitted] + $line, $errors);

        // Nothing can be configured about it after all — a product deleted
        // since, or a module uninstalled. Say nothing and give the row back.
        if (trim($form) === '') {
            return $this->editor($order, true);
        }

        return view('torgash.checkout::admin.orders._line_config', [
            'order' => $order,
            'line' => $line,
            'form' => $form,
            'errors' => $errors,
        ]);
    }

    public function destroy(Order $order, string $line)
    {
        $items = $order->items;
        $index = $this->indexOf($items, $line);
        abort_if($index === false, 404);

        $removed = $items[$index];
        unset($items[$index]);

        // What was bought as part of it goes with it: a processor chosen inside
        // a server cannot be on the order once the server is not, and leaving
        // it would charge for a part of something nobody bought.
        $parentKey = (string) ($removed['key'] ?? '');
        $parts = 0;

        if ($parentKey !== '') {
            foreach ($items as $i => $item) {
                if ((string) ($item['parent_key'] ?? '') === $parentKey) {
                    unset($items[$i]);
                    $parts++;
                }
            }
        }

        $this->commit(
            $order,
            array_values($items),
            $parts > 0 ? 'Item removed: :name ×:qty (with :parts part(s) of it)' : 'Item removed: :name ×:qty',
            ['name' => $removed['name'], 'qty' => $removed['qty'], 'parts' => $parts],
        );

        return $this->editor($order->fresh(), true);
    }

    /**
     * The product picker. Deliberately narrow — it answers "which product do I
     * mean" for this one screen and nothing else; a shop-wide product picker is
     * a bigger thing to design than this needs.
     */
    public function search(Request $request, Order $order)
    {
        $needle = trim((string) $request->get('q', ''));
        $onOrder = collect($order->items)->pluck('product_id')->all();

        $products = $needle === '' ? collect() : Product::query()
            ->withCardData()
            ->where(fn ($q) => $q
                ->where('sku', 'ilike', '%'.$needle.'%')
                // Every translation at once: the name is jsonb keyed by locale,
                // and an admin searching in one language should still find a
                // product whose name they only know in another. Matching on
                // name::text would also match the locale KEYS ("en", "ru").
                ->orWhereRaw('exists (select 1 from jsonb_each_text(name) as t(k, v) where t.v ilike ?)', ['%'.$needle.'%']))
            ->orderBy('id')
            ->limit(10)
            ->get();

        return view('torgash.checkout::admin.orders._product_search', [
            'order' => $order,
            'products' => $products,
            'onOrder' => $onOrder,
            'needle' => $needle,
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    protected function commit(Order $order, array $items, string $key, array $params = []): void
    {
        DB::transaction(function () use ($order, $items, $key, $params) {
            $items = $this->stock->apply($order, $order->items, $items);

            $this->totals->recalculate($order, $items);

            OrderLog::record($order, $key, $params);
        });
    }

    protected function editor(Order $order, bool $editing)
    {
        return view('torgash.checkout::admin.orders._editor', [
            'order' => $order,
            'editing' => $editing,
            // These responses always replace the card in a live page, so the
            // activity list rides along out of band.
            'oob' => true,
        ]);
    }

    /**
     * What a newly added line costs. Priced for the ORDER's customer, not for
     * whoever is signed in to the admin: effectivePrice() defaults to
     * auth()->user(), which here is an employee, and would silently price the
     * line at the employee's own customer-group discount. A guest order gets
     * the plain list price — and the admin can overwrite either one in the
     * field beside it.
     */
    protected function priceFor(Order $order, Product $product): int
    {
        return $order->user
            ? (int) $product->effectivePrice($order->user)
            : (int) $product->price;
    }

    protected function minorUnits(string $value): int
    {
        return max(0, (int) round((float) str_replace([' ', ','], ['', '.'], $value) * 100));
    }
}
