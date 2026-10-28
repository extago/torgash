<?php

namespace Torgash\Sku;

use App\Core\Support\PerRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Product;

class SkuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Generator::class);
        $this->app->singleton(Templates::class);
        $this->app->singleton(Assigner::class);
        // The category templates are a memo of a small table the admin edits:
        // a reused application (a worker) and a queue job start afresh.
        PerRequest::forget($this->app, Templates::class, Assigner::class);
        PerRequest::flushPerJob($this->app, Templates::class);
    }

    public function boot(): void
    {
        // The counters codes are drawn from are on no guest's page (PLAN §61).
        page_cache()->unseen('m_sku_counters');

        Route::middleware(['web', 'auth', 'can:sku.manage'])
            ->prefix('admin/sku')
            ->name('admin.sku.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // The catalogue asks for every new product (Product::booted()); the
        // listeners RESOLVE the assigner, never capture it — they outlive
        // the request under a worker.
        hook_listen('catalog.product.sku', fn (string $sku, array $context) => $context['product'] instanceof Product
            ? app(Assigner::class)->onCreate($sku, $context['product'], (bool) ($context['typed'] ?? false))
            : $sku);

        // The admin form has synced the categories: a category's own
        // template may apply now.
        hook_listen('catalog.product.saved', function (array $context) {
            if (($context['product'] ?? null) instanceof Product) {
                app(Assigner::class)->afterSave($context['product']);
            }
        });
    }
}
