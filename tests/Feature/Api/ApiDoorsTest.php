<?php

namespace Tests\Feature\Api;

use App\Core\Settings\SettingsManager;
use App\Models\Storefront;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Coupon;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * The API is a door of the same shop: what the web form holds a visitor to,
 * and how many tries it allows, holds here too.
 */
class ApiDoorsTest extends TestCase
{
    protected string $cart;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Product::create(['sku' => 'DOOR-1', 'name' => ['en' => 'Door'], 'slug' => 'door-one', 'price' => 20_00, 'stock' => 10, 'status' => 'active']);
        $this->cart = $this->postJson('http://localhost/api/v2/shop/carts')->json('data.token');
        $this->patchJson($this->url('/items'), ['product' => 'DOOR-1', 'quantity' => 1])->assertOk();
    }

    protected function url(string $suffix = ''): string
    {
        return 'http://localhost/api/v2/shop/carts/'.$this->cart.$suffix;
    }

    protected function limit(int $perMinute): void
    {
        app(SettingsManager::class)->set('torgash.api', 'rate_limit', $perMinute);
    }

    // ---------------------------------------------------------------- limits

    public function test_a_made_up_token_is_not_a_new_allowance(): void
    {
        $this->limit(2);

        $codes = [];
        for ($i = 0; $i < 6; $i++) {
            $codes[] = $this->getJson('http://localhost/api/v2/shop/products', ['Authorization' => 'Bearer '.Str::random(40)])->status();
        }

        $this->assertContains(429, $codes);
    }

    public function test_tokens_the_shop_issued_do_not_use_up_each_other(): void
    {
        $this->limit(3);
        $admin = User::role('admin')->firstOrFail();
        [, $first] = ApiToken::issue($admin, 'One');
        [, $second] = ApiToken::issue($admin, 'Two');

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('http://localhost/api/v2/shop/products', ['Authorization' => 'Bearer '.$first])->assertOk();
        }

        $this->getJson('http://localhost/api/v2/shop/products', ['Authorization' => 'Bearer '.$first])->assertStatus(429);
        $this->getJson('http://localhost/api/v2/shop/products', ['Authorization' => 'Bearer '.$second])->assertOk();
    }

    public function test_guessing_codes_stops_at_the_web_box_and_at_the_api(): void
    {
        $this->post('http://localhost/cart/add/door-one', ['qty' => 1])->assertOk();

        for ($i = 0; $i < 10; $i++) {
            $this->post('http://localhost/cart/coupon', ['code' => 'GUESS'.$i]);
            $this->assertStringContainsString('not valid', (string) session('coupon_result.message'));
        }

        Coupon::create(['code' => 'REAL10', 'type' => 'percent', 'value' => 10, 'enabled' => true]);

        $this->post('http://localhost/cart/coupon', ['code' => 'REAL10']);
        $this->assertFalse(session('coupon_result.ok'), 'guessing stopped, even at the right one');
        $this->assertStringContainsString('Too many', (string) session('coupon_result.message'));

        // The same counter behind the other door.
        $this->patchJson($this->url('/checkout'), ['code' => 'REAL10'])->assertOk()->assertJsonPath('code.ok', false);
    }

    public function test_a_right_code_is_no_guess(): void
    {
        Coupon::create(['code' => 'REAL10', 'type' => 'percent', 'value' => 10, 'enabled' => true]);

        for ($i = 0; $i < 15; $i++) {
            $this->patchJson($this->url('/checkout'), ['code' => 'REAL10'])->assertOk()->assertJsonPath('code.ok', true);
        }
    }

    // ------------------------------------------------------------- storefront

    public function test_a_storefront_is_named_by_its_code_or_its_id(): void
    {
        $front = Storefront::query()->firstOrFail();

        $this->getJson('http://localhost/api/v2/shop/products', ['X-Storefront' => (string) $front->code])->assertOk();
        $this->getJson('http://localhost/api/v2/shop/products', ['X-Storefront' => (string) $front->id])->assertOk();
    }

    public function test_a_disabled_storefront_cannot_be_asked_for(): void
    {
        $default = Storefront::query()->orderByDesc('is_default')->orderBy('id')->firstOrFail();
        $off = Storefront::create(['code' => 'off', 'name' => 'Off', 'enabled' => false, 'default_locale' => 'en', 'locales' => ['en']]);

        $this->getJson('http://localhost/api/v2/shop/products', ['X-Storefront' => 'off'])->assertOk();

        $this->assertSame($default->id, app('torgash.storefront')->id, 'answered as the default, not as '.$off->code);
    }

    public function test_nonsense_for_a_storefront_is_not_a_500(): void
    {
        $this->getJson('http://localhost/api/v2/shop/products', ['X-Storefront' => 'no such thing'])->assertOk();
        $this->getJson('http://localhost/api/v2/shop/products', ['X-Storefront' => '99999999999999999999999'])->assertOk();
        $this->getJson('http://localhost/api/v2/shop/products?storefront[]=x')->assertOk();
    }

    // ------------------------------------------------------- what the form asks

    /** @return array<string, mixed> */
    protected function answers(): array
    {
        return [
            'customer_name' => 'Guest',
            'email' => 'nobody-confirmed-this@example.test',
            'address_line' => '1 Street',
            'city' => 'Town',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->orderBy('position')->value('id'),
        ];
    }

    public function test_a_shop_that_confirms_contacts_does_not_take_an_order_past_that(): void
    {
        config(['torgash.verification.check_mail_domain' => false]);
        app(SettingsManager::class)->set('core', 'verify_forms', ['checkout']);

        $this->patchJson($this->url('/checkout'), $this->answers())->assertOk();

        $this->postJson($this->url('/checkout/complete'))->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame(0, Order::count());
    }

    public function test_a_captcha_on_the_checkout_holds_at_the_api_too(): void
    {
        hook_listen('core.form.check', fn ($value, array $context) => $context['form'] === 'checkout' ? 'Prove you are human.' : $value);

        $this->patchJson($this->url('/checkout'), $this->answers())->assertOk();

        $this->postJson($this->url('/checkout/complete'))->assertStatus(422)->assertJsonValidationErrors('captcha');

        $this->assertSame(0, Order::count());
    }

    public function test_a_shop_that_asks_nothing_takes_the_order(): void
    {
        $this->patchJson($this->url('/checkout'), $this->answers())->assertOk();

        $this->postJson($this->url('/checkout/complete'))->assertStatus(201);

        $this->assertSame(1, Order::count());
    }
}
