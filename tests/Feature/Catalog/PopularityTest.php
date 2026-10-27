<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Tests\TestCase;
use Torgash\Checkout\Models\Order;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Sorting\Sorts;

/**
 * Popularity (PLAN §31.2): one number, two events, and both weights are the
 * shop's.
 *
 * What is pinned here is what the owner decided and why: points rather than
 * tick boxes (views outnumber orders by two orders of magnitude), an order
 * counts once whatever the quantity, `sales_count` keeps counting units
 * because the labels read it, and the number is editable without any of that
 * being falsified.
 */
class PopularityTest extends TestCase
{
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => ['en' => 'Wanted'], 'slug' => 'wanted', 'enabled' => true,
        ]);
    }

    /** A view is worth what the shop says it is, once per visitor per product. */
    public function test_a_view_awards_points_once_per_session(): void
    {
        $product = $this->product('POP-1', 'Looked at');

        $this->settings(['popularity_view_points' => 3]);

        $this->browse('http://localhost/product/pop-1');
        $this->assertSame(3, $product->fresh()->popularity);

        // Same session, same product: the guard that keeps views_count honest
        // keeps the points honest too, because it is the same guard.
        $this->browse('http://localhost/product/pop-1');
        $this->assertSame(3, $product->fresh()->popularity);
    }

    /**
     * A crawler keeps no cookies, so every visit it makes is a new session —
     * the once-per-session guard does nothing to it, and a sort the shop shows
     * its customers would end up ranked by robots.
     */
    public function test_a_bot_is_not_a_visitor(): void
    {
        $product = $this->product('POP-2', 'Crawled');

        $this->settings(['popularity_view_points' => 3]);

        $this->get('http://localhost/product/pop-2', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->assertOk();

        $this->assertSame(0, $product->fresh()->popularity);
        // ...while views_count deliberately keeps counting what it always
        // counted: it is an existing statistic, and moving its goalposts would
        // silently re-draw every label written against it.
        $this->assertSame(1, $product->fresh()->views_count);
    }

    /** 0 is how a shop switches an event off; both at 0 leaves the number entirely manual. */
    public function test_zero_points_switches_an_event_off(): void
    {
        $product = $this->product('POP-3', 'Ignored');

        $this->settings(['popularity_view_points' => 0]);

        $this->browse('http://localhost/product/pop-3');

        $this->assertSame(0, $product->fresh()->popularity);
        $this->assertSame(1, $product->fresh()->views_count);
    }

    /**
     * An order is worth its points ONCE, whatever the quantity — "how often is
     * it chosen", which is what a shopper's "most popular" means. `sales_count`
     * counts the units beside it, unchanged.
     */
    public function test_an_order_counts_once_however_many_were_bought(): void
    {
        $product = $this->product('POP-4', 'Bought in bulk');

        $this->settings(['popularity_order_points' => 10]);

        $order = Order::create([
            'customer_name' => 'Wholesaler',
            'email' => 'bulk@example.test',
            'subtotal' => 5000_00,
            'total' => 5000_00,
            'status' => 'new',
            'items' => [['product_id' => $product->id, 'qty' => 50, 'price' => 100_00]],
        ]);

        hook_action('checkout.order_placed', ['order' => $order]);

        $this->assertSame(10, $product->fresh()->popularity);
    }

    /** The point of the column: a shop can simply type a bigger number. */
    public function test_the_shop_can_type_over_it(): void
    {
        $product = $this->product('POP-5', 'Pushed up');

        $this->actingAs($this->admin());

        $this->put('http://localhost/admin/catalog/products/'.$product->id,
            $this->formPayload($product, ['popularity' => 500]))->assertRedirect();

        $this->assertSame(500, $product->fresh()->popularity);
    }

    /** ...and the listing is ordered by it. */
    public function test_the_storefront_sorts_by_it(): void
    {
        $this->product('POP-6', 'Wanted less')->update(['popularity' => 1]);
        $this->product('POP-7', 'Wanted more')->update(['popularity' => 99]);

        $this->get('http://localhost/catalog/wanted?sort=popular')->assertOk()
            ->assertSeeInOrder(['Wanted more', 'Wanted less']);
    }

    protected function browse(string $url): void
    {
        $this->get($url, ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0'])->assertOk();
    }

    /** @param array<string, mixed> $values */
    protected function settings(array $values): void
    {
        foreach ($values as $key => $value) {
            app(SettingsManager::class)->set('torgash.catalog', $key, $value);
        }

        app(Sorts::class)->flush();
    }

    /** @param array<string, mixed> $overrides */
    protected function formPayload(Product $product, array $overrides = []): array
    {
        return [
            'sku' => $product->sku,
            'name' => $product->t('name'),
            'price' => $product->price / 100,
            'stock' => $product->stock,
            'status' => $product->status,
        ] + $overrides;
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    protected function product(string $sku, string $name): Product
    {
        $product = Product::create([
            'sku' => $sku,
            'name' => ['en' => $name],
            'slug' => strtolower($sku),
            'price' => 100_00,
            'stock' => 5,
            'status' => 'active',
        ]);

        $product->categories()->attach($this->category->id);

        return $product;
    }
}
