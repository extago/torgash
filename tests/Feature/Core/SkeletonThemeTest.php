<?php

namespace Tests\Feature\Core;

use App\Models\Storefront;
use App\Models\User;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Tests\TestCase;

/**
 * The skeleton theme stands on its own (no parent): its one layout has to
 * carry every page a module draws, and nothing of `default` may leak in.
 * If a page here breaks, a theme author copying the skeleton is missing
 * a line the engine relies on — the layout is the documentation.
 */
class SkeletonThemeTest extends TestCase
{
    public function test_every_storefront_page_draws_in_the_skeleton(): void
    {
        $category = Category::create(['name' => ['en' => 'Skeleton lamps'], 'slug' => 'skeleton-lamps', 'enabled' => true]);
        $product = Product::create(['sku' => 'SKEL-1', 'name' => ['en' => 'Skeleton lamp'], 'slug' => 'skeleton-lamp', 'price' => 100_00, 'stock' => 5, 'status' => 'active']);
        $product->categories()->attach($category->id);

        Storefront::query()->firstOrFail()->update(['theme' => 'skeleton']);

        foreach (['/', '/catalog', '/catalog/skeleton-lamps', '/product/skeleton-lamp', '/cart'] as $path) {
            $html = $this->get('http://localhost'.$path)->assertOk()->getContent();

            $this->assertStringContainsString('torgash-storefront', $html, $path);
            $this->assertStringContainsString('htmx.min.js', $html, $path);
            $this->assertStringNotContainsString('torgash-tabbar', $html, $path.': nothing of the default theme');
        }

        $this->get('http://localhost/product/skeleton-lamp')->assertSee('Skeleton lamp');
    }

    public function test_a_theme_without_settings_offers_no_configurator(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $storefront = Storefront::query()->firstOrFail();
        $storefront->update(['theme' => 'skeleton']);

        $this->actingAs($admin)->get(route('admin.themes.index'))->assertOk()
            ->assertDontSee(route('admin.themes.customize', $storefront), false);
    }
}
