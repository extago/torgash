<?php

namespace Torgash\Inventory;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Product;

/**
 * Stock accounting without 1C (PLAN §42). This first part is the journal:
 * every movement the catalogue and the warehouses announce is written down
 * with its cause, and the product form shows the latest ones.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Journal::class);
        $this->app->singleton(Batches::class);
    }

    public function boot(): void
    {
        // The journal, documents and costs are on no guest's page (PLAN §61);
        // stock itself is announced as it moves.
        page_cache()->unseen(['m_inventory_moves', 'm_inventory_move_costs', 'm_inventory_documents', 'm_inventory_document_lines', 'm_inventory_batches', 'm_inventory_order_costs']);


        Route::middleware(['web', 'auth', 'can:inventory.manage'])
            ->prefix('admin/inventory')
            ->name('admin.inventory.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        hook_listen('catalog.stock.moved', fn (array $move) => app(Journal::class)->write($move));

        // Already a log: recording it again in the audit is the same event twice.
        hook_listen('guard.audit.ignored_tables', fn (array $tables) => [...$tables, 'm_inventory_moves']);

        // What the order earned, and its own expenses and income (§42.5а),
        // as a card on the order — for whoever keeps the books.
        hook_listen('checkout.order.side_panels', function (array $panels, array $context) {
            $order = $context['order'];

            if (! auth()->user()?->can('inventory.manage') || $order->merged_into_id) {
                return $panels;
            }

            $profit = app(Profit::class);

            return [...$panels, ['key' => 'inventory.profit', 'position' => 20, 'html' => view('torgash.inventory::partials.order-panel', [
                'order' => $order,
                'profit' => $profit->forOrders([$order->id])[$order->id] ?? null,
                'rows' => \Illuminate\Support\Facades\DB::table('m_inventory_order_costs')->where('order_id', $order->id)->orderBy('id')->get(),
                'labels' => $profit->labels(),
                'costError' => null,
            ])->render()]];
        });

        // An order poured into another takes its expenses and income with it,
        // and gets them back if the merge is undone (PLAN §40.10/4).
        hook_listen('checkout.order.merging', function (array $context) {
            $ids = \Illuminate\Support\Facades\DB::table('m_inventory_order_costs')->where('order_id', $context['from']->id)->pluck('id')->all();
            \Illuminate\Support\Facades\DB::table('m_inventory_order_costs')->whereIn('id', $ids)->update(['order_id' => $context['into']->id]);
            $context['merge']->remember('torgash.inventory', $ids);
        });
        hook_listen('checkout.order.unmerging', function (array $context) {
            \Illuminate\Support\Facades\DB::table('m_inventory_order_costs')
                ->whereIn('id', (array) $context['merge']->remembered('torgash.inventory'))
                ->update(['order_id' => $context['from']->id]);
        });

        // The latest movements, under the product's stock on its form.
        hook_listen('catalog.product.stock_extra', function (string $value, array $context) {
            /** @var Product $product */
            $product = $context['product'];

            if ($product->id === null || ! auth()->user()?->can('inventory.manage')) {
                return $value;
            }

            return $value.view('torgash.inventory::partials.product-moves', ['product' => $product])->render();
        });
    }
}
