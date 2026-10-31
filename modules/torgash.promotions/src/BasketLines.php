<?php

namespace Torgash\Promotions;

use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Product;

class BasketLines
{
    /**
     * The cart as this module reads it — and the one place another module can
     * take a line OUT of what a promotion may discount.
     *
     * A line already carrying a discount of its own (a kit's, today) is not
     * discounted twice: that is the shop owner's own rule (PLAN §33.8), and
     * "the case is half price in the set AND ten per cent off because it is an
     * accessory" is how a shop ends up selling below cost. `discounted` is
     * stated here rather than guessed by the answerer — a line whose parts
     * take money OFF is discounted, whoever put that part there.
     *
     * @return list<array{product_id: int, category_ids: list<int>, qty: int, line_total: int, discounted: bool}>
     */
    public static function of(Collection $items): array
    {
        $productIds = $items->pluck('product.id')->all();
        $categoriesByProduct = Product::whereIn('id', $productIds)
            ->with('categories:id')
            ->get()
            ->mapWithKeys(fn (Product $p) => [$p->id => $p->categories->pluck('id')->map('intval')->all()]);

        $lines = $items->map(fn ($item) => [
            // The line's own name and what it is made of. Both are its PUBLIC
            // shape (cart states a line as `{key, product_id, qty, config}`
            // and prices it into parts), and neither is read here: they are
            // for a module that priced this line and has to recognise its own
            // work — see `config` below.
            'key' => $item->key,
            'parts' => (array) ($item->parts ?? []),
            'product_id' => $item->product->id,
            'category_ids' => $categoriesByProduct[$item->product->id] ?? [],
            'qty' => $item->qty,
            'line_total' => $item->line_total,
            'discounted' => collect($item->parts ?? [])->contains(fn (array $part) => $part['amount'] < 0),
            // The line's own configuration bag, passed through untouched and
            // never read here: it is the line's PUBLIC shape (cart states it
            // as `{key, product_id, qty, config}`), and a module that priced
            // this line needs it to recognise its own work. `discounted`
            // above only catches a discount written as a negative part, and
            // not every module writes one — a kit prices its parts below the
            // shelf price instead.
            'config' => (array) ($item->config ?? []),
        ])->all();

        return array_values(hook_filter('promotions.cart_lines', $lines, []));
    }
}
