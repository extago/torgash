<?php

namespace Torgash\Checkout\Orders;

use Torgash\Cart\Lines;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;

/**
 * Changing the BUILD of a line of a placed order (PLAN §33.7).
 *
 * The hole this fills: an order could be re-priced, its quantities changed and
 * its lines added to and removed, but a configured line could only be deleted
 * — so "make it 32 GB instead of 16" meant destroying the server line, its
 * parts and its warehouse breakdown, and assembling a plain one in their
 * place. Orders are money; a correction must not be a demolition.
 *
 * The one place it happens, exactly as `PlaceOrder` is the one place an order
 * is created. What it does NOT do is own any arithmetic: the configuration is
 * normalised and refused by whoever understands it (`cart.line.config`), it is
 * priced by the same `Lines::parts()` the cart prices a line with, and the
 * lines are rebuilt by `OrderLines::forLine()` — the derivation placement
 * itself uses. Three borrowed steps, so a hand-edited order comes out shaped
 * like a placed one.
 *
 * **The money rule: the order's own price for the product, today's rules for
 * the answers.** The base price is the line's, frozen at placement — the
 * customer agreed to it and an edit elsewhere on the order may not move it, so
 * "10% of the product" means 10% of what this order sold it for. What a NEW
 * answer costs is today's, which is the same rule the editor's own "add a
 * product" button already follows. The manager can type over the unit price
 * afterwards either way.
 *
 * Checkout names no configurator here. A module that answers
 * `checkout.order.line_config_form` with controls, and `cart.line.config` /
 * `cart.line.parts` with meaning, is editable in the admin without this class
 * hearing of it — and a shop with no such module is offered no button at all.
 */
class LineConfig
{
    /**
     * The controls for this line, or '' when nothing can be configured about
     * it — which is also the answer for every line in a shop with no options
     * module, and therefore how the button decides whether to exist.
     *
     * Asked about the PRODUCT, never about what happens to be stored: a line
     * bought with no answers is still a line whose product asks questions, and
     * a manager adding a warranty to it is the same act as changing one.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $errors
     */
    public function form(Order $order, array $line, array $errors = []): string
    {
        $product = $this->productFor($line);

        if (! $product) {
            return '';
        }

        return (string) hook_filter('checkout.order.line_config_form', '', [
            'order' => $order,
            'line' => $line,
            'product' => $product,
            'config' => (array) ($line['config'] ?? []),
            'errors' => $errors,
        ]);
    }

    /**
     * Is there anything to configure about this line — the question the table
     * asks about every row it draws, which is why it is not "render the form
     * and look at it": a page of twelve lines would build twelve
     * configurators to decide whether to print twelve buttons. The same split
     * the product page already lives by (`Groups::has()` beside the form).
     *
     * @param  array<string, mixed>  $line
     */
    public function isConfigurable(Order $order, array $line): bool
    {
        $product = $this->productFor($line);

        if (! $product) {
            return false;
        }

        return (bool) hook_filter('checkout.order.line_configurable', false, [
            'order' => $order,
            'line' => $line,
            'product' => $product,
        ]);
    }

    /**
     * Rewrite one line of `$items` with what was submitted, and hand back the
     * whole list for the caller to commit.
     *
     * Only the PARENT line and its parts are touched; everything else on the
     * order is passed through untouched, including a part of some OTHER line
     * that happens to sit between them in the array.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $submitted  the configuration bag, as a form posts it
     * @return list<array<string, mixed>>
     *
     * @throws \Illuminate\Validation\ValidationException  by whoever understands the configuration
     */
    public function apply(Order $order, array $items, string $lineKey, array $submitted): array
    {
        $index = $this->indexOf($items, $lineKey);

        if ($index === false) {
            return $items;
        }

        $line = $items[$index];
        $product = $this->productFor($line);

        if (! $product) {
            return $items;
        }

        // Normalised and REFUSED by whoever owns the meaning — the same seam
        // the cart passes a configuration through, so a choice the shop has
        // switched off cannot be sold from the admin either. It throws, and
        // the controller turns that back into the form.
        $config = (array) hook_filter('cart.line.config', $submitted, [
            'product' => $product,
            'order' => $order,
        ]);

        $qty = max(1, (int) $line['qty']);
        $base = $this->baseOf($line);
        $parts = Lines::parts($product, $qty, $config, $base);

        $rebuilt = OrderLines::forLine([
            // Its own key, kept: the warehouse breakdown, this screen's URL and
            // any document already naming this line all point at it.
            'key' => (string) ($line['key'] ?? $line['product_id'] ?? ''),
            'product_id' => (int) $line['product_id'],
            'sku' => $line['sku'] ?? $product->sku,
            // The name as the order records it, not as the catalogue says it
            // today: a product renamed since is still what the customer bought.
            'name' => $line['name'] ?? $product->t('name'),
            'qty' => $qty,
            'base_price' => $base,
            'config' => $config,
        ], $parts);

        // The breakdown belongs to the line, not to its build: these are the
        // same units of the same product, and re-deriving would tell the
        // warehouse they came from nowhere.
        if (isset($line['warehouses'])) {
            $rebuilt[0]['warehouses'] = $line['warehouses'];
        }

        $parentKey = (string) $rebuilt[0]['key'];

        // Out with the line and everything that was bought as part of it, in
        // with what it is now. OrderStock sees the difference by key: a part
        // that is gone is credited back to the warehouse, a new one is taken
        // — which is the whole reason the parts are rebuilt rather than
        // patched.
        $rest = array_values(array_filter(
            $items,
            fn (array $item) => (string) ($item['key'] ?? $item['product_id'] ?? '') !== $parentKey
                && (string) ($item['parent_key'] ?? '') !== $parentKey,
        ));

        array_splice($rest, min($index, count($rest)), 0, $rebuilt);

        return array_values($rest);
    }

    /**
     * What the line says its build is, in words, for the log — the shop's own
     * snapshot of it rather than a re-reading of the answers, because that is
     * what the rest of the order prints.
     *
     * @param  array<string, mixed>  $line
     */
    public static function describe(array $line): string
    {
        $words = array_map(
            fn (array $option) => (string) $option['label'].(($option['qty'] ?? 1) > 1 ? ' ×'.$option['qty'] : ''),
            array_values((array) ($line['options'] ?? [])),
        );

        return $words === [] ? __('nothing') : implode(', ', $words);
    }

    /**
     * The product's own price on THIS order, before anything was added to it.
     *
     * Stored since configured lines learned to remember their answers; worked
     * back out for a line placed before that, where it is exact because
     * `options` is then the only thing between the two numbers.
     *
     * @param  array<string, mixed>  $line
     */
    protected function baseOf(array $line): int
    {
        if (isset($line['base_price'])) {
            return (int) $line['base_price'];
        }

        $folded = array_sum(array_map(
            fn (array $option) => (int) ($option['amount'] ?? 0),
            array_values((array) ($line['options'] ?? [])),
        ));

        return (int) $line['unit_price'] - (int) $folded;
    }

    /** @param  array<string, mixed>  $line */
    protected function productFor(array $line): ?Product
    {
        // A part of something is not configured on its own: it exists because
        // its parent's build says so, and editing it there is the only place
        // the two can stay in step.
        if (($line['parent_key'] ?? null) !== null) {
            return null;
        }

        return Product::find((int) ($line['product_id'] ?? 0));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function indexOf(array $items, string $lineKey): int|string|false
    {
        return collect($items)->search(
            fn ($item) => (string) ($item['key'] ?? $item['product_id'] ?? '') === $lineKey,
        );
    }
}
