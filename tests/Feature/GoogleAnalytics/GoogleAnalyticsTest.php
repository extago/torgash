<?php

namespace Tests\Feature\GoogleAnalytics;

use App\Core\Access\Impersonation;
use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\GoogleAnalytics\Tag;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * Google Analytics 4 (PLAN §73): the tag by a measurement ID or a Tag
 * Manager container, waiting for consent — or loading at once under Consent
 * Mode, but only where Google is told the visitor's choice; a product page's
 * view_item in GA4's words; the checkout page saying a checkout began; the
 * visitor's client ID on the order once the shop ticks it.
 */
class GoogleAnalyticsTest extends TestCase
{
    protected SettingsManager $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->settings = app(SettingsManager::class);
        $this->settings->set('torgash.google-analytics', 'tag_id', 'G-TEST12345');
        // A shop that does not ask: the tag is printed as it is.
        $this->settings->set('torgash.privacy', 'consent_mode', 'off');
    }

    public function test_without_an_id_nothing_is_printed(): void
    {
        $this->settings->set('torgash.google-analytics', 'tag_id', '');

        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringNotContainsString('googletagmanager.com', $html);
        $this->assertStringNotContainsString('google-analytics.js', $html);
    }

    public function test_the_tag_starts_by_its_measurement_id_and_hears_the_page(): void
    {
        $this->settings->set('torgash.google-analytics', 'tag_id', ' g-test12345 ');

        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~<script nonce="[0-9a-f]+" async src="https://www\.googletagmanager\.com/gtag/js\?id=G-TEST12345"></script>~', $html);
        $this->assertMatchesRegularExpression("~<script nonce=\"[0-9a-f]+\">\s*window\.dataLayer = window\.dataLayer \|\| \[\];~", $html, 'its start is signed too');
        $this->assertStringContainsString("gtag('config', 'G-TEST12345');", $html);
        $this->assertMatchesRegularExpression(
            '~<script nonce="[0-9a-f]+" src="[^"]*/modules/torgash\.google-analytics/assets/google-analytics\.js[^"]*" data-google-tag="G-TEST12345" data-manager="0" data-ecommerce="1" data-item-id="sku" data-leads="callback,product_question" defer></script>~',
            $html,
        );
    }

    public function test_a_tag_manager_container_is_loaded_instead(): void
    {
        $this->settings->set('torgash.google-analytics', 'tag_id', 'GTM-ABC1234');

        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringContainsString("j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;", $html);
        $this->assertStringContainsString("})(window,document,'script','dataLayer','GTM-ABC1234');", $html);
        $this->assertStringNotContainsString('gtag/js', $html);
        $this->assertStringContainsString('data-google-tag="GTM-ABC1234" data-manager="1"', $html);
    }

    /**
     * Where the shop asks: waiting is the default; Consent Mode loads the tag
     * at once, after the defaults that keep it from storing anything — and
     * only while those defaults are printed, or it would store on everyone.
     */
    public function test_it_waits_for_consent_unless_consent_mode_can_hold_it(): void
    {
        $this->settings->set('torgash.privacy', 'consent_mode', 'opt_in');
        $this->settings->set('torgash.privacy', 'google_consent_mode', true);

        $html = $this->plain($this->get('http://localhost/')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('~<template data-consent="statistics">\s*<script async src="https://www\.googletagmanager\.com/gtag/js\?id=G-TEST12345"></script>~', $html);

        $this->settings->set('torgash.google-analytics', 'consent', 'consent_mode');
        $html = $this->plain($this->get('http://localhost/')->assertOk()->getContent());
        $this->assertStringNotContainsString('<template data-consent="statistics">', $html);
        $defaults = strpos($html, "gtag('consent','default'");
        $tag = strpos($html, 'googletagmanager.com/gtag/js');
        $this->assertNotFalse($defaults);
        $this->assertNotFalse($tag);
        $this->assertLessThan($tag, $defaults, 'Consent Mode is set before the tag');

        $this->settings->set('torgash.privacy', 'google_consent_mode', false);
        $html = $this->plain($this->get('http://localhost/')->assertOk()->getContent());
        $this->assertStringNotContainsString("gtag('consent','default'", $html);
        $this->assertStringContainsString('<template data-consent="statistics">', $html, 'nobody tells Google: it waits');
    }

    /**
     * Not waiting is the shop's decision (2026-11-15): the tag loads for
     * everyone — and the consent signals stay the visitor's own, so where
     * Consent Mode is on Google still holds its cookies until they agree.
     */
    public function test_the_shop_may_load_it_for_everyone(): void
    {
        $this->settings->set('torgash.privacy', 'consent_mode', 'opt_in');
        $this->settings->set('torgash.privacy', 'google_consent_mode', false);
        $this->settings->set('torgash.google-analytics', 'consent', 'none');

        $html = $this->plain($this->get('http://localhost/')->assertOk()->getContent());
        $this->assertStringNotContainsString('<template data-consent="statistics">', $html);
        $this->assertStringContainsString('<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST12345"></script>', $html);
        $this->assertStringNotContainsString("gtag('consent','default'", $html);

        $this->settings->set('torgash.privacy', 'google_consent_mode', true);
        $html = $this->plain($this->get('http://localhost/')->assertOk()->getContent());
        $this->assertStringNotContainsString('<template data-consent="statistics">', $html);
        $this->assertLessThan(strpos($html, 'googletagmanager.com/gtag/js'), strpos($html, "gtag('consent','default'"));
        $this->assertStringContainsString('"analytics_storage":"denied"', $html, 'nobody agreed yet, and nothing says they did');
    }

    /** The product a page shows, in GA4's words — its code or its number, as the shop chose. */
    public function test_a_product_page_says_which_product(): void
    {
        $product = $this->product();

        $html = $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent();
        $this->assertStringContainsString(
            "gtag('event', 'view_item', {\"currency\":\"".shop_currency().'","value":150,"items":[{"item_id":"GA-1","item_name":"Наковальня","item_brand":"Acme","item_category":"Tools","item_category2":"Anvils","price":150,"quantity":1}]});',
            $html,
        );

        $this->settings->set('torgash.google-analytics', 'item_id', 'id');
        $this->assertStringContainsString('"item_id":"'.$product->id.'"', $this->get('http://localhost/product/'.$product->slug)->getContent());

        $this->settings->set('torgash.google-analytics', 'tag_id', 'GTM-ABC1234');
        $this->assertMatchesRegularExpression(
            '~window\.dataLayer\.push\(\{ ecommerce: null \}\);\s*window\.dataLayer\.push\(\{"event":"view_item","ecommerce":\{"currency"~',
            $this->get('http://localhost/product/'.$product->slug)->getContent(),
        );
    }

    /**
     * The checkout page says a checkout began, with the basket — and not when
     * the form comes back refused, which is the same checkout.
     */
    public function test_the_checkout_page_says_a_checkout_began(): void
    {
        $product = $this->product();
        $this->post('http://localhost/cart/add/'.$product->slug, ['qty' => 2]);

        $page = $this->get('http://localhost/checkout')->assertOk()->getContent();
        $this->assertSame(1, substr_count($page, 'new CustomEvent("torgash:checkout-started"'));
        $this->assertStringContainsString(
            '"lines":[{"id":"'.$product->id.'","sku":"GA-1","name":"Наковальня","brand":"Acme","categories":["Tools","Anvils"],"price":150,"quantity":2}]',
            $page,
        );

        $this->post('http://localhost/checkout', ['customer_name' => ''])->assertSessionHasErrors();
        $this->assertStringNotContainsString('torgash:checkout-started', $this->get('http://localhost/checkout')->assertOk()->getContent());
    }

    public function test_the_client_id_is_written_on_an_order_once_ticked(): void
    {
        $this->settings->set('torgash.checkout', 'client_facts', ['ip_address', Tag::FACT]);
        $product = $this->product();

        $order = $this->buy($product, ['_ga' => 'GA1.1.1234567890.1731500000']);
        $this->assertSame('1234567890.1731500000', $order->client[Tag::FACT] ?? null);

        $this->flushSession();
        $this->assertArrayNotHasKey(Tag::FACT, (array) $this->buy($product, ['_ga' => 'GA1.1.<b>1'])->client);
    }

    public function test_an_id_is_a_measurement_id_or_a_container(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->get('http://localhost/admin/settings/torgash.google-analytics')
            ->assertOk()->assertSee('Question about a product');

        $this->post('http://localhost/admin/settings/torgash.google-analytics', ['tag_id' => 'UA-12345-1'])
            ->assertSessionHasErrors('tag_id');
        $this->assertSame('G-TEST12345', setting('torgash.google-analytics', 'tag_id'));

        $this->post('http://localhost/admin/settings/torgash.google-analytics', [
            'tag_id' => 'gtm-abc1234', 'consent' => 'wait', 'item_id' => 'sku', 'leads' => ['callback', 'not-a-form'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('GTM-ABC1234', app(Tag::class)->id());
        $this->assertSame(['callback'], app(Tag::class)->leads());
    }

    /** A manager looking at the shop as a customer is the shop's own visit. */
    public function test_a_manager_looking_as_a_customer_is_not_counted(): void
    {
        $role = Role::findOrCreate('ga-support');
        $role->givePermissionTo(['admin.access', 'customers.manage', Impersonation::PERMISSION]);
        $manager = User::factory()->create();
        $manager->assignRole($role);
        $customer = User::factory()->create();

        $html = $this->actingAs($manager)->withSession([
            Impersonation::SESSION => ['manager' => $manager->id, 'customer' => $customer->id],
        ])->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringNotContainsString('googletagmanager.com', $html);
        $this->assertStringNotContainsString('google-analytics.js', $html);
    }

    protected function plain(string $html): string
    {
        return (string) preg_replace('/ nonce="[^"]*"/', '', $html);
    }

    protected function product(): Product
    {
        $make = Feature::create(['name' => ['en' => 'Make'], 'code' => 'ga_make', 'purpose' => 'brand', 'input_type' => 'select']);
        $acme = FeatureValue::create(['feature_id' => $make->id, 'value' => ['en' => 'Acme'], 'slug' => 'ga-acme']);
        $tools = Category::create(['name' => ['en' => 'Tools'], 'slug' => 'ga-tools', 'status' => 'active']);
        $anvils = Category::create(['name' => ['en' => 'Anvils'], 'slug' => 'ga-anvils', 'status' => 'active', 'parent_id' => $tools->id]);

        $product = Product::create([
            'sku' => 'GA-1', 'name' => ['en' => 'Наковальня'], 'slug' => 'ga-anvil',
            'price' => 150_00, 'stock' => 50, 'status' => 'active',
        ]);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $make->id, 'feature_value_id' => $acme->id]);
        $product->categories()->attach($anvils->id);

        return $product;
    }

    /** @param  array<string, string>  $cookies */
    protected function buy(Product $product, array $cookies = []): Order
    {
        $this->withUnencryptedCookies($cookies)->post('http://localhost/cart/add/'.$product->slug, ['qty' => 1]);
        $this->withUnencryptedCookies($cookies)->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer', 'email' => 'guest@example.test',
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ])->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }
}
