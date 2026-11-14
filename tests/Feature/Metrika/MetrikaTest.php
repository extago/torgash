<?php

namespace Tests\Feature\Metrika;

use App\Core\Access\Impersonation;
use App\Models\Currency;
use App\Core\Settings\SettingsManager;
use App\Core\Support\Currencies;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\JustPlaced;
use Torgash\Exim\SchemaRegistry;
use Torgash\Metrika\Counter;
use Torgash\Privacy\ConsentState;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * Yandex Metrica (PLAN §73): the counter by its number, waiting for consent
 * where the shop asks; e-commerce from the page's own events in the counter's
 * currency; Metrica allowed to draw the storefront, never the admin; the
 * visitor's ClientID on the order — and in the API and the export — once the
 * shop ticks it.
 */
class MetrikaTest extends TestCase
{
    protected SettingsManager $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->settings = app(SettingsManager::class);
        $this->settings->set('torgash.metrika', 'counter_id', '12345678');
        // A shop that does not ask: the counter is printed as it is.
        $this->settings->set('torgash.privacy', 'consent_mode', 'off');
    }

    public function test_without_a_number_nothing_is_printed(): void
    {
        $this->settings->set('torgash.metrika', 'counter_id', '');

        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringNotContainsString('mc.yandex.ru', $html);
        $this->assertStringNotContainsString('dataLayerYM', $html);
        $this->assertStringNotContainsString('metrika.js', $html);
    }

    public function test_the_counter_starts_as_set_and_hears_the_page(): void
    {
        $this->settings->set('torgash.metrika', 'webvisor', false);
        $this->settings->set('torgash.metrika', 'goals', ['order', 'basket', 'nonsense']);

        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringContainsString('window.dataLayerYM = window.dataLayerYM || [];', $html);
        $this->assertMatchesRegularExpression('~<script nonce="[0-9a-f]+">\s*\(function\(m,e,t,r,i,k,a\)~', $html, 'the counter is signed');
        $this->assertStringContainsString('"https://mc.yandex.ru/metrika/tag.js","ym"', $html);
        $this->assertStringContainsString('ym(12345678, "init", {"clickmap":true,"trackLinks":true,"accurateTrackBounce":true,"trackHash":true,"ecommerce":"dataLayerYM"});', $html);
        $this->assertMatchesRegularExpression(
            '~<script nonce="[0-9a-f]+" src="[^"]*/modules/torgash\.metrika/assets/metrika\.js[^"]*" data-metrika-counter="12345678" data-goals="basket,order" data-ecommerce="1" data-currency="'.shop_currency().'" data-rate="1" data-hits="1" defer></script>~',
            $html,
        );
    }

    public function test_the_counter_waits_for_consent_where_the_shop_asks_for_it(): void
    {
        $this->settings->set('torgash.privacy', 'consent_mode', 'opt_in');

        $html = preg_replace('/ nonce="[^"]*"/', '', $this->get('http://localhost/')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('~<template data-consent="statistics">\s*<script>\s*\(function\(m,e,t,r,i,k,a\).*?ym\(12345678, "init"~s', $html);
        $this->assertStringContainsString('/assets/js/consent-gate.js', $html);
        $this->assertStringContainsString('window.dataLayerYM = window.dataLayerYM || [];', $html, 'the container is there: it sends nothing by itself');

        $agreed = preg_replace('/ nonce="[^"]*"/', '', $this->withCookie(ConsentState::COOKIE, '1:statistics')->get('http://localhost/')->getContent());
        $this->assertStringNotContainsString('<template data-consent="statistics">', $agreed);
        $this->assertStringContainsString('ym(12345678, "init"', $agreed);
    }

    /** The shop may count everyone, as code in "Necessary" does — its decision, not the module's (2026-11-15). */
    public function test_the_shop_may_count_everyone_without_waiting(): void
    {
        $this->settings->set('torgash.privacy', 'consent_mode', 'opt_in');
        $this->settings->set('torgash.metrika', 'wait_consent', false);

        $html = $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<template data-consent="statistics">', $html);
        $this->assertMatchesRegularExpression('~<script nonce="[0-9a-f]+">\s*\(function\(m,e,t,r,i,k,a\)~', $html, 'signed, and running');
        $this->assertStringContainsString('ym(12345678, "init"', $html);
    }

    /** The product a page shows, as Metrica wants it — and in the counter's currency, which Metrica will not convert. */
    public function test_a_product_page_says_which_product_in_the_counters_currency(): void
    {
        $product = $this->product();
        Currency::create(['code' => 'MTR', 'name' => 'Metric', 'symbol' => 'm', 'rate' => 2.5, 'decimals' => 2]);
        app(Currencies::class)->flush();
        $this->settings->set('torgash.metrika', 'currency', 'MTR');

        $html = $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent();

        $this->assertStringContainsString('window.dataLayerYM.push({"ecommerce":{"currencyCode":"MTR","detail":{"products":[{"id":"'.$product->id.'","name":"Наковальня","price":375,"brand":"Acme","category":"Tools/Anvils"}]}}});', $html);
        $this->assertStringContainsString('data-currency="MTR" data-rate="2.5"', $html);
    }

    /**
     * The click map and Webvisor draw the storefront inside Metrica — which
     * the policy allows, and X-Frame-Options, which can only say "same
     * origin", is left off. The admin stays drawn inside nobody's page.
     */
    public function test_metrica_may_draw_the_storefront_and_never_the_admin(): void
    {
        $page = $this->get('http://localhost/')->assertOk();
        $policy = (string) $page->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self' https://metrika.yandex.ru ", $policy);
        $this->assertStringContainsString('https://*.webvisor.com', $policy);
        $this->assertFalse($page->headers->has('X-Frame-Options'));

        $admin = $this->actingAs(User::role('admin')->firstOrFail())->get('http://localhost/admin')->assertOk();
        $this->assertStringContainsString("frame-ancestors 'self'; ", (string) $admin->headers->get('Content-Security-Policy'));
        $this->assertSame('SAMEORIGIN', $admin->headers->get('X-Frame-Options'));

        // Neither maps nor recordings: nothing to allow.
        foreach (['webvisor', 'clickmap', 'track_links'] as $key) {
            $this->settings->set('torgash.metrika', $key, false);
        }
        $this->flushSession();
        auth()->logout();
        $plain = $this->get('http://localhost/')->assertOk();
        $this->assertStringContainsString("frame-ancestors 'self'; ", (string) $plain->headers->get('Content-Security-Policy'));
        $this->assertSame('SAMEORIGIN', $plain->headers->get('X-Frame-Options'));
    }

    /**
     * The ClientID is a fact an order writes down about the browser — once
     * the shop ticks it — and so it is on the order's card, in the API and
     * in the export, and goes with the rest of the browser's facts.
     */
    public function test_the_client_id_is_written_on_an_order_once_ticked_and_handed_on(): void
    {
        $this->settings->set('torgash.checkout', 'client_facts', ['ip_address', 'user_agent', Counter::FACT]);
        $product = $this->product();

        $order = $this->buy($product, ['_ym_uid' => '1731500000123456789']);
        $this->assertSame('1731500000123456789', $order->client[Counter::FACT] ?? null);

        [, $token] = ApiToken::issue(User::role('admin')->firstOrFail(), 'Tests');
        $this->getJson('http://localhost/api/v2/admin/orders/'.$order->id, ['Authorization' => 'Bearer '.$token])
            ->assertOk()->assertJsonPath('data.client.'.Counter::FACT, '1731500000123456789');

        $column = collect(app(SchemaRegistry::class)->fieldsFor('order'))->firstWhere('key', 'client_'.Counter::FACT);
        $this->assertSame('Yandex Metrica ClientID', $column['label']);
        $this->assertSame('1731500000123456789', ($column['get'])($order));

        $this->actingAs(User::role('admin')->firstOrFail())->get('http://localhost/admin/orders/'.$order->order_number)
            ->assertOk()->assertSee('Yandex Metrica ClientID')->assertSee('1731500000123456789');

        // Not a number the counter writes: nothing.
        auth()->logout();
        $this->flushSession();
        $this->assertArrayNotHasKey(Counter::FACT, (array) $this->buy($product, ['_ym_uid' => '<script>'])->client);

        // Not ticked: nothing either.
        $this->settings->set('torgash.checkout', 'client_facts', ['ip_address', 'user_agent']);
        $this->flushSession();
        $this->assertArrayNotHasKey(Counter::FACT, (array) $this->buy($product, ['_ym_uid' => '1731500000123456789'])->client);
    }

    /**
     * The page is told once that the order is placed — a reload of the
     * "thank you" page is not a second purchase — and an order placed in one
     * click, which has no page of its own, is told in its answer.
     */
    public function test_a_placed_order_is_announced_once(): void
    {
        $product = $this->product();
        $order = $this->buy($product);

        $page = $this->get('http://localhost/checkout/'.$order->order_number.'/confirmation')->assertOk()->getContent();
        $this->assertSame(1, substr_count($page, 'new CustomEvent("torgash:order-placed"'));
        $this->assertStringContainsString('"number":"'.$order->order_number.'"', $page);
        $this->assertStringContainsString('"lines":[{"name":"Наковальня","id":"'.$product->id.'"', $page);

        $again = $this->get('http://localhost/checkout/'.$order->order_number.'/confirmation')->assertOk()->getContent();
        $this->assertStringNotContainsString('torgash:order-placed', $again);
        $this->assertStringContainsString('Thank you!', $again, 'the page still thanks — it is only not counted twice');

        // In one click from the product page.
        $this->settings->set('torgash.checkout', 'one_click', true);
        $this->settings->set('torgash.checkout', 'one_click_shipping_id', (string) ShippingMethod::where('enabled', true)->value('id'));
        $this->settings->set('torgash.checkout', 'one_click_payment_id', PaymentMethod::where('enabled', true)->value('id'));

        $answer = $this->withHeader('HX-Request', 'true')->post('http://localhost/one-click', [
            'product_id' => $product->id, 'customer_name' => 'Пётр', 'phone' => '+380 (67) 555-01-23',
            \App\Core\Support\FormGuard::STAMP => \Illuminate\Support\Facades\Crypt::encryptString((string) (time() - 30)),
            \App\Core\Support\FormGuard::TRAP => '',
        ])->assertOk();

        $placed = json_decode((string) $answer->headers->get('HX-Trigger'), true)[JustPlaced::EVENT] ?? null;
        $this->assertSame(Order::latest('id')->value('order_number'), $placed['number'] ?? null);
        $this->assertSame([(string) $product->id, 1], [$placed['lines'][0]['id'], $placed['lines'][0]['quantity']]);
    }

    public function test_a_counter_number_is_digits(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/settings/torgash.metrika', ['counter_id' => 'ym(1234)'])
            ->assertSessionHasErrors('counter_id');
        $this->assertSame('12345678', setting('torgash.metrika', 'counter_id'));

        $this->post('http://localhost/admin/settings/torgash.metrika', ['counter_id' => ' 87654321 '])->assertSessionHasNoErrors();
        $this->assertSame('87654321', app(Counter::class)->id());
    }

    /** A manager looking at the shop as a customer is the shop's own visit, not a visitor's. */
    public function test_a_manager_looking_as_a_customer_is_not_counted(): void
    {
        $role = Role::findOrCreate('mt-support');
        $role->givePermissionTo(['admin.access', 'customers.manage', Impersonation::PERMISSION]);
        $manager = User::factory()->create();
        $manager->assignRole($role);
        $customer = User::factory()->create();

        $html = $this->actingAs($manager)->withSession([
            Impersonation::SESSION => ['manager' => $manager->id, 'customer' => $customer->id],
        ])->get('http://localhost/')->assertOk()->getContent();

        $this->assertStringNotContainsString('mc.yandex.ru', $html);
        $this->assertStringNotContainsString('metrika.js', $html);
    }

    protected function product(): Product
    {
        $make = Feature::create(['name' => ['en' => 'Make'], 'code' => 'mt_make', 'purpose' => 'brand', 'input_type' => 'select']);
        $acme = FeatureValue::create(['feature_id' => $make->id, 'value' => ['en' => 'Acme'], 'slug' => 'mt-acme']);
        $tools = Category::create(['name' => ['en' => 'Tools'], 'slug' => 'mt-tools', 'status' => 'active']);
        $anvils = Category::create(['name' => ['en' => 'Anvils'], 'slug' => 'mt-anvils', 'status' => 'active', 'parent_id' => $tools->id]);

        $product = Product::create([
            'sku' => 'MT-1', 'name' => ['en' => 'Наковальня'], 'slug' => 'mt-anvil',
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
