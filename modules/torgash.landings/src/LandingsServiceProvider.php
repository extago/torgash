<?php

namespace Torgash\Landings;

use App\Core\Modules\ModuleLifecycle;
use App\Core\Support\PerRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Product;
use Torgash\Landings\Models\Landing;

class LandingsServiceProvider extends ServiceProvider implements ModuleLifecycle
{
    public function register(): void
    {
        $this->app->singleton(Landings::class);
        $this->app->singleton(Renderer::class);
        $this->app->singleton(Files::class);
        // Which landings are on: a memo of a small table the admin edits.
        // The renderer remembers the page has its one script already.
        PerRequest::forget($this->app, Landings::class, Renderer::class);
        PerRequest::flushPerJob($this->app, Landings::class);
    }

    public function boot(): void
    {
        // A landing's dates open and close it with nothing written: a page
        // kept ready for guests is kept only until the next (PLAN §61).
        page_cache()->changes(fn () => page_cache()->nextDateIn('m_landings'));

        Route::middleware(['web', 'auth', 'can:landings.manage'])
            ->prefix('admin/landings')
            ->name('admin.landings.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        $this->productPage();
        $this->productForm();
    }

    /**
     * The four places on a product's page. Above and below are the catalogue's
     * two zones around the description, INSTEAD its `description_instead`
     * (the text stays the product's — feeds, the .md page, the meta tags), and
     * a tab is a section of the page like any other, which the shop may
     * rename and move like any other (`section_key`). Listeners resolve the
     * services: they outlive a request under a worker.
     */
    protected function productPage(): void
    {
        foreach (['before' => 'catalog.product.before_description', 'instead' => 'catalog.product.description_instead', 'after' => 'catalog.product.after_description'] as $placement => $hook) {
            hook_listen($hook, function (string $html, array $context) use ($placement) {
                $product = $context['product'] ?? null;

                if (! $product instanceof Product) {
                    return $html;
                }

                foreach (app(Landings::class)->at($product, $placement) as $landing) {
                    $html .= app(Renderer::class)->render($landing, $product);
                }

                return $html;
            });
        }

        hook_listen('catalog.product.tabs', function (array $sections, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $sections;
            }

            foreach (app(Landings::class)->at($product, 'tab') as $landing) {
                $html = app(Renderer::class)->render($landing, $product);

                if ($html === '') {
                    continue;
                }

                $title = $landing->t('tab_title') ?: $landing->name;
                $sections[] = [
                    'key' => 'landing-'.$landing->id,
                    'section_key' => 'landing-'.$landing->id,
                    'label' => $title,
                    'heading' => $title,
                    'sort' => (int) $landing->sort,
                    'html' => $html,
                    'section' => null,
                ];
            }

            return $sections;
        });
    }

    /**
     * The product form's "Landings" tab: which landings this product shows and
     * why, and the two answers a shop gives about ONE product — "show it here
     * too" and "never here" — saved with the product (`catalog.product.saved`),
     * because the tab is inside the product's form.
     */
    protected function productForm(): void
    {
        hook_listen('catalog.product.form_tabs', function (array $tabs, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product || ! $product->exists || ! auth()->user()?->can('landings.manage') || ! Landing::query()->exists()) {
                return $tabs;
            }

            $tabs[] = [
                'key' => 'landings',
                'label' => __('Landings'),
                'html' => view('torgash.landings::admin.product-tab', ProductTab::data($product))->render(),
            ];

            return $tabs;
        });

        hook_listen('catalog.product.saved', function (array $context) {
            $product = $context['product'] ?? null;
            $request = $context['request'] ?? null;

            if ($product instanceof Product && $request instanceof Request && $request->has('landings_form') && auth()->user()?->can('landings.manage')) {
                ProductTab::save($product, (array) $request->input('landings', []));
            }
        });
    }

    public function install(): void {}

    /** Its tables go with the migrations; its files are on the public disk. */
    public function uninstall(): void
    {
        Storage::disk('public')->deleteDirectory(Landing::FILES);
    }
}
