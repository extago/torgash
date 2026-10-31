<?php

namespace Tests\Feature\Points;

use App\Core\Mail\EventMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderChanges;
use Torgash\Checkout\Orders\OrderStatusChanger;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Checkout\Orders\RecordPayment;
use Torgash\Giftcards\Issuer;
use Torgash\Points\Earning;
use Torgash\Points\Ledger;
use Torgash\Points\Models\OrderPoints;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * Bonus points (PLAN §40.5): earned from the money actually paid, credited when
 * the order reaches the chosen status, spent in the basket as a discount after
 * the coupon, taken back with a cancellation or a refund.
 */
class PointsTest extends TestCase
{
    protected User $customer;

    protected PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->customer = User::factory()->create();
        $this->cash = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => -1]);

        Product::create(['sku' => 'PT-1', 'name' => ['en' => 'Table'], 'slug' => 'pt-table', 'price' => 2450_00, 'stock' => 10, 'status' => 'active']);
    }

    public function test_an_order_waits_and_is_credited_when_it_is_completed(): void
    {
        $order = $this->buy();

        $row = OrderPoints::findOrFail($order->id);
        $this->assertSame((int) floor(2450_00 * app(Earning::class)->rate()), $row->earn);
        $this->assertSame(24, $row->earn, '1 point for every 100');
        $this->assertSame(0, app(Ledger::class)->balance($this->customer->id));

        app(OrderStatusChanger::class)->change($order, OrderStatus::findOrFail('complete'));

        $this->assertSame(24, app(Ledger::class)->balance($this->customer->id));
        Mail::assertQueued(EventMail::class, fn ($mail) => $mail->event === 'points.credited');

        // Completing twice credits once.
        app(OrderStatusChanger::class)->change($order->fresh(), OrderStatus::findOrFail('shipped'));
        app(OrderStatusChanger::class)->change($order->fresh(), OrderStatus::findOrFail('complete'));
        $this->assertSame(24, app(Ledger::class)->balance($this->customer->id));
    }

    public function test_points_are_spent_as_a_discount_that_lowers_the_order(): void
    {
        app(Ledger::class)->adjust($this->customer->id, 500, 'Welcome');

        $this->actingAs($this->customer)->post('http://localhost/cart/add/pt-table', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/cart/points', ['points' => '300']);

        $order = $this->placeWithoutAdding();

        $this->assertSame(300_00, $order->discount_amount);
        $this->assertSame([['code' => 'points', 'amount' => 300_00]], array_map(fn ($l) => ['code' => $l['code'], 'amount' => $l['amount']], $order->discountLines()));
        $this->assertSame(200, app(Ledger::class)->balance($this->customer->id));
        $this->assertSame(300, OrderPoints::find($order->id)->spent);
        // Earned only on what is left after the discount.
        $this->assertSame(21, OrderPoints::find($order->id)->earn);
    }

    public function test_no_more_than_the_shops_share_of_the_goods(): void
    {
        app(Ledger::class)->adjust($this->customer->id, 5000, 'Lots');

        $this->actingAs($this->customer)->post('http://localhost/cart/add/pt-table', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/cart/points', ['points' => '5000']);

        $order = $this->placeWithoutAdding();

        $this->assertSame(1225_00, $order->discount_amount, 'half of 2450 by default');
    }

    public function test_a_cancelled_order_returns_what_it_spent_and_takes_back_what_it_earned(): void
    {
        app(Ledger::class)->adjust($this->customer->id, 100, 'Start');
        $this->actingAs($this->customer)->post('http://localhost/cart/add/pt-table', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/cart/points', ['points' => '100']);
        $order = $this->placeWithoutAdding();
        $this->assertSame(0, app(Ledger::class)->balance($this->customer->id));

        app(OrderStatusChanger::class)->change($order, OrderStatus::findOrFail('complete'));
        $earned = OrderPoints::find($order->id)->earned;
        $this->assertGreaterThan(0, $earned);

        app(OrderStatusChanger::class)->change($order->fresh(), OrderStatus::findOrFail('cancelled'));

        $this->assertSame(100, app(Ledger::class)->balance($this->customer->id));
    }

    public function test_a_refund_takes_back_the_earned_points(): void
    {
        $order = $this->buy();
        app(OrderStatusChanger::class)->change($order, OrderStatus::findOrFail('complete'));
        app(RecordPayment::class)->record($order->fresh(), PaymentState::PAID);
        app(OrderChanges::class)->flush();
        $this->assertSame(24, app(Ledger::class)->balance($this->customer->id));

        app(\Torgash\Checkout\Orders\Refund::class)->refund($order->fresh(), $order->fresh()->paid_total);
        app(OrderChanges::class)->flush();

        $this->assertSame(0, app(Ledger::class)->balance($this->customer->id));
    }

    public function test_a_gift_card_part_earns_nothing(): void
    {
        [, $code] = app(Issuer::class)->issue(1000_00);

        $this->actingAs($this->customer)->post('http://localhost/cart/add/pt-table', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/cart/coupon', ['code' => $code]);
        $order = $this->placeWithoutAdding();

        $money = $order->total - 1000_00;
        $this->assertSame((int) floor(2450_00 * $money / $order->total / 100_00), OrderPoints::find($order->id)->earn);
    }

    public function test_a_category_multiplier_counts_for_its_subtree(): void
    {
        $parent = Category::create(['name' => ['en' => 'Furniture'], 'slug' => 'pt-furniture']);
        $child = Category::create(['name' => ['en' => 'Tables'], 'slug' => 'pt-tables', 'parent_id' => $parent->id]);
        Product::where('sku', 'PT-1')->first()->categories()->attach($child->id);
        DB::table('m_points_rules')->insert(['kind' => 'category', 'target_id' => $parent->id, 'multiplier' => 2]);

        $order = $this->buy();

        $this->assertSame(49, OrderPoints::find($order->id)->earn);
    }

    public function test_a_line_can_be_bought_outright_for_its_price_in_points(): void
    {
        app(\App\Core\Settings\SettingsManager::class)->set('torgash.points', 'points_prices', true);
        $product = Product::where('sku', 'PT-1')->first();
        DB::table('m_points_products')->insert(['product_id' => $product->id, 'points_price' => 3000]);
        app(Ledger::class)->adjust($this->customer->id, 3500, 'Collected');

        $this->actingAs($this->customer)->get('http://localhost/product/pt-table')->assertOk()->assertSee('3 000');

        $this->actingAs($this->customer)->post('http://localhost/cart/add/pt-table', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/cart/points/lines/'.$product->id);

        $order = $this->placeWithoutAdding();

        $this->assertSame(2450_00, $order->discount_amount);
        $this->assertSame(500, app(Ledger::class)->balance($this->customer->id));
    }

    public function test_a_guest_neither_earns_nor_spends(): void
    {
        $this->post('http://localhost/cart/add/pt-table', ['qty' => 1]);
        $order = $this->placeWithoutAdding(guest: true);

        $this->assertNull(OrderPoints::find($order->id));
    }

    public function test_the_screens(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(Ledger::class)->adjust($this->customer->id, 120, 'Hello');

        $this->actingAs($this->customer)->get('http://localhost/account/points')->assertOk()->assertSee('120');
        $this->actingAs($this->customer)->post('http://localhost/cart/add/pt-table', ['qty' => 1]);
        $this->actingAs($this->customer)->get('http://localhost/cart')->assertOk()->assertSee(__('Bonus points'));
        $this->actingAs($this->customer)->get('http://localhost/checkout')->assertOk()->assertSee(__('Bonus points'));

        $this->actingAs($admin)->get('http://localhost/admin/points')->assertOk()->assertSee($this->customer->email);
        $this->actingAs($admin)->get('http://localhost/admin/customers/'.$this->customer->id)->assertOk()->assertSee('120');
        $this->actingAs($admin)->post('http://localhost/admin/points/adjust', ['email' => $this->customer->email, 'points' => '-20', 'reason' => 'Typo'])->assertSessionHasNoErrors();
        $this->assertSame(100, app(Ledger::class)->balance($this->customer->id));
    }

    /** On a card the points stand after the stars, or under the price — the
     *  shop's choice; the product page keeps them under its price either way. */
    public function test_a_card_shows_the_points_where_the_shop_chose(): void
    {
        $category = Category::create(['name' => ['en' => 'Points place'], 'slug' => 'pt-place']);
        Product::where('sku', 'PT-1')->firstOrFail()->categories()->attach($category->id);

        $card = function (): string {
            $html = $this->actingAs($this->customer)->get('http://localhost/catalog/pt-place')->assertOk()->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="utf-8">'.$html);
            $xpath = new \DOMXPath($dom);

            // The text of the price's own column, and of everything else.
            $price = $xpath->query('//*[contains(@class, "torgash-card-buy")]')->item(0)->textContent;

            return str_contains($price, '+24') ? 'price' : (str_contains($html, '+24') ? 'meta' : 'none');
        };

        $this->assertSame('meta', $card());

        app(\App\Core\Settings\SettingsManager::class)->set('torgash.points', 'card_place', 'price');

        $this->assertSame('price', $card());
        $this->actingAs($this->customer)->get('http://localhost/product/pt-table')->assertOk()->assertSee('+24');
    }

    public function test_expired_points_are_written_off_oldest_first(): void
    {
        app(\App\Core\Settings\SettingsManager::class)->set('torgash.points', 'lifetime_months', 1);
        app(Ledger::class)->adjust($this->customer->id, 100, 'Old');
        $this->travel(20)->days();
        app(Ledger::class)->adjust($this->customer->id, 50, 'New');
        $this->travel(15)->days();

        app(Ledger::class)->expire();

        $this->assertSame(50, app(Ledger::class)->balance($this->customer->id));
    }

    public function test_invariants_hold(): void
    {
        app(Ledger::class)->adjust($this->customer->id, 100, 'Start');
        $this->actingAs($this->customer)->post('http://localhost/cart/add/pt-table', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/cart/points', ['points' => '100']);
        $this->placeWithoutAdding();

        foreach ((new \Torgash\Points\Invariants\PointsInvariants)->register() as $key => $invariant) {
            $this->assertSame([], ($invariant['check'])(100), $key);
        }
    }

    protected function buy(): Order
    {
        $this->actingAs($this->customer)->post('http://localhost/cart/add/pt-table', ['qty' => 1]);

        return $this->placeWithoutAdding();
    }

    protected function placeWithoutAdding(bool $guest = false): Order
    {
        $request = $guest ? $this : $this->actingAs($this->customer);

        $request->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => $guest ? 'guest@example.test' : $this->customer->email,
            'address_line' => '1 Street', 'city' => 'Town',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $this->cash->id,
        ])->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }
}
