<?php

namespace Torgash\Attachments;

use App\Core\Modules\ModuleLifecycle;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Torgash\Attachments\Http\DownloadController;
use Torgash\Attachments\Models\Attachment;
use Torgash\Catalog\Models\Product;

/**
 * Documentation beside a product: files of the shop's own and links to the
 * maker's, in a section of the product page.
 *
 * Everything reaches the catalogue through its own hooks — the form's tab,
 * its save, the page's sections — so the catalogue has never heard of a
 * document, and a shop without this module has the product page it had.
 */
class AttachmentsServiceProvider extends ServiceProvider implements ModuleLifecycle
{
    public function register(): void
    {
        $this->app->singleton(Documents::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'can:admin.access'])
            ->prefix('admin/attachments')
            ->name('admin.attachments.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // Outside StorefrontRoutes: a file is the same file in every language,
        // and one address for it is what a link pasted anywhere needs.
        Route::middleware('web')
            ->get('attachments/{attachment}/{name}', DownloadController::class)
            ->whereNumber('attachment')
            ->where('name', '[A-Za-z0-9._-]+')
            ->name('attachments.download');

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
                'key' => 'documents',
                'label' => __('Documentation'),
                'html' => view('torgash.attachments::admin.editor', ['documentsProduct' => $product])->render(),
            ];

            return $tabs;
        });

        hook_listen('catalog.product.saved', function (array $context) {
            $product = $context['product'] ?? null;
            $input = $context['request']?->input('documents');

            if ($product instanceof Product && is_array($input)) {
                app(Documents::class)->applyForm('product', (int) $product->id, $input);
            }
        });

        // A product in the bin keeps its documents — it may come back. Gone
        // for good, it takes them and their files along.
        Product::forceDeleted(fn (Product $product) => app(Documents::class)->forget('product', (int) $product->id));
    }

    protected function productPage(): void
    {
        hook_listen('catalog.product.tabs.admin', fn (array $rows) => [...$rows, [
            'key' => 'documents',
            'label' => __('Documentation'),
            'sort' => 25,
            'module' => __('Documentation'),
            'note' => __('Shown on a product that has documents.'),
        ]]);

        // Sort 25: after the characteristics (20), before the reviews (30) —
        // the sheet is read after the numbers it explains.
        hook_listen('catalog.product.tabs', function (array $sections, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $sections;
            }

            $documents = app(Documents::class)->of('product', (int) $product->id);

            if ($documents->isEmpty()) {
                return $sections;
            }

            $sections[] = [
                'key' => 'documents',
                'label' => __('Documentation'),
                'count' => $documents->count(),
                'heading' => __('Documentation'),
                'sort' => 25,
                'html' => view('torgash.attachments::storefront.section', ['documents' => $documents])->render(),
                'section' => null,
            ];

            return $sections;
        });
    }

    public function install(): void {}

    /** The files on the private disk, which dropping the table would leave behind. */
    public function uninstall(): void
    {
        if (Schema::hasTable('m_attachments_files')) {
            Attachment::query()->get()->each->delete();
        }

        Storage::disk(Attachment::DISK)->deleteDirectory(Attachment::DIRECTORY);
    }
}
