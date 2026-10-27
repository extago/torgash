<?php

namespace Tests\Feature\Core;

use App\Core\Demo\DemoData;
use App\Models\BackgroundTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Blocks\Models\Block;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Coupon;
use Torgash\Kits\Models\Kit;
use Torgash\Kits\Models\KitSlot;
use Torgash\Options\Models\OptionGroup;
use Torgash\Promotions\Models\Promotion;
use Torgash\Reviews\Models\Criterion;
use Torgash\Reviews\Models\Review;
use Torgash\Warehouses\Models\Warehouse;

/**
 * The demo shop (PLAN §55): installed from database/demo, every record it
 * made written down, and removed again without touching what the owner
 * added in between.
 */
class DemoDataTest extends TestCase
{
    public function test_the_demo_installs_shows_and_removes_only_itself(): void
    {
        Storage::fake('public');
        $demo = app(DemoData::class);

        $this->assertFalse($demo->installed());
        $this->assertArrayHasKey('catalog', $demo->parts());

        $demo->install();

        $this->assertTrue($demo->installed());
        $phone = Product::where('slug', 'iphone-16-pro-256gb-black-titanium')->firstOrFail();
        $this->assertSame('iphone-16-pro', $phone->variation_group);
        $this->assertContains('Demo', $phone->labels->map(fn (Label $label) => $label->t('name', 'en'))->all());
        $this->assertSame('apple', $phone->brandValue()?->slug);

        // The one kept from the dev shop, with its pictures and its text's.
        $core = Product::where('sku', 'EF-CORE')->firstOrFail();
        $this->assertSame(8, $core->getMedia('gallery')->count());
        $this->assertStringNotContainsString('data-demo', $core->description['en']);
        $this->assertSame(4, substr_count($core->description['en'], '<img'));

        // The storefront answers with it: a category, a product, a brand's filtered page.
        $this->get('http://localhost/catalog/smartphones')->assertOk()->assertSee('iPhone 16 Pro');
        $this->get('http://localhost/product/iphone-16-pro-256gb-black-titanium')->assertOk()->assertSee('Demo');
        $this->get('http://localhost/brand/apple')->assertOk()->assertSee('MacBook Air');

        // The parts hung off the catalogue: a laptop's own questions and the
        // shared ones its category reaches, a phone's kit with its discount.
        $this->get('http://localhost/product/lenovo-thinkpad-x1-carbon-gen-13')->assertOk()
            ->assertSee('Memory and storage')->assertSee('Extended warranty')->assertDontSee('Gift wrapping');
        $this->get('http://localhost/product/iphone-16-pro-256gb-black-titanium')->assertOk()
            ->assertSee('Everything for your iPhone')->assertSee('Gift wrapping');
        $this->assertSame(5, OptionGroup::where('code', 'like', 'demo-%')->count());
        $this->assertSame(5, Kit::where('code', 'like', 'demo-%')->count());
        $this->assertSame('none', KitSlot::whereHas('kit', fn ($kit) => $kit->where('code', 'demo-gopro-set'))->value('discount_type'), 'a simple kit gives nothing away');

        // The sales: the deal of the day counts to tonight's midnight, whatever day the demo went in.
        $deal = Promotion::where('slug', 'demo-deal-of-the-day')->firstOrFail();
        $this->assertTrue($deal->countdownEndsAt()?->equalTo(now()->addDay()->startOfDay()));
        $this->get('http://localhost/sale/demo-deal-of-the-day')->assertOk()->assertSee('GoPro HERO13');
        $gopro = Product::where('sku', 'HERO13')->firstOrFail();
        $this->assertSame((int) round($gopro->price * 0.85), (int) $gopro->effectivePrice());
        $this->assertNotNull(Coupon::where('code', 'DEMO10')->first());

        // Stock spread over the default warehouse and two pickup shops, the total unchanged.
        $mall = Warehouse::where('name->en', 'Demo shop in the mall')->firstOrFail();
        $this->assertTrue($mall->is_pickup);
        $this->assertSame((int) $phone->fresh()->stock, (int) DB::table('m_warehouse_product_stock')->where('product_id', $phone->id)->sum('qty'));
        $this->assertGreaterThan(0, $mall->stockedCount());

        // Pages, linked from the footer's columns the theme left empty.
        $this->get('http://localhost/contacts')->assertOk()->assertSee('The contacts come from the storefront');
        $about = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('href="http://localhost/about"', $about);
        $this->assertStringContainsString('href="http://localhost/privacy"', $about);

        // The blog: an article's card in the text, its rubric in the feed.
        $this->get('http://localhost/blog/demo-noise-cancelling-headphones')->assertOk()
            ->assertSee('torgash-rich-embed', false)->assertSee('Sony WH-1000XM6');
        $this->get('http://localhost/blog')->assertOk()->assertSee('Buying guides')->assertSee('Home Wi-Fi without dead zones');
        $this->assertTrue(Block::where('zone', 'storefront.home.bottom')->where('type', 'blog_posts')->exists());

        // The home page's slider and cards, placed by blocks.
        $this->app->register(new \Torgash\Blocks\BlocksServiceProvider($this->app), true); // zones subscribe at boot
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('Smartphone week', $home);
        $this->assertStringContainsString('x-data="torgashSlider(', $home);
        $this->assertStringContainsString('DEMO10', $home);

        // Reviews: published, averaged, scored by the categories' criteria, one answered by the shop.
        $phone = $phone->fresh();
        $this->assertSame(3, Review::reviews()->published()->where('product_id', $phone->id)->count());
        $this->assertNotNull(DB::table('m_reviews_aggregates')->where('product_id', $phone->id)->value('rating'));
        $this->assertTrue(Review::where('product_id', $phone->id)->where('is_staff', true)->exists());
        $this->get('http://localhost/product/iphone-16-pro-256gb-black-titanium')->assertOk()->assertSee('Alex M.')->assertSee('Camera');

        // The owner's own product, made while the demo is there, survives its removal.
        $own = Product::create(['sku' => 'OWN-1', 'name' => ['en' => 'My own'], 'slug' => 'my-own', 'price' => 100, 'stock' => 1, 'status' => 'active']);
        $own->categories()->attach(Category::where('slug', 'smartphones')->value('id'));

        $result = $demo->remove();

        $this->assertSame(0, $result['failed']);
        $this->assertFalse($demo->installed());
        $this->assertSame(0, DB::table('demo_records')->count());
        $this->assertNull(Product::withTrashed()->where('sku', 'EF-CORE')->first());
        $this->assertNull(Feature::where('code', 'brand')->first());
        $this->assertSame(0, OptionGroup::where('code', 'like', 'demo-%')->count());
        $this->assertSame(0, Kit::where('code', 'like', 'demo-%')->count() + KitSlot::count());
        $this->assertSame(0, Promotion::where('slug', 'like', 'demo-%')->count() + Coupon::where('code', 'DEMO10')->count());
        $this->assertSame(0, Review::where('author_name', 'Alex M.')->count() + Criterion::where('code', 'like', 'demo-%')->count());
        $this->assertNull(Warehouse::where('name->en', 'Demo shop in the mall')->first());
        $this->assertFalse(Block::where('type', 'blog_posts')->where('title', 'From the blog')->exists());
        $this->assertSame(0, \Torgash\Banners\Models\Banner::where('name', 'like', 'Demo:%')->count() + Block::where('type', 'banners')->where('title', 'like', 'Demo:%')->count());
        $this->assertSame(0, DB::table('m_reviews_aggregates')->where('product_id', $phone->id)->count(), 'the average goes with the product');
        $this->assertNotNull(Product::find($own->id), 'the owner\'s product stays');
    }

    /** The dev shop got the demo's slider on top of its own (2026-10-27). */
    public function test_a_home_page_with_the_shops_own_banners_gets_no_demo_slider(): void
    {
        $own = \Torgash\Banners\Models\Slider::query()->create(['name' => 'Own slider', 'frame_id' => \Torgash\Banners\Models\Template::query()->where('key', 'slider')->value('id'), 'settings' => [], 'values' => []]);
        Block::create(['zone' => 'storefront.home.top', 'type' => 'banners', 'title' => 'Own slider', 'enabled' => true, 'sort' => 0, 'settings' => ['slider' => (string) $own->id], 'rules_match' => 'all']);

        app(DemoData::class)->install(only: ['banners']);

        $this->assertFalse(Block::where('type', 'banners')->where('title', 'like', 'Demo:%')->exists());
        $this->assertSame(0, \Torgash\Banners\Models\Slider::where('name', 'like', 'Demo:%')->count());
    }

    public function test_the_system_page_reminds_of_the_demo_and_removes_it_in_the_background(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $demo = app(DemoData::class);

        $this->actingAs($admin)->get('http://localhost/admin/system')->assertOk()
            ->assertDontSee(route('admin.system.demo.remove'));

        $product = $demo->record(fn () => Product::create(['sku' => 'DEMO-1', 'name' => ['en' => 'Demo thing'], 'slug' => 'demo-thing', 'price' => 100, 'stock' => 1, 'status' => 'active']));
        $own = Product::create(['sku' => 'OWN-1', 'name' => ['en' => 'My own'], 'slug' => 'my-own', 'price' => 100, 'stock' => 1, 'status' => 'active']);

        $this->actingAs($admin)->get('http://localhost/admin/system')->assertOk()
            ->assertSee(route('admin.system.demo.remove'))
            ->assertSee(__('Remove the demo data'));

        // The queue is sync under tests: the task has run by the time the redirect comes back.
        $this->actingAs($admin)->post('http://localhost/admin/system/demo/remove')->assertRedirect();

        $this->assertFalse($demo->installed());
        $this->assertNull(Product::withTrashed()->find($product->id));
        $this->assertNotNull(Product::find($own->id), 'the owner\'s product stays');
        $task = BackgroundTask::query()->where('name', 'Remove the demo data')->latest('id')->firstOrFail();
        $this->assertSame(BackgroundTask::DONE, $task->status);
    }
}
