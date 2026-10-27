<?php

namespace Tests\Feature\Giftcards;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Catalog\Models\Product;
use Torgash\Giftcards\Issuer;
use Torgash\Giftcards\Models\Card;
use Torgash\Points\Ledger;

/**
 * Gift cards and points over the API (PLAN §40.7/5): issue, read, correct and
 * switch off a card; a customer's points and a correction; a shop client
 * checking a balance and paying a cart with a code.
 */
class GiftcardApiTest extends TestCase
{
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        RateLimiter::clear('giftcards:127.0.0.1');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        [, $this->token] = ApiToken::issue($admin, 'Tests');
    }

    protected function api(string $method, string $uri, array $data = [])
    {
        return $this->json($method, 'http://localhost'.$uri, $data, ['Authorization' => 'Bearer '.$this->token]);
    }

    public function test_a_card_is_issued_read_corrected_and_switched_off(): void
    {
        $issued = $this->api('POST', '/api/v2/admin/giftcards', ['amount' => 50000, 'recipient_email' => 'friend@example.test'])
            ->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}(-[A-Z0-9]{4}){3}$/', $issued['code']);
        $this->assertSame(50000, $issued['balance']);

        $read = $this->api('GET', '/api/v2/admin/giftcards/'.$issued['id'])->assertOk()->json('data');
        $this->assertArrayNotHasKey('code', $read, 'the code is handed out once');

        $this->api('POST', '/api/v2/admin/giftcards/'.$issued['id'].'/actions/adjust', ['amount' => -10000, 'reason' => 'Correction'])
            ->assertOk()->assertJsonPath('data.balance', 40000);
        $this->api('POST', '/api/v2/admin/giftcards/'.$issued['id'].'/actions/disable')->assertOk()->assertJsonPath('data.state', 'disabled');

        $this->api('GET', '/api/v2/admin/giftcards?filter[code]='.strtolower($issued['code']))->assertOk()->assertJsonPath('data.0.id', $issued['id']);
    }

    public function test_the_shop_api_checks_a_balance_and_pays_a_cart_with_a_code(): void
    {
        [$card, $code] = app(Issuer::class)->issue(300_00);
        Product::create(['sku' => 'API-GC', 'name' => ['en' => 'Lamp'], 'slug' => 'api-gc-lamp', 'price' => 1000_00, 'stock' => 5, 'status' => 'active']);

        $this->getJson('http://localhost/api/v2/shop/giftcards/'.$code)->assertOk()->assertJsonPath('data.balance', 300_00);
        $this->getJson('http://localhost/api/v2/shop/giftcards/AAAA-BBBB-CCCC-DDDD')->assertNotFound();

        $cart = $this->postJson('http://localhost/api/v2/shop/carts')->json('data.token');
        $this->patchJson('http://localhost/api/v2/shop/carts/'.$cart.'/items', ['product' => 'API-GC', 'quantity' => 1])->assertOk();

        $answer = $this->patchJson('http://localhost/api/v2/shop/carts/'.$cart.'/checkout', ['code' => $code])->assertOk();
        $this->assertTrue($answer->json('code.ok'));
        $this->assertSame(300_00, $answer->json('data.prepaid.0.amount'));
        $this->assertSame($answer->json('data.total') - 300_00, $answer->json('data.to_pay'));

        $this->patchJson('http://localhost/api/v2/shop/carts/'.$cart.'/checkout', ['remove_prepaid' => $card->id])
            ->assertOk()->assertJsonPath('data.prepaid', []);
    }

    public function test_points_are_read_and_corrected(): void
    {
        $customer = User::factory()->create();
        app(Ledger::class)->adjust($customer->id, 200, 'Welcome');

        $this->api('GET', '/api/v2/admin/points/'.$customer->id)->assertOk()->assertJsonPath('data.balance', 200);
        $this->api('POST', '/api/v2/admin/points/'.$customer->id.'/actions/adjust', ['points' => -50, 'reason' => 'Typo'])
            ->assertOk()->assertJsonPath('data.balance', 150);
        $this->api('POST', '/api/v2/admin/points/'.$customer->id.'/actions/adjust', ['points' => -500, 'reason' => 'Too much'])
            ->assertStatus(422);
    }

    public function test_the_description_lists_them(): void
    {
        $document = $this->getJson('http://localhost/api/v2/openapi.json')->assertOk()->json();

        $this->assertArrayHasKey('/api/v2/admin/giftcards', $document['paths']);
        $this->assertArrayHasKey('post', $document['paths']['/api/v2/admin/giftcards']);
        $this->assertArrayHasKey('/api/v2/shop/giftcards/{code}', $document['paths']);
        $this->assertArrayHasKey('/api/v2/admin/points/{key}/actions/adjust', $document['paths']);
    }
}
