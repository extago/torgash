<?php

namespace Tests\Feature\Core;

use App\Core\Mail\Letters;
use App\Core\Settings\SettingsManager;
use App\Core\Themes\ThemeSettings;
use App\Models\Storefront;
use Tests\TestCase;
use Torgash\Blocks\Models\Block;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Mail\CheckoutLetters;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;

/**
 * Two storefronts on one engine — a second domain with its own theme,
 * language, contacts and settings, the catalogue shared. What has to differ
 * between them, asked through the addresses a visitor and a crawler use, and
 * through a letter written where there is no request at all.
 */
class MultiStorefrontTest extends TestCase
{
    protected Storefront $second;

    protected function setUp(): void
    {
        parent::setUp();

        $main = Storefront::where('is_default', true)->firstOrFail();

        $this->second = Storefront::create([
            'code' => 'second', 'name' => 'Second Shop', 'domains' => ['second.test', 'www.second.test'],
            'theme' => 'crystal', 'default_locale' => 'ru', 'locales' => ['ru'],
            'default_currency' => $main->default_currency, 'currencies' => [$main->default_currency],
            'contacts' => ['phone' => '+380 99 222 22 22', 'email' => 'second@example.com'],
            'is_default' => false, 'enabled' => true,
        ]);
    }

    public function test_each_domain_is_served_by_its_storefront(): void
    {
        $this->get('http://second.test/')->assertOk()->assertSee('<title>Second Shop', false);
        $this->get('http://www.second.test/')->assertOk()->assertSee('<title>Second Shop', false);
        $this->get('http://localhost/')->assertOk()->assertDontSee('Second Shop', false);
    }

    public function test_a_switched_off_storefront_gives_its_domain_back_to_the_default(): void
    {
        $this->second->update(['enabled' => false]);

        $this->get('http://second.test/')->assertOk()->assertDontSee('<title>Second Shop', false);
    }

    public function test_each_storefront_speaks_its_own_language_and_refuses_one_it_does_not_offer(): void
    {
        $this->get('http://second.test/')->assertSee('<html lang="ru"', false);
        $this->get('http://localhost/')->assertSee('<html lang="en"', false);
        $this->get('http://second.test/en')->assertNotFound();
    }

    public function test_canonical_and_alternates_name_the_domain_the_page_is_on(): void
    {
        $page = $this->get('http://second.test/')->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="http://second.test/">', $page);
        $this->assertStringNotContainsString('localhost', $this->linksOf($page, 'alternate'));
    }

    public function test_robots_and_the_sitemap_are_the_domains_own(): void
    {
        Product::create(['sku' => 'MS-1', 'name' => ['en' => 'Shared lamp', 'ru' => 'Общая лампа'], 'slug' => 'shared-lamp', 'price' => 10_00, 'stock' => 5, 'status' => 'active']);

        $robots = $this->get('http://second.test/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Sitemap: http://second.test/', $robots);
        $this->assertStringNotContainsString('localhost', $robots);

        $sitemap = $this->get('http://second.test/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('http://second.test/', $sitemap);
        $this->assertStringNotContainsString('http://localhost/', $sitemap);
    }

    public function test_a_setting_overridden_for_one_storefront_stays_on_it(): void
    {
        $settings = app(SettingsManager::class);
        $settings->set(app(ThemeSettings::class)->moduleId('crystal'), 'custom_css', '.only-second{color:red}', $this->second->id);

        $this->get('http://second.test/')->assertSee('.only-second', false);
        $this->get('http://localhost/')->assertDontSee('.only-second', false);
    }

    public function test_a_block_shown_on_one_storefront_is_not_drawn_on_the_other(): void
    {
        $block = Block::create([
            'zone' => 'storefront.home.content', 'type' => 'html', 'title' => 'Second only', 'enabled' => true, 'sort' => 1,
            'settings' => ['html' => '<p>SECOND-ONLY-BLOCK</p>'], 'rules_match' => 'all',
            'rules' => [['kind' => 'storefront', 'operator' => 'in', 'value' => [(string) $this->second->id]]],
        ]);
        // Zones subscribe to their blocks at boot, so the zone is drawn here
        // directly — as each storefront's page would draw it.
        $renderer = app(\Torgash\Blocks\ZoneRenderer::class);

        app()->instance('torgash.storefront', $this->second);
        $this->assertStringContainsString('SECOND-ONLY-BLOCK', $renderer->render([$block]));

        app()->instance('torgash.storefront', Storefront::where('is_default', true)->firstOrFail());
        $this->assertStringNotContainsString('SECOND-ONLY-BLOCK', $renderer->render([$block]));
    }

    public function test_contacts_are_the_storefronts_own(): void
    {
        $this->get('http://second.test/')->assertSee('+380 99 222 22 22', false);
        $this->get('http://localhost/')->assertDontSee('+380 99 222 22 22', false);
    }

    public function test_the_admin_opens_on_either_domain(): void
    {
        $this->get('http://second.test/admin/login')->assertOk();
        $this->actingAs(\App\Models\User::role('admin')->firstOrFail())
            ->get('http://second.test/admin')->assertOk();
    }

    public function test_closing_one_storefront_leaves_the_other_open(): void
    {
        $this->second->update(['closed' => true, 'closed_token' => 'x']);

        $this->get('http://second.test/')->assertStatus(503);
        $this->get('http://localhost/')->assertOk();
    }

    /**
     * A queued letter has no request: whatever it links to must still be on
     * the domain of the storefront the order was placed on, not APP_URL.
     */
    public function test_a_letter_about_an_order_links_to_the_storefront_it_was_placed_on(): void
    {
        config(['app.url' => 'http://main.example']);
        \Illuminate\Support\Facades\URL::forceRootUrl('http://main.example');

        $order = Order::create([
            'order_number' => 'MS-'.uniqid(), 'customer_name' => 'Olena', 'email' => 'olena@example.com',
            'items' => [['product_id' => null, 'name' => 'Lamp', 'qty' => 1, 'price' => 5_00, 'unit_price' => 5_00, 'line_total' => 5_00]],
            'subtotal' => 5_00, 'total' => 5_00, 'status' => OrderStatus::query()->value('code'),
            'currency_code' => shop_currency(), 'storefront_id' => $this->second->id,
        ]);

        $html = Letters::compose(CheckoutLetters::PLACED, ['order' => $order], 'ru')['html'];

        $this->assertStringContainsString('second.test', $html, 'the letter links to its own storefront');
        $this->assertStringNotContainsString('href="http://main.example', $html, 'and not to the main one');

        \Illuminate\Support\Facades\URL::forceRootUrl(null);
    }

    protected function linksOf(string $html, string $rel): string
    {
        preg_match_all('/<link rel="'.$rel.'"[^>]*>/', $html, $m);

        return implode("\n", $m[0]);
    }
}
