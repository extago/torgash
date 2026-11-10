<?php

namespace Torgash\Age;

use App\Core\Storefronts\StorefrontRoutes;
use App\Core\Support\PerRequest;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Torgash\Age\Http\Admin\OverviewController;
use Torgash\Age\Http\PlateController;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Orders\OrderSource;

/**
 * Age limits (PLAN §71): goods for adults, by category, by product or for the
 * whole shop.
 *
 * What it does to a visitor who has not said they are old enough — photos
 * covered by a plate, or the goods left out of lists — and the strip or the
 * window that asks; what it does to an order of such goods — a tick, and a
 * note for the manager to check an ID on delivery, which is the only real
 * check there is.
 *
 * It reaches the catalogue and checkout only through their hooks: the one
 * question every product picture asks (`catalog.product.picture_cover`), the
 * listing's query, the page's product or category, the forms' "who sees it"
 * place and their saves, the checkout's consents and its placing. Every
 * listener resolves `Rules` / `Visitor` inside itself: both are per request
 * (PerRequest), a captured one would serve the next visitor this one's age.
 */
class AgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Rules::class);
        $this->app->singleton(Visitor::class);
        PerRequest::forget($this->app, Rules::class, Visitor::class);
    }

    public function boot(): void
    {
        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        // No session, no cookies: a picture, the same for everybody.
        Route::get('/age/plate/{age}.svg', PlateController::class)->whereNumber('age')->name('age.plate');

        Route::middleware(['web', 'auth', 'can:catalog.view'])
            ->get('/admin/age', OverviewController::class)->name('admin.age.index');

        // Written by the button's POST in plain text and read before anything
        // decrypts (the page cache's vary) — so never encrypted.
        EncryptCookies::except([Visitor::COOKIE]);

        // The pages are drawn differently for it — plates or photos, the
        // strip or none — so each answer is its own copy. Read straight off
        // the request: asked of every visitor on every page.
        page_cache()
            ->vary('age', fn (Request $request) => Visitor::cookieAge($request) ?: null, cookies: [Visitor::COOKIE])
            ->unseen('m_age_orders');

        // A manager looking through a customer's eyes may answer for them.
        hook_listen('core.impersonation.writes', fn (array $routes) => [...$routes, 'age.confirm']);

        $this->storefront();
        $this->forms();
        $this->checkout();
    }

    protected function storefront(): void
    {
        hook_listen('catalog.product.picture_cover', function ($cover, array $context) {
            $product = $context['product'] ?? null;

            return $cover ?? ($product instanceof Product ? app(Visitor::class)->cover($product) : null);
        });

        // A page of products: their own rows in one query (the catalogue has
        // loaded their categories already, at priority 1). Only where the
        // limits apply — the admin's list and the API announce pages too.
        hook_listen('catalog.products.listed', function (array $context) {
            if (app(Visitor::class)->applies()) {
                app(Rules::class)->prime(collect($context['products'] ?? [])->filter(fn ($p) => $p instanceof Product));
            }
        }, 20);

        hook_listen('catalog.products.listed_query', fn ($query) => app(Visitor::class)->narrow($query));

        // The sitemap has no visitor: it is told what a visitor who has said
        // nothing would not see, so a picture search does not show it either.
        hook_listen('catalog.sitemap.covered_pictures', function (array $ids, array $context) {
            $covered = app(Rules::class)->coveredForGuests();
            $listed = collect($context['products'] ?? [])->pluck('id')->map(fn ($id) => (int) $id);

            return [...$ids, ...($covered === null ? $listed : $listed->intersect($covered))->values()->all()];
        });

        hook_listen('catalog.product.view', function ($product) {
            if ($product instanceof Product && app(Visitor::class)->applies()) {
                app(Visitor::class)->onPageOf(app(Rules::class)->forProduct($product));
            }

            return $product;
        });

        hook_listen('catalog.category.view', function ($category) {
            if ($category instanceof Category && app(Visitor::class)->applies()) {
                app(Visitor::class)->onPageOf(app(Rules::class)->forCategory($category));
            }

            return $category;
        });

        // The strip at the top of the page: drawn after the controller said
        // which product or category the page is (a layout is drawn last).
        hook_listen('core.body_start', function (string $html) {
            $age = app(Visitor::class)->stripAge();

            return $age > 0 ? $html.view('torgash.age::storefront.strip', ['ageAsk' => $age])->render() : $html;
        });

        hook_listen('core.footer', function (string $html) {
            $age = app(Visitor::class)->windowAge();

            return $age > 0 ? $html.view('torgash.age::storefront.window', [
                'ageAsk' => $age,
                'ageEverywhere' => setting('torgash.age', 'window', 'off') === 'everywhere',
            ])->render() : $html;
        });
    }

    /** "Age limit" beside "Who sees it" on a category's and a product's form. */
    protected function forms(): void
    {
        hook_listen('catalog.category.visibility_extra', function (string $html, array $context) {
            $category = $context['category'] ?? null;

            if (! $category instanceof Category) {
                return $html;
            }

            $own = $category->exists ? DB::table('m_age_category_limits')->where('category_id', $category->id)->first() : null;

            return $html.view('torgash.age::admin.fields', [
                'ageSubject' => 'category',
                'ageOwn' => $own ? ['age' => (int) $own->age, 'display' => $own->display] : null,
                // What reaches it from above: the parent's limit and the shop's.
                'ageInherited' => app(Rules::class)->forCategory((int) $category->parent_id),
            ])->render();
        });

        hook_listen('catalog.product.visibility_extra', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $html;
            }

            $rules = app(Rules::class);
            $inherited = $rules->site();

            if ($product->exists) {
                foreach ($product->categories()->pluck('m_catalog_categories.id') as $categoryId) {
                    $inherited = Limit::merge($inherited, $rules->forCategory((int) $categoryId));
                }
            }

            return $html.view('torgash.age::admin.fields', [
                'ageSubject' => 'product',
                'ageOwn' => $product->exists ? $rules->ownRow((int) $product->id) : null,
                'ageInherited' => $inherited,
            ])->render();
        });

        hook_listen('catalog.category.saved', function (array $context) {
            if (($category = $context['category'] ?? null) instanceof Category) {
                $this->saveRow('m_age_category_limits', 'category_id', (int) $category->id, $context['request'] ?? null);
            }
        });

        hook_listen('catalog.product.saved', function (array $context) {
            if (($product = $context['product'] ?? null) instanceof Product) {
                $this->saveRow('m_age_product_limits', 'product_id', (int) $product->id, $context['request'] ?? null);
            }
        });
    }

    /** One form's "Age limit": a number keeps or replaces the row, nothing removes it. */
    protected function saveRow(string $table, string $key, int $id, ?Request $request): void
    {
        // Only a form that had the field: an import, a quick edit or a copy
        // does not wipe what it never showed.
        if (! $request || ! $request->boolean('age_form')) {
            return;
        }

        $age = Rules::clampAge($request->input('age_limit'));
        $display = (string) $request->input('age_display', '');

        if ($age === 0) {
            DB::table($table)->where($key, $id)->delete();

            return;
        }

        DB::table($table)->upsert([[
            $key => $id,
            'age' => $age,
            'display' => in_array($display, Limit::KINDS, true) ? $display : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]], [$key], ['age', 'display', 'updated_at']);
    }

    protected function checkout(): void
    {
        hook_listen('checkout.form.consents', function (string $html) {
            if (! setting('torgash.age', 'confirm_at_checkout', true)) {
                return $html;
            }

            $age = app(Rules::class)->ageOf(array_column(app(\Torgash\Cart\CartManager::class)->raw(), 'product_id'));

            return $age > 0 ? $html.view('torgash.age::storefront.checkout-tick', ['ageAsk' => $age])->render() : $html;
        });

        hook_listen('checkout.one_click.consents', function (string $html, array $context) {
            if (! setting('torgash.age', 'confirm_at_checkout', true)) {
                return $html;
            }

            $product = $context['product'] ?? null;
            $age = app(Rules::class)->ageOf($product instanceof Product
                ? [$product]
                : array_column(app(\Torgash\Cart\CartManager::class)->raw(), 'product_id'));

            return $age > 0 ? $html.view('torgash.age::storefront.one-click-tick', [
                'ageAsk' => $age,
                'ageTicked' => (bool) (($context['values'] ?? [])['age_confirmed'] ?? old('age_confirmed')),
            ])->render() : $html;
        });

        // Inside the order's transaction: throwing refuses the order, and the
        // note is written with it or not at all.
        hook_listen('checkout.order.placing', function (array $context) {
            $order = $context['order'] ?? null;

            if (! $order) {
                return;
            }

            $ids = collect((array) ($order->items ?? []))->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $age = app(Rules::class)->ageOf($ids);

            if ($age === 0) {
                return;
            }

            // Asked of the buyer only where the buyer is the one pressing:
            // the storefront's checkout and one click. A manager's order, the
            // API and 1C carry the note without it.
            $asked = setting('torgash.age', 'confirm_at_checkout', true)
                && in_array((string) $order->source, [OrderSource::CHECKOUT, OrderSource::ONE_CLICK], true);

            if ($asked && ! request()->boolean('age_confirmed')) {
                throw ValidationException::withMessages([
                    'age_confirmed' => __('Tick that you are :age or older: the order has goods sold only to people of that age.', ['age' => $age]),
                ]);
            }

            DB::table('m_age_orders')->insert([
                'order_id' => $order->id,
                'age' => $age,
                'confirmed_at' => $asked ? now() : null,
                'created_at' => now(),
            ]);
        });

        hook_listen('checkout.order.side_panels', function (array $panels, array $context) {
            $order = $context['order'] ?? null;
            $note = $order ? DB::table('m_age_orders')->where('order_id', $order->id)->first() : null;

            if ($note) {
                $panels[] = ['key' => 'age.check', 'position' => 5, 'html' => view('torgash.age::admin.order-panel', ['ageNote' => $note])->render()];
            }

            return $panels;
        });
    }
}
