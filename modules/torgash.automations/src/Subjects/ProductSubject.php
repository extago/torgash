<?php

namespace Torgash\Automations\Subjects;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Torgash\Automations\Models\ProductBatch;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleCondition;
use Torgash\Automations\ProductWatch;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;

/**
 * Products — the shelf itself, whoever moved it: a sale, a write-off, a
 * delivery, an import, 1C (PLAN §64.2). The events are crossings worked out
 * by {@see ProductWatch} once per act, so what a rule is about is not one
 * product but every product the same thing happened to at once — a
 * {@see ProductBatch}. A message therefore speaks of a LIST
 * (`{{#products}}…{{/products}}`, `{{ products_text }}`, `{{ count }}`), and
 * `{{ product.name }}` is the first of it, for the usual case of one.
 *
 * Conditions narrow the list — "only the ones in this category" — and the
 * rule runs for what is left; so does the rule's quiet period, kept per
 * product.
 */
class ProductSubject extends BaseSubject
{
    /** How many lines of the list a message prints before "…and N more". */
    public const LINES = 30;

    public static function label(): string
    {
        return 'Product';
    }

    public function triggers(): array
    {
        return [
            ProductWatch::LOW => 'A product runs low',
            ProductWatch::OUT => 'A product runs out',
            ProductWatch::RESTOCKED => 'A product is back in stock',
            ProductWatch::PRICE => 'A product\'s price changes',
        ];
    }

    public function listen(Closure $fire): void
    {
        hook_listen('automations.product.batch', function (array $context) use ($fire) {
            if (($context['batch'] ?? null) instanceof ProductBatch) {
                $fire($context['batch']->event, $context['batch']);
            }
        });
    }

    public function find(int $id): ?Model
    {
        return ProductBatch::find($id);
    }

    /**
     * The newest batch — or, in a shop where nothing has happened yet, one
     * made up of its newest product, so "Test" has something to show.
     */
    public function sample(): ?Model
    {
        if ($batch = ProductBatch::latest('id')->first()) {
            return $batch;
        }

        $product = Product::latest('id')->first();

        return $product ? ProductBatch::create([
            'event' => ProductWatch::LOW,
            'items' => [['id' => (int) $product->id, 'before' => (int) $product->stock + 1, 'after' => (int) $product->stock]],
        ]) : null;
    }

    public function context(Model $model, string $event, array $changed = []): array
    {
        /** @var ProductBatch $model */
        $priced = $event === ProductWatch::PRICE;
        $products = Product::whereIn('id', $model->productIds())->get()->keyBy('id');
        $rows = [];

        foreach ((array) $model->items as $item) {
            if (! $product = $products[(int) ($item['id'] ?? 0)] ?? null) {
                continue;
            }

            $price = (int) $product->price;
            $priceBefore = $priced ? (int) $item['before'] : $price;

            $rows[] = [
                'id' => (int) $product->id,
                'name' => (string) $product->t('name', content_base_locale()),
                'sku' => (string) $product->sku,
                'stock' => (int) $product->stock,
                'stock_before' => $priced ? (int) $product->stock : (int) $item['before'],
                'low_at' => $product->lowStockThreshold(),
                'price' => price_scale()->trimmed($price),
                'price_formatted' => admin_unit_price($price),
                'price_before' => price_scale()->trimmed($priceBefore),
                'price_before_formatted' => admin_unit_price($priceBefore),
                'price_went' => $price <=> $priceBefore,
                'url' => route('catalog.product', $product->slug),
                'admin_url' => route('admin.catalog.products.edit', $product->id),
            ];
        }

        $lines = array_map(fn (array $row) => $priced
            ? trim($row['sku'].' '.$row['name']).': '.$row['price_before_formatted'].' → '.$row['price_formatted']
            : trim($row['sku'].' '.$row['name']).' — '.__(':count pcs.', ['count' => $row['stock']]), $rows);
        $more = count($lines) - self::LINES;

        return [
            'event' => $event,
            'changed' => '',
            'shop' => ['name' => (string) config('app.name'), 'url' => (string) config('app.url')],
            'count' => count($rows),
            'several' => count($rows) > 1,
            'products' => $rows,
            'products_text' => implode("\n", array_slice($lines, 0, self::LINES))
                .($more > 0 ? "\n".__('…and :count more', ['count' => $more]) : ''),
            'product' => $rows[0] ?? [],
        ];
    }

    public function variables(): array
    {
        return [
            'Products' => [
                'count' => 'How many products this happened to at once',
                'products_text' => 'All of them, a line each: "SKU Name — 2 pcs." (the first 30, then "…and N more")',
                'several' => 'true when there is more than one',
            ],
            'The first product' => [
                'product.name' => 'Product name',
                'product.sku' => 'SKU',
                'product.stock' => 'Stock now',
                'product.stock_before' => 'Stock before',
                'product.low_at' => 'At how many pieces it is "running low"',
                'product.price_formatted' => 'Price with the currency',
                'product.price_before_formatted' => 'Price before, with the currency',
                'product.price' => 'Price as a number',
                'product.price_before' => 'Price before, as a number',
                'product.url' => 'Link to the product',
                'product.admin_url' => 'Link to the product in the admin',
            ],
            'Each product (inside the products loop)' => [
                'item.name' => 'Product name',
                'item.sku' => 'SKU',
                'item.stock' => 'Stock now',
                'item.stock_before' => 'Stock before',
                'item.price_formatted' => 'Price with the currency',
                'item.price_before_formatted' => 'Price before, with the currency',
                'item.url' => 'Link to the product',
                'item.admin_url' => 'Link to the product in the admin',
            ],
        ];
    }

    public function conditions(): array
    {
        return [
            'category' => [
                'label' => 'Product category',
                'operators' => ['in' => 'is in', 'not_in' => 'is not in'],
                'input' => 'categories',
                'options' => app(CategoryTree::class)->options(),
            ],
            'price' => [
                'label' => 'Price',
                'operators' => ['gte' => 'is at least', 'lte' => 'is at most'],
                'input' => 'amount',
            ],
            'stock' => [
                'label' => 'Stock',
                'operators' => ['lte' => 'is at most', 'gte' => 'is at least'],
                'input' => 'text',
                'placeholder' => '0',
            ],
            'price_went' => [
                'label' => 'The price',
                'operators' => ['down' => 'went down', 'up' => 'went up'],
                'input' => 'none',
            ],
        ];
    }

    /**
     * Narrows the batch to the products the condition holds for, and passes
     * while any is left: every condition after it — and the message — then
     * speaks of those alone.
     */
    public function passes(RuleCondition $condition, Model $model): bool
    {
        /** @var ProductBatch $model */
        $ids = $model->productIds();

        if ($ids === []) {
            return false;
        }

        $products = DB::table('m_catalog_products')->whereIn('id', $ids)->get(['id', 'price', 'stock'])->keyBy('id');
        $number = (int) preg_replace('/[^\d-]/', '', $condition->text());
        $inside = [];

        if ($condition->kind === 'category') {
            $wanted = app(CategoryTree::class)->descendants($condition->ids());
            $inside = DB::table('m_catalog_category_product')->whereIn('product_id', $ids)->whereIn('category_id', $wanted)
                ->pluck('product_id')->map('intval')->flip()->all();
        }

        $model->narrow(function (array $item) use ($condition, $products, $number, $inside, $model) {
            $product = $products[(int) $item['id']] ?? null;

            if (! $product) {
                return false;
            }

            return match ($condition->kind.':'.$condition->operator) {
                'category:in' => isset($inside[(int) $item['id']]),
                'category:not_in' => ! isset($inside[(int) $item['id']]),
                'price:gte' => price_scale()->toMinor((int) $product->price) >= $condition->amount(),
                'price:lte' => price_scale()->toMinor((int) $product->price) <= $condition->amount(),
                'stock:lte' => (int) $product->stock <= $number,
                'stock:gte' => (int) $product->stock >= $number,
                // Only a price event knows which way; on any other the question has no answer.
                'price_went:down' => $model->event === ProductWatch::PRICE && (int) $item['after'] < (int) $item['before'],
                'price_went:up' => $model->event === ProductWatch::PRICE && (int) $item['after'] > (int) $item['before'],
                default => false,
            };
        });

        return $model->items !== [];
    }

    /**
     * "Not more often than…", per PRODUCT: the ones this rule spoke of since
     * `$since` leave the list, the rest are remembered as spoken of now.
     * True when nothing is left to say.
     */
    public function quiet(Rule $rule, Model $model, Carbon $since): bool
    {
        /** @var ProductBatch $model */
        $told = DB::table('m_automations_product_marks')->where('rule_id', $rule->id)
            ->whereIn('product_id', $model->productIds())->where('told_at', '>=', $since)
            ->pluck('product_id')->map('intval')->flip()->all();

        $model->narrow(fn (array $item) => ! isset($told[(int) $item['id']]));

        if ($model->items === []) {
            return true;
        }

        DB::table('m_automations_product_marks')->upsert(
            array_map(fn (int $id) => ['rule_id' => $rule->id, 'product_id' => $id, 'told_at' => now()], $model->productIds()),
            ['rule_id', 'product_id'],
            ['told_at'],
        );

        return false;
    }

    public function refs(array $ids): array
    {
        $batches = ProductBatch::whereIn('id', $ids)->get();
        $firsts = Product::withTrashed()->whereIn('id', $batches->map(fn (ProductBatch $b) => $b->productIds()[0] ?? 0)->filter()->all())
            ->get()->keyBy('id');
        $refs = [];

        foreach ($batches as $batch) {
            $count = count((array) $batch->items);
            $first = $firsts[$batch->productIds()[0] ?? 0] ?? null;

            $refs[$batch->id] = [
                'label' => $count === 1 && $first
                    ? ((string) $first->sku ?: (string) $first->t('name'))
                    : trans_choice(':count product|:count products', $count, ['count' => $count]),
                'url' => $count === 1 && $first ? route('admin.catalog.products.edit', $first->id) : null,
            ];
        }

        return $refs;
    }
}
