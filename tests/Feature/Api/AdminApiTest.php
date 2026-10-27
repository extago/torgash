<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\MakesVariationAxes;
use Tests\TestCase;
use Torgash\Api\ApiResources;
use Torgash\Api\Models\ApiToken;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;

/**
 * The admin half of the API: one controller, every module's resources, and the
 * conventions applied from one place.
 *
 * What is pinned here is mostly what must NOT happen — a call with no token
 * reading the catalogue, a read-only key writing, a PATCH rewriting fields the
 * exchange does not own, a status set as a column instead of run as a
 * transition. The happy paths are one assertion each; the refusals are the
 * feature.
 */
class AdminApiTest extends TestCase
{
    use MakesVariationAxes;

    protected User $admin;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->admin = User::role('admin')->firstOrFail();
        [, $this->token] = ApiToken::issue($this->admin, 'Tests');
    }

    /** @param array<string, mixed> $headers */
    protected function call_(string $method, string $uri, array $data = [], ?string $token = null)
    {
        return $this->json($method, 'http://localhost'.$uri, $data, [
            'Authorization' => 'Bearer '.($token ?? $this->token),
        ]);
    }

    // ------------------------------------------------------------------ auth

    public function test_no_token_is_no_answer(): void
    {
        $this->json('GET', 'http://localhost/api/v2/admin/products')->assertStatus(401);
    }

    public function test_an_unknown_or_expired_token_is_refused(): void
    {
        $this->call_('GET', '/api/v2/admin/products', [], 'tg_nonsense')->assertStatus(401);

        [$expired, $plaintext] = ApiToken::issue($this->admin, 'Old', null, now()->subDay());

        $this->call_('GET', '/api/v2/admin/products', [], $plaintext)->assertStatus(401);
        $this->assertTrue($expired->isExpired());
    }

    /** A key narrowed to reading may not write, whatever its owner may do. */
    public function test_a_read_only_token_cannot_write(): void
    {
        [, $readOnly] = ApiToken::issue($this->admin, 'Feed', ['read']);
        $product = $this->product();

        $this->call_('GET', '/api/v2/admin/products/'.$product->sku, [], $readOnly)->assertOk();
        $this->call_('PATCH', '/api/v2/admin/products/'.$product->sku, ['stock' => 5], $readOnly)->assertStatus(403);
    }

    /** And a token can never do more than the account behind it. */
    public function test_the_owners_permissions_still_apply(): void
    {
        $customer = User::factory()->create();
        [, $token] = ApiToken::issue($customer, 'Customer key');

        $this->call_('GET', '/api/v2/admin/products', [], $token)->assertStatus(403);
    }

    // ----------------------------------------------------------------- lists

    public function test_a_list_pages_and_says_where_it_is(): void
    {
        foreach (range(1, 3) as $i) {
            $this->product(['sku' => "PAGE-$i", 'slug' => "page-$i"]);
        }

        $response = $this->call_('GET', '/api/v2/admin/products?itemsPerPage=2&page=1')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.itemsPerPage'));
        $this->assertGreaterThanOrEqual(3, $response->json('meta.total'));
    }

    /** Asking for everything gets the ceiling, not an error. */
    public function test_too_many_items_are_capped_rather_than_refused(): void
    {
        $response = $this->call_('GET', '/api/v2/admin/products?itemsPerPage=99999')->assertOk();

        $this->assertSame(
            (int) setting('torgash.api', 'max_items_per_page', 200),
            $response->json('meta.itemsPerPage'),
        );
    }

    /** Sorting is allow-listed: an arbitrary column is an unindexed sort. */
    public function test_only_declared_columns_can_be_sorted_by(): void
    {
        $this->product(['sku' => 'AAA-1', 'slug' => 'aaa-1']);
        $this->product(['sku' => 'ZZZ-1', 'slug' => 'zzz-1']);

        $sorted = $this->call_('GET', '/api/v2/admin/products?order[sku]=desc')->assertOk();
        $this->assertSame('ZZZ-1', $sorted->json('data.0.sku'));

        // Not sortable, and therefore ignored rather than obeyed.
        $this->call_('GET', '/api/v2/admin/products?order[description]=desc')->assertOk();
    }

    // ------------------------------------------------------------- addressing

    /** Our id, their id, or the shop's own key — the same row. */
    public function test_a_row_is_addressable_by_every_key_it_has(): void
    {
        $product = $this->product(['sku' => 'ADDR-1', 'slug' => 'addr-1', 'external_id' => '1c-777']);

        foreach ([(string) $product->id, 'ADDR-1', '1c-777'] as $key) {
            $this->call_('GET', '/api/v2/admin/products/'.$key)->assertOk()->assertJsonPath('data.id', $product->id);
        }

        $this->call_('GET', '/api/v2/admin/products/nobody')->assertStatus(404);
    }

    // ----------------------------------------------------------------- writes

    /** A PATCH changes what it names, and only what the exchange owns. */
    public function test_a_patch_is_partial_and_bounded(): void
    {
        $product = $this->product(['sku' => 'WRITE-1', 'slug' => 'write-1', 'stock' => 3]);

        $this->call_('PATCH', '/api/v2/admin/products/WRITE-1', [
            'stock' => 9,
            // Not in the allow-list: the shop's own address for the product is
            // not an exchange's to rewrite.
            'slug' => 'hijacked',
        ])->assertOk()->assertJsonPath('data.stock', 9);

        $product->refresh();

        $this->assertSame(9, $product->stock);
        $this->assertSame('write-1', $product->slug);
    }

    /** Prices go through the same arithmetic the admin form uses. */
    public function test_a_price_written_in_another_currency_is_stored_the_way_the_form_would(): void
    {
        $this->product(['sku' => 'PRICE-1', 'slug' => 'price-1']);

        $this->call_('PATCH', '/api/v2/admin/products/PRICE-1', [
            'price' => 42000,
            'price_currency' => 'UAH',
        ])->assertOk();

        $product = Product::where('sku', 'PRICE-1')->firstOrFail();

        $this->assertSame('UAH', $product->price_currency);
        $this->assertSame(4200000, $product->price_original);
        // Derived into the books, exactly as Prices::columns() does elsewhere.
        $this->assertGreaterThan(0, $product->price);
    }

    public function test_a_read_only_resource_says_so(): void
    {
        ApiResources::register('admin.readonly_things', [
            'permission' => null,
            'query' => fn () => Product::query(),
            'serialize' => fn ($p) => ['id' => $p->id],
        ]);

        try {
            $product = $this->product(['sku' => 'RO-1', 'slug' => 'ro-1']);

            $this->call_('PATCH', '/api/v2/admin/readonly_things/'.$product->id, ['x' => 1])->assertStatus(405);
        } finally {
            ApiResources::forget('admin.readonly_things');
        }
    }

    // ---------------------------------------------------------------- actions

    /**
     * The transition, not the column: moving an order through the API runs the
     * same OrderStatusChanger the admin's select runs, so the stock moves and
     * the change is recorded.
     */
    public function test_a_status_moves_through_the_shops_own_code(): void
    {
        $product = $this->product(['sku' => 'ACT-1', 'slug' => 'act-1', 'stock' => 5]);

        $order = Order::create([
            'order_number' => 'TG-API1',
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'currency_code' => 'USD', 'subtotal' => 10_00, 'shipping_cost' => 0, 'total' => 10_00,
            'status' => 'awaiting_payment', 'payment_state' => PaymentState::PENDING,
            'stock_deducted' => false,
            'items' => [['product_id' => $product->id, 'sku' => $product->sku, 'name' => 'Acted', 'qty' => 2, 'unit_price' => 5_00, 'line_total' => 10_00]],
        ]);

        $this->call_('POST', '/api/v2/admin/orders/TG-API1/actions/status', ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('data.status', 'open');

        $order->refresh();

        $this->assertTrue($order->stock_deducted);
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertTrue($order->logs()->exists());
    }

    public function test_an_unknown_action_is_a_404(): void
    {
        $this->call_('POST', '/api/v2/admin/orders/TG-API1/actions/teleport')->assertStatus(404);
    }

    /** @param array<string, mixed> $attributes */
    /** Categories: the list replaces, a string is an external id, an unknown one refuses it all. */
    public function test_categories_are_written_whole_or_not_at_all(): void
    {
        $product = $this->product(['sku' => 'CAT-1', 'slug' => 'cat-1']);
        $old = Category::create(['name' => ['en' => 'Old'], 'slug' => 'api-old-'.uniqid()]);
        $byId = Category::create(['name' => ['en' => 'By id'], 'slug' => 'api-id-'.uniqid()]);
        $byKey = Category::create(['name' => ['en' => 'By key'], 'slug' => 'api-key-'.uniqid(), 'external_id' => '0042']);
        $product->categories()->sync([$old->id]);

        $this->call_('PATCH', '/api/v2/admin/products/CAT-1', ['categories' => [$byId->id, '0042'], 'stock' => 5])
            ->assertOk()
            ->assertJsonPath('data.categories.0.id', $byId->id);

        $this->assertEqualsCanonicalizing([$byId->id, $byKey->id], $product->categories()->pluck('m_catalog_categories.id')->all());

        $this->call_('PATCH', '/api/v2/admin/products/CAT-1', ['categories' => [$old->id, 'no-such-key'], 'stock' => 7])
            ->assertStatus(422)
            ->assertJsonValidationErrors('categories');

        $product->refresh();
        $this->assertSame(5, $product->stock, 'a refused call writes nothing');
        $this->assertEqualsCanonicalizing([$byId->id, $byKey->id], $product->categories()->pluck('m_catalog_categories.id')->all());

        $this->call_('PATCH', '/api/v2/admin/products/CAT-1', ['categories' => []])->assertOk();
        $this->assertSame(0, $product->categories()->count());
    }

    /** A product joins a group of variations unless nothing would tell it from a member. */
    public function test_a_variation_group_is_joined_only_by_a_product_that_differs(): void
    {
        [, $colours] = $this->variationAxis('Api colour', ['Red', 'Blue']);
        $red = $this->product(['sku' => 'VAR-RED', 'slug' => 'var-red', 'variation_group' => 'api-tee']);
        $this->answer($red, $colours['Red']);
        $blue = $this->product(['sku' => 'VAR-BLUE', 'slug' => 'var-blue']);
        $this->answer($blue, $colours['Blue']);
        $twin = $this->product(['sku' => 'VAR-TWIN', 'slug' => 'var-twin', 'stock' => 1]);
        $this->answer($twin, $colours['Red']);

        $this->call_('PATCH', '/api/v2/admin/products/VAR-BLUE', ['variation_group' => 'api-tee'])
            ->assertOk()->assertJsonPath('data.variation_group', 'api-tee');

        $this->call_('PATCH', '/api/v2/admin/products/VAR-TWIN', ['variation_group' => 'api-tee', 'stock' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variation_group')
            ->assertSee('VAR-RED');

        $twin->refresh();
        $this->assertNull($twin->variation_group, 'the twin stays out');
        $this->assertSame(1, $twin->stock, 'and nothing else of the call is written');

        $this->call_('PATCH', '/api/v2/admin/products/VAR-BLUE', ['variation_group' => null])
            ->assertOk()->assertJsonPath('data.variation_group', null);
    }

    protected function product(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'sku' => 'API-'.random_int(1000, 9999),
            'slug' => 'api-'.random_int(1000, 9999),
            'name' => ['en' => 'Api widget'],
            'price' => 10_00,
            'stock' => 1,
            'status' => 'active',
        ]);
    }
}
