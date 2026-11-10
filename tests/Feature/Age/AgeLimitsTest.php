<?php

namespace Tests\Feature\Age;

use App\Core\PageCache\PageCache;
use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;
use Torgash\Age\Visitor;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Media\Gallery;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\ZoneMatcher;

/**
 * Age limits (PLAN §71, torgash.age): an age on a category holds everything
 * under it, on a product only that product, on the whole shop everything. A
 * visitor who has not said they are old enough gets the photos covered by a
 * plate, or does not find the goods in lists at all; pressing "I am 18 or
 * older" shows them. An order of such goods needs the buyer's tick and tells
 * the manager to check a document — the only real check.
 */
class AgeLimitsTest extends TestCase
{
    protected const PLATE = 'http://localhost/age/plate/18.svg';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        app(SettingsManager::class)->set('torgash.catalog', 'external_images', true);
    }

    public function test_a_category_age_covers_the_photos_of_everything_under_it(): void
    {
        $tobacco = $this->category('age-tobacco', 18);
        $cigars = $this->category('age-cigars', null, null, $tobacco);
        $cigar = $this->product('AGE-CIGAR', 'Havana cigar', $cigars);
        $kettle = $this->product('AGE-KETTLE', 'Plain kettle', $this->category('age-kitchen'));

        $list = $this->get('http://localhost/catalog')->assertOk();
        $list->assertSee('Havana cigar')->assertSee(self::PLATE, false)
            ->assertDontSee($this->photo($cigar), false)
            ->assertSee($this->photo($kettle), false, 'other goods keep their photos');
        // A plate says 18+ itself; the strip belongs to the goods' own pages.
        $list->assertDontSee($this->strip(18));

        $page = $this->get('http://localhost/product/age-cigar')->assertOk();
        $page->assertSee(self::PLATE, false)->assertDontSee($this->photo($cigar), false);
        $this->assertStringContainsString('<meta property="og:image" content="'.self::PLATE, $page->getContent(), 'a messenger\'s preview shows the plate');
        $page->assertSee($this->strip(18));

        $this->get('http://localhost/catalog/age-tobacco')->assertOk()->assertSee($this->strip(18));

        // Another product's page asks nothing, even with the cigar's plate in
        // a row of other goods on it.
        $this->get('http://localhost/product/age-kettle')->assertOk()->assertDontSee($this->strip(18));
    }

    public function test_the_button_shows_the_goods_and_goes_back_to_the_page(): void
    {
        $cigar = $this->product('AGE-CIGAR', 'Havana cigar', $this->category('age-tobacco', 18));

        $this->post('http://localhost/age/confirm', ['age' => 18, 'back' => '/product/age-cigar?from=list'])
            ->assertRedirect('http://localhost/product/age-cigar?from=list')
            ->assertCookie(Visitor::COOKIE, '18', false);

        $this->withUnencryptedCookies([Visitor::COOKIE => '18'])->get('http://localhost/product/age-cigar')->assertOk()
            ->assertSee($this->photo($cigar), false)->assertDontSee(self::PLATE, false)->assertDontSee($this->strip(18));
    }

    public function test_the_way_back_is_only_ever_this_shops_own_page(): void
    {
        foreach (['https://elsewhere.test/', '//elsewhere.test/x', '/\\elsewhere.test', ''] as $back) {
            $this->post('http://localhost/age/confirm', ['age' => 18, 'back' => $back])->assertRedirect(url('/'));
        }

        // An older answer is never taken back by a younger question.
        $this->withUnencryptedCookies([Visitor::COOKIE => '21'])
            ->post('http://localhost/age/confirm', ['age' => 18, 'back' => '/'])->assertCookie(Visitor::COOKIE, '21', false);
    }

    public function test_hidden_goods_are_not_listed_or_found_but_their_page_opens(): void
    {
        $cigar = $this->product('AGE-CIGAR', 'Smoky cigar', $this->category('age-tobacco'));
        $this->limitProduct($cigar, 18, 'hide');
        $this->product('AGE-KETTLE', 'Plain kettle', $this->category('age-kitchen'));

        $this->get('http://localhost/catalog')->assertOk()->assertSee('Plain kettle')->assertDontSee('Smoky cigar');
        app(\Torgash\Search\Indexer::class)->all();
        $this->get('http://localhost/search/suggest?q=smoky')->assertOk()->assertDontSee('Smoky cigar');

        $this->get('http://localhost/product/age-cigar')->assertOk()->assertSee('Smoky cigar')
            ->assertSee(self::PLATE, false)->assertSee($this->strip(18));

        $this->withUnencryptedCookies([Visitor::COOKIE => '18'])->get('http://localhost/catalog')->assertOk()->assertSee('Smoky cigar');
    }

    public function test_a_hidden_category_asks_on_its_own_page(): void
    {
        $this->product('AGE-WINE', 'Red wine', $this->category('age-wine', 18, 'hide'));

        $this->get('http://localhost/catalog/age-wine')->assertOk()->assertDontSee('Red wine')->assertSee($this->strip(18));
        $this->withUnencryptedCookies([Visitor::COOKIE => '18'])->get('http://localhost/catalog/age-wine')->assertOk()
            ->assertSee('Red wine')->assertDontSee($this->strip(18));
    }

    /** Each rule is its own: a younger rule that no longer applies does not soften an older one. */
    public function test_an_older_rule_still_covers_after_a_younger_age_is_confirmed(): void
    {
        $vape = $this->product('AGE-VAPE', 'Strong vape', $this->category('age-vapes', 16, 'hide'));
        $this->limitProduct($vape, 21, 'cover');

        $this->get('http://localhost/catalog')->assertOk()->assertDontSee('Strong vape');

        $this->withUnencryptedCookies([Visitor::COOKIE => '18']);
        $this->get('http://localhost/catalog')->assertOk()->assertSee('Strong vape')
            ->assertSee('http://localhost/age/plate/21.svg', false)->assertDontSee($this->photo($vape), false);
        $this->get('http://localhost/product/age-vape')->assertOk()->assertSee($this->strip(21));

        $this->withUnencryptedCookies([Visitor::COOKIE => '21']);
        $this->get('http://localhost/catalog')->assertOk()->assertSee($this->photo($vape), false);
    }

    public function test_the_whole_shop_and_the_window(): void
    {
        $kettle = $this->product('AGE-KETTLE', 'Plain kettle', $this->category('age-kitchen'));
        $wine = $this->product('AGE-WINE', 'Red wine', $this->category('age-wine', 18));
        $settings = app(SettingsManager::class);
        $window = __('Are you :age or older?', ['age' => 18]);

        // On the pages of goods with an age only, and then instead of the strip.
        $settings->set('torgash.age', 'window', 'restricted');
        $this->get('http://localhost/product/age-wine')->assertOk()->assertSee($window)->assertDontSee($this->strip(18));
        $this->get('http://localhost/product/age-kettle')->assertOk()->assertDontSee($window);
        $this->get('http://localhost/')->assertOk()->assertDontSee($window);

        $settings->set('torgash.age', 'window', 'everywhere');
        $this->get('http://localhost/')->assertOk()->assertSee($window);
        $this->withUnencryptedCookies([Visitor::COOKIE => '18'])->get('http://localhost/')->assertOk()->assertDontSee($window);
        $this->unencryptedCookies = [];

        // The whole shop: every photo is a plate.
        $settings->set('torgash.age', 'window', 'off');
        $settings->set('torgash.age', 'site_age', 18);
        $this->get('http://localhost/catalog')->assertOk()
            ->assertDontSee($this->photo($kettle), false)->assertDontSee($this->photo($wine), false)
            ->assertSee(self::PLATE, false);
    }

    public function test_staff_see_the_shop_as_it_is(): void
    {
        $cigar = $this->product('AGE-CIGAR', 'Smoky cigar', $this->category('age-tobacco', 18, 'hide'));

        $this->actingAs($this->admin());
        $this->get('http://localhost/catalog')->assertOk()->assertSee('Smoky cigar')->assertSee($this->photo($cigar), false);
        $this->get('http://localhost/product/age-cigar')->assertOk()->assertSee($this->photo($cigar), false)->assertDontSee($this->strip(18));
    }

    public function test_the_category_form_sets_and_clears_an_age_and_the_product_form_shows_it(): void
    {
        $category = $this->category('age-form');
        $product = $this->product('AGE-FORM', 'Form product', $category);
        $this->actingAs($this->admin());

        $this->get('http://localhost/admin/catalog/categories/'.$category->id.'/edit')->assertOk()->assertSee('name="age_limit"', false);

        $save = fn (array $age) => $this->put('http://localhost/admin/catalog/categories/'.$category->id, [
            'name' => 'Form category', 'slug' => $category->slug, 'status' => 'active',
        ] + $age)->assertRedirect()->assertSessionHasNoErrors();

        $save(['age_form' => 1, 'age_limit' => '18', 'age_display' => 'hide']);
        $this->assertSame(['age' => 18, 'display' => 'hide'], (array) DB::table('m_age_category_limits')->where('category_id', $category->id)->first(['age', 'display']));

        // A form that never showed the field leaves the age alone.
        $save([]);
        $this->assertSame(1, DB::table('m_age_category_limits')->where('category_id', $category->id)->count());

        // The product's form tells what it gets from its category.
        $this->get('http://localhost/admin/catalog/products/'.$product->id.'/edit')->assertOk()
            ->assertSee(__('Already :age+ from the categories above or the whole shop; a rule here can only add to that.', ['age' => 18]));

        $save(['age_form' => 1, 'age_limit' => '']);
        $this->assertSame(0, DB::table('m_age_category_limits')->where('category_id', $category->id)->count());

        $this->put('http://localhost/admin/catalog/products/'.$product->id, [
            'sku' => $product->sku, 'name' => 'Form product', 'price' => '10', 'stock' => '5', 'status' => 'active',
            'age_form' => 1, 'age_limit' => '21', 'age_display' => '',
        ])->assertRedirect();
        $this->assertSame(['age' => 21, 'display' => null], (array) DB::table('m_age_product_limits')->where('product_id', $product->id)->first(['age', 'display']));

        $this->get('http://localhost/admin/age')->assertOk()->assertSee('Form product')->assertSee('21+');
    }

    public function test_an_order_of_such_goods_needs_the_tick_and_tells_the_manager_to_check_an_id(): void
    {
        [$shipping, $payment] = $this->methods();
        $this->product('AGE-WINE', 'Red wine', $this->category('age-wine', 18));

        $this->post('http://localhost/cart/add/age-wine', ['qty' => 1]);
        $this->get('http://localhost/checkout')->assertOk()->assertSee('name="age_confirmed"', false)->assertSee(__('I am :age or older', ['age' => 18]));

        $order = [
            'customer_name' => 'Buyer', 'email' => 'age-buyer@test', 'phone' => '+380675550128',
            'address_line' => 'Street 1', 'city' => 'Town', 'postal_code' => '01001', 'country' => 'UA',
            'shipping_method_id' => (string) $shipping->id, 'payment_method_id' => $payment->id,
        ];

        $this->post('http://localhost/checkout', $order)->assertSessionHasErrors('age_confirmed');
        $this->assertSame(0, Order::count(), 'no tick, no order');

        $this->post('http://localhost/checkout', $order + ['age_confirmed' => '1'])->assertRedirect();
        $placed = Order::latest('id')->firstOrFail();
        $note = DB::table('m_age_orders')->where('order_id', $placed->id)->first();
        $this->assertSame(18, (int) $note->age);
        $this->assertNotNull($note->confirmed_at);

        $this->actingAs($this->admin())->get('http://localhost/admin/orders/'.$placed->order_number)->assertOk()
            ->assertSee(__(':age+: check an ID', ['age' => 18]));
    }

    public function test_one_click_asks_for_the_tick_too_and_other_goods_are_not_asked(): void
    {
        [$shipping, $payment] = $this->methods();
        $settings = app(SettingsManager::class);
        $settings->set('torgash.checkout', 'one_click', true);
        $settings->set('torgash.checkout', 'one_click_shipping_id', (string) $shipping->id);
        $settings->set('torgash.checkout', 'one_click_payment_id', $payment->id);
        RateLimiter::clear('form:one_click:ip:127.0.0.1');

        $wine = $this->product('AGE-WINE', 'Red wine', $this->category('age-wine', 18));
        $kettle = $this->product('AGE-KETTLE', 'Plain kettle', $this->category('age-kitchen'));

        $this->get('http://localhost/product/age-wine')->assertOk()->assertSee('name="age_confirmed"', false);
        $this->get('http://localhost/product/age-kettle')->assertOk()->assertDontSee('name="age_confirmed"', false);

        $payload = fn (Product $product, array $extra = []) => [
            'product_id' => $product->id, 'customer_name' => 'Buyer', 'phone' => '+380 (67) 555-01-28',
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)), FormGuard::TRAP => '',
        ] + $extra;

        $this->post('http://localhost/one-click', $payload($wine))->assertSessionHasErrors('age_confirmed');
        $this->assertSame(0, Order::count());

        $this->post('http://localhost/one-click', $payload($wine, ['age_confirmed' => '1']))->assertRedirect();
        $this->assertSame(1, DB::table('m_age_orders')->count());

        $this->post('http://localhost/one-click', $payload($kettle))->assertRedirect();
        $this->assertSame(2, Order::count());
        $this->assertSame(1, DB::table('m_age_orders')->count(), 'a kettle carries no note');
    }

    public function test_the_sitemap_keeps_the_page_but_not_a_covered_photo(): void
    {
        $cigar = $this->product('AGE-CIGAR', 'Havana cigar', $this->category('age-tobacco', 18));
        $kettle = $this->product('AGE-KETTLE', 'Plain kettle', $this->category('age-kitchen'));
        $this->attachPhoto($cigar);
        $this->attachPhoto($kettle);

        $sitemap = $this->get('http://localhost/sitemap.xml')->assertOk();
        $sitemap->assertSee(route('catalog.product', 'age-cigar'), false)
            ->assertDontSee(Gallery::thumbUrl($cigar->fresh()->getFirstMedia('gallery'), 1200), false)
            ->assertSee(Gallery::thumbUrl($kettle->fresh()->getFirstMedia('gallery'), 1200), false);
    }

    /** A kept page is kept per answer: the copy with photos never reaches a visitor who has not pressed. */
    public function test_the_page_cache_keeps_a_copy_for_each_answer(): void
    {
        $cigar = $this->product('AGE-CIGAR', 'Havana cigar', $this->category('age-tobacco', 18));
        app(SettingsManager::class)->set('core', PageCache::ENABLED, true);
        $url = 'http://localhost/product/age-cigar';

        $this->assertSame('stored', $this->cacheState($this->get($url)->assertSee(self::PLATE, false)));
        $this->flushSession();
        $this->assertSame('hit', $this->cacheState($this->get($url)->assertSee(self::PLATE, false)));

        $this->flushSession();
        $this->withUnencryptedCookies([Visitor::COOKIE => '18']);
        $confirmed = $this->get($url)->assertSee($this->photo($cigar), false)->assertDontSee(self::PLATE, false);
        $this->assertSame('stored', $this->cacheState($confirmed), 'its own copy, not the plated one');
    }

    public function test_the_plate_is_a_picture_for_everybody(): void
    {
        $plate = $this->get('http://localhost/age/plate/18.svg')->assertOk();

        $this->assertStringStartsWith('image/svg+xml', (string) $plate->headers->get('Content-Type'));
        $this->assertStringContainsString('18+', $plate->getContent());
        $this->assertStringNotContainsString('<script', $plate->getContent());
    }

    protected function category(string $slug, ?int $age = null, ?string $display = null, ?Category $parent = null): Category
    {
        $category = Category::create(['name' => ['en' => $slug], 'slug' => $slug, 'status' => 'active', 'parent_id' => $parent?->id]);

        if ($age !== null) {
            DB::table('m_age_category_limits')->insert(['category_id' => $category->id, 'age' => $age, 'display' => $display, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $category;
    }

    protected function product(string $sku, string $name, Category $category): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku), 'price' => 20_00, 'stock' => 5,
            'status' => 'active', 'image_url' => $this->photoFor($sku),
        ]);
        $product->categories()->attach($category->id);

        return $product;
    }

    protected function limitProduct(Product $product, int $age, ?string $display): void
    {
        DB::table('m_age_product_limits')->insert(['product_id' => $product->id, 'age' => $age, 'display' => $display, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function photo(Product $product): string
    {
        return $this->photoFor($product->sku);
    }

    protected function photoFor(string $sku): string
    {
        return 'https://img.test/'.strtolower($sku).'.jpg';
    }

    protected function attachPhoto(Product $product): void
    {
        $path = tempnam(sys_get_temp_dir(), 'age').'.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $product->addMedia($path)->toMediaCollection('gallery');
    }

    protected function strip(int $age): string
    {
        return __('Some goods here are sold only to people of :age or older, so they are not shown in full.', ['age' => $age]);
    }

    /** @return array{0: ShippingMethod, 1: PaymentMethod} */
    protected function methods(): array
    {
        $shipping = ShippingMethod::create(['name' => ['en' => 'Courier'], 'price_mode' => 'quote', 'position' => 1, 'enabled' => true]);
        $shipping->rates()->create(['zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 0, 'enabled' => true]);
        $payment = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => 1]);

        return [$shipping, $payment];
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    protected function cacheState(\Illuminate\Testing\TestResponse $response): ?string
    {
        $state = $response->headers->get('X-Torgash-Cache');

        return $state === null ? null : strtok($state, ';');
    }
}
