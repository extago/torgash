<?php

namespace Torgash\Model3d;

use App\Core\Modules\ModuleLifecycle;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Product;
use Torgash\Media\Gallery;
use Torgash\Model3d\Models\Model3d;

/**
 * A product's 3D model among its photos.
 *
 * It reaches the catalogue only through its hooks — the form's tab, its save,
 * the gallery's items — and rides the gallery's contract for a played item
 * (`video => true` + a `player` kept inert until pressed), so the catalogue
 * draws a model's slide without knowing what one is. The viewer
 * (model-viewer, 1 MB) is fetched on the first press and never before.
 */
class Model3dServiceProvider extends ServiceProvider implements ModuleLifecycle
{
    public function register(): void
    {
        $this->app->singleton(Models3d::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'can:admin.access'])
            ->prefix('admin/model3d')
            ->name('admin.model3d.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        $this->productForm();
        $this->productPage();
    }

    protected function productForm(): void
    {
        hook_listen('catalog.product.form_tabs', function (array $tabs, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $tabs;
            }

            $tabs[] = [
                'key' => 'model3d',
                'label' => __('3D'),
                'html' => view('torgash.model3d::admin.editor', ['model3dProduct' => $product])->render(),
            ];

            return $tabs;
        });

        hook_listen('catalog.product.saved', function (array $context) {
            $product = $context['product'] ?? null;
            $input = $context['request']?->input('models3d');

            if ($product instanceof Product && is_array($input)) {
                app(Models3d::class)->applyForm('product', (int) $product->id, $input);
            }
        });

        Product::forceDeleted(fn (Product $product) => app(Models3d::class)->forget('product', (int) $product->id));
    }

    protected function productPage(): void
    {
        // After the photos (and beside the videos), in the admin's order.
        hook_listen('catalog.product.gallery', function (array $items, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $items;
            }

            foreach (app(Models3d::class)->of('product', (int) $product->id) as $model) {
                $poster = $model->poster();
                $title = $model->t('title') ?: (string) $product->t('name');

                $items[] = [
                    'video' => true,
                    'fill' => true,
                    'play_icon' => 'rotate-3d',
                    'play_label' => __('3D view: :title', ['title' => $title]),
                    'thumb' => $poster ? Gallery::thumbUrl($poster, 120) : null,
                    'stage' => $poster ? Gallery::thumbUrl($poster, 800) : null,
                    'full' => null,
                    'width' => null,
                    'height' => null,
                    'alt' => $title,
                    'platform' => '',
                    'player' => view('torgash.model3d::storefront.player', [
                        'viewerModel' => $model,
                        'viewerTitle' => $title,
                        'viewerPoster' => $poster ? Gallery::thumbUrl($poster, 800) : null,
                    ])->render(),
                ];
            }

            return $items;
        });
    }

    public function install(): void {}

    /** The .glb files on the public disk; the posters go with their rows. */
    public function uninstall(): void
    {
        if (Schema::hasTable('m_model3d_models')) {
            Model3d::query()->get()->each->delete();
        }

        Storage::disk(Model3d::DISK)->deleteDirectory(Model3d::DIRECTORY);
    }
}
