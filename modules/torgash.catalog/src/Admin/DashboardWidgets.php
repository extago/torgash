<?php

namespace Torgash\Catalog\Admin;

use Torgash\Catalog\Models\Product;

/**
 * The catalogue's widget on the admin dashboard: what has run out and what is
 * about to. Only products in the plain `in_stock` status are counted — that is
 * the one status whose number decides whether it can be bought (a preorder or
 * an "unlimited" row has a number that means nothing), and only active ones:
 * a hidden product running out is nobody's emergency.
 */
class DashboardWidgets
{
    public function register(): void
    {
        hook_listen('core.admin.dashboard.widgets', function (array $widgets) {
            $widgets[] = [
                'key' => 'catalog.stock',
                'title' => 'Stock',
                'icon' => 'package-open',
                'size' => 'third',
                'position' => 40,
                'can' => 'catalog.manage',
                'link' => ['url' => route('admin.catalog.products.index'), 'label' => 'All products'],
                'render' => fn () => $this->stock(),
            ];

            return $widgets;
        });
    }

    protected function stock(): string
    {
        $threshold = max(1, (int) setting('torgash.catalog', 'stock_low_threshold', 10));
        $tracked = Product::query()->where('status', 'active')->where('availability_status', 'in_stock');

        $out = (clone $tracked)->where('stock', '<=', 0)->count();
        $low = (clone $tracked)->where('stock', '>', 0)->where('stock', '<=', $threshold)->count();

        $rows = (clone $tracked)->where('stock', '<=', $threshold)
            ->orderBy('stock')->orderBy('id')->limit(6)->get(['id', 'name', 'sku', 'stock'])
            ->map(fn (Product $product) => [
                'label' => $product->t('name'),
                'hint' => $product->sku,
                'url' => route('admin.catalog.products.edit', $product),
                'value' => $product->stock <= 0 ? __('none left') : __(':count left', ['count' => $product->stock]),
                'tone' => $product->stock <= 0 ? 'bad' : 'warn',
            ])->all();

        return view('admin.dashboard.list', [
            'stats' => [
                ['label' => __('ran out'), 'value' => $out, 'tone' => $out ? 'bad' : null],
                ['label' => __(':count or fewer left', ['count' => $threshold]), 'value' => $low, 'tone' => $low ? 'warn' : null],
                ['label' => __('products on sale'), 'value' => Product::query()->where('status', 'active')->count()],
            ],
            'rows' => $rows,
            'empty' => __('Nothing is running out.'),
        ])->render();
    }
}
