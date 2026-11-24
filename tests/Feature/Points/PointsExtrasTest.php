<?php

namespace Tests\Feature\Points;

use App\Core\Mail\EventMail;
use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderStatusChanger;
use Torgash\Points\ExpiryNotices;
use Torgash\Points\Ledger;
use Torgash\Points\Models\Entry;
use Torgash\Points\Models\OrderPoints;
use Torgash\Points\Rules;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * Bonus points, stage 4 (PLAN §74): a letter before points expire and the
 * account saying when, points for the way of paying, an order too small to
 * spend points on, the link to the shop's page about the programme and the
 * product page saying how much of it points pay.
 */
class PointsExtrasTest extends TestCase
{
    protected User $customer;

    protected PaymentMethod $cash;

    protected PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->customer = User::factory()->create();
        $this->cash = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => -2]);
        $this->card = PaymentMethod::create(['name' => ['en' => 'Card online'], 'enabled' => true, 'position' => -1]);

        Product::create(['sku' => 'PX-1', 'name' => ['en' => 'Chair'], 'slug' => 'px-chair', 'price' => 2000_00, 'stock' => 10, 'status' => 'active']);
    }

    public function test_a_customer_is_written_once_before_points_expire(): void
    {
        $this->set('lifetime_months', 1);
        app(Ledger::class)->adjust($this->customer->id, 100, 'Old');
        $this->travel(10)->days();
        app(Ledger::class)->adjust($this->customer->id, 40, 'New');

        // Nothing is within a week of its date yet.
        $this->assertSame(0, app(ExpiryNotices::class)->send());

        $this->travel(15)->days();
        $this->assertSame(1, app(ExpiryNotices::class)->send());
        Mail::assertQueued(EventMail::class, fn ($mail) => $mail->event === 'points.expiring'
            && $mail->hasTo($this->customer->email) && $mail->payload['points'] === 100);

        // …and not again tomorrow for the same credit.
        $this->travel(1)->days();
        $this->assertSame(0, app(ExpiryNotices::class)->send());
        Mail::assertQueued(EventMail::class, 1);

        // The account says the same: the nearest day and what goes on it.
        $this->actingAs($this->customer)->get('http://localhost/account/points')->assertOk()
            ->assertSee(trans_choice(':points point expires on :date|:points points expire on :date', 100, [
                'points' => '100',
                'date' => Entry::where('note', 'Old')->firstOrFail()->expires_at->format('d.m.Y'),
            ]));
    }

    public function test_points_already_spent_are_not_warned_about_and_no_days_means_no_letter(): void
    {
        $this->set('lifetime_months', 1);
        app(Ledger::class)->adjust($this->customer->id, 100, 'Old');
        app(Ledger::class)->adjust($this->customer->id, -100, 'Spent');
        $this->travel(25)->days();

        $this->assertNull(app(Ledger::class)->expiring($this->customer->id));
        $this->assertSame(0, app(ExpiryNotices::class)->send());
        $this->assertNotNull(Entry::where('note', 'Old')->firstOrFail()->expiry_warned_at, 'asked once, not every morning');

        $other = User::factory()->create();
        app(Ledger::class)->adjust($other->id, 70, 'Theirs');
        $this->travel(25)->days();
        $this->set('expiry_notice_days', 0);

        $this->assertSame(0, app(ExpiryNotices::class)->send());
        Mail::assertNothingQueued();
    }

    public function test_a_bonus_that_has_not_expired_is_not_written_off_with_one_that_has(): void
    {
        $this->set('lifetime_months', 1);
        app(Ledger::class)->adjust($this->customer->id, 100, 'Old');
        $this->travel(20)->days();
        app(Ledger::class)->bonus($this->customer->id, 30, 'A gift', 'test:gift');
        $this->travel(15)->days();

        app(Ledger::class)->expire();

        $this->assertSame(30, app(Ledger::class)->balance($this->customer->id));
    }

    public function test_a_way_of_paying_adds_points_that_wait_with_the_orders_own(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('http://localhost/admin/payments/methods/'.$this->card->id.'/edit')->assertOk()
            ->assertSee('name="points_payment_value"', false);
        $this->actingAs($admin)->put('http://localhost/admin/payments/methods/'.$this->card->id, [
            'name' => 'Card online', 'enabled' => '1',
            'points_payment_shown' => '1', 'points_payment_value' => '5', 'points_payment_mode' => 'percent',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['method' => $this->card->id, 'mode' => 'percent', 'value' => 5.0], app(Rules::class)->payment($this->card->id));

        // The customer is told beside the method, before choosing.
        $this->actingAs($this->customer)->post('http://localhost/cart/add/px-chair', ['qty' => 1]);
        $this->actingAs($this->customer)->get('http://localhost/checkout')->assertOk()
            ->assertSee(trans_choice('+:points bonus point|+:points bonus points', 100, ['points' => '100']));

        $order = $this->place($this->card);
        $row = OrderPoints::findOrFail($order->id);

        $this->assertSame(20 + 100, $row->earn, '1 for every 100, and 5% of 2 000 for the card');
        $this->assertSame(100, $row->terms['payment']['points']);
        $this->assertSame(0, app(Ledger::class)->balance($this->customer->id));

        // Paid another way after all: the card's points go, the order's own stay.
        $this->actingAs($admin)->patch('http://localhost/admin/orders/'.$order->order_number.'/payment', ['payment_method_id' => $this->cash->id]);
        $this->assertSame(20, OrderPoints::findOrFail($order->id)->earn);

        $this->actingAs($admin)->patch('http://localhost/admin/orders/'.$order->order_number.'/payment', ['payment_method_id' => $this->card->id]);
        $this->assertSame(120, OrderPoints::findOrFail($order->id)->earn);

        app(OrderStatusChanger::class)->change($order->fresh(), OrderStatus::findOrFail('complete'));
        $this->assertSame(120, app(Ledger::class)->balance($this->customer->id));
    }

    public function test_an_order_placed_keeps_what_its_way_of_paying_gave_and_a_guest_is_told_nothing(): void
    {
        DB::table('m_points_payment_methods')->insert(['payment_method_id' => $this->card->id, 'mode' => 'fixed', 'value' => 50, 'created_at' => now(), 'updated_at' => now()]);
        app(Rules::class)->flush();

        $this->post('http://localhost/cart/add/px-chair', ['qty' => 1]);
        $this->get('http://localhost/checkout')->assertOk()
            ->assertDontSee(trans_choice('+:points bonus point|+:points bonus points', 50, ['points' => '50']));

        // Signing in keeps the basket: one chair, 20 points of its own.
        $order = $this->place($this->card);
        $this->assertSame(20 + 50, OrderPoints::findOrFail($order->id)->earn);

        // The shop changes its mind: the order placed is not moved by it.
        DB::table('m_points_payment_methods')->update(['value' => 500]);
        app(Rules::class)->flush();
        app(\Torgash\Points\OrderFlow::class)->recalculated($order->fresh());

        $this->assertSame(70, OrderPoints::findOrFail($order->id)->earn);
    }

    public function test_points_are_not_spent_on_an_order_too_small(): void
    {
        $this->set('spend_from', 3000);
        app(Ledger::class)->adjust($this->customer->id, 500, 'Welcome');

        $this->actingAs($this->customer)->post('http://localhost/cart/add/px-chair', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/cart/points', ['points' => '300']);
        $this->actingAs($this->customer)->get('http://localhost/cart')->assertOk()
            ->assertSee(__('Points pay for an order from :amount.', ['amount' => format_price(3000_00)]))
            ->assertDontSee('name="points"', false);

        // Enough in the basket — and the points asked for come off.
        $this->actingAs($this->customer)->post('http://localhost/cart/add/px-chair', ['qty' => 1]);
        $this->actingAs($this->customer)->get('http://localhost/cart')->assertOk()->assertSee('name="points"', false);

        $order = $this->place($this->cash);
        $this->assertSame(300, OrderPoints::findOrFail($order->id)->spent);
    }

    public function test_a_products_page_says_how_much_points_pay_and_where_to_read_about_them(): void
    {
        $page = fn () => $this->actingAs($this->customer)->get('http://localhost/product/px-chair')->assertOk();
        $share = fn (int $percent) => __('Points pay for up to :percent% of the price', ['percent' => $percent]);

        $page()->assertSee($share(50))->assertDontSee(__('About bonus points'));

        $this->set('info_url', 'bonus-points');
        $page()->assertSee('href="http://localhost/bonus-points"', false)->assertSee(__('About bonus points'));
        $this->actingAs($this->customer)->get('http://localhost/account/points')->assertOk()->assertSee('href="http://localhost/bonus-points"', false);

        // A product points do not pay for is an exception worth saying…
        app(\Torgash\Points\ProductTerms::class)->write(Product::where('sku', 'PX-1')->value('id'), ['spend' => ['forbidden', null]]);
        app(Rules::class)->flush();
        $page()->assertSee(__('Points do not pay for this product'));

        // …the whole price is not, and the shop can keep quiet altogether.
        app(\Torgash\Points\ProductTerms::class)->write(Product::where('sku', 'PX-1')->value('id'), ['spend' => ['limit', 100]]);
        app(Rules::class)->flush();
        $page()->assertDontSee($share(100))->assertDontSee(__('Points do not pay for this product'));

        app(\Torgash\Points\ProductTerms::class)->write(Product::where('sku', 'PX-1')->value('id'), ['spend' => ['limit', 30]]);
        app(Rules::class)->flush();
        $page()->assertSee($share(30));
        $this->set('show_spend', false);
        $page()->assertDontSee($share(30));
    }

    protected function set(string $key, mixed $value): void
    {
        app(SettingsManager::class)->set('torgash.points', $key, $value);
    }

    protected function place(PaymentMethod $method): Order
    {
        $this->actingAs($this->customer)->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => $this->customer->email,
            'address_line' => '1 Street', 'city' => 'Town',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $method->id,
        ])->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }
}
