<?php

namespace Tests\Feature\Giftcards;

use App\Core\Mail\EventMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderChanges;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Checkout\Orders\RecordPayment;
use Torgash\Giftcards\Delivery;
use Torgash\Giftcards\Issuer;
use Torgash\Giftcards\Mail\GiftcardLetters;
use Torgash\Giftcards\Models\Card;
use Torgash\Giftcards\Models\GiftProduct;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * A gift card sold as a product: a fixed amount or a typed one, for oneself or
 * for somebody else on a chosen day — issued once the order is PAID, never
 * before, and never twice.
 */
class GiftcardSaleTest extends TestCase
{
    protected Product $card;

    protected PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->card = Product::create([
            'sku' => 'GIFT', 'name' => ['en' => 'Gift card'], 'slug' => 'gift-card',
            'price' => 0, 'stock' => 0, 'status' => 'active', 'availability_status' => 'unlimited',
        ]);
        GiftProduct::create(['product_id' => $this->card->id, 'free_amount' => true, 'min_amount' => 100_00, 'max_amount' => 5000_00]);

        $this->cash = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => 91]);
    }

    public function test_a_typed_amount_is_what_the_line_costs(): void
    {
        $this->post('http://localhost/cart/add/gift-card', ['qty' => 1, 'config' => ['giftcard' => ['amount' => '1 500']]])->assertOk();

        $line = app(\Torgash\Cart\CartManager::class)->items()->first();

        $this->assertSame(1500_00, $line->unit_price);
        $this->assertSame(['amount' => 1500_00], $line->config['giftcard']);
    }

    public function test_an_amount_outside_the_limits_is_refused(): void
    {
        $this->post('http://localhost/cart/add/gift-card', ['qty' => 1, 'config' => ['giftcard' => ['amount' => '50']]]);

        $this->assertSame([], app(\Torgash\Cart\CartManager::class)->raw());
    }

    public function test_a_card_is_issued_when_the_order_is_paid_and_not_before(): void
    {
        $order = $this->buy(['amount' => '700', 'to_name' => 'Anna', 'to_email' => 'anna@example.test', 'message' => 'Happy birthday'], qty: 2);

        $this->assertSame(0, Card::count(), 'an unpaid order is not a working code');

        app(RecordPayment::class)->record($order, PaymentState::PAID);
        app(OrderChanges::class)->flush();

        $cards = Card::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $cards);
        $this->assertSame([700_00, 700_00], $cards->pluck('initial_amount')->all());
        $this->assertSame('anna@example.test', $cards[0]->recipient_email);
        $this->assertSame('Happy birthday', $cards[0]->message);
        $this->assertNotNull($cards[0]->delivered_at);
        $this->assertSame(now()->addMonthsNoOverflow(12)->toDateString(), $cards[0]->expires_at->toDateString());

        Mail::assertQueued(EventMail::class, fn ($mail) => $mail->event === GiftcardLetters::RECEIVED);
        Mail::assertQueued(EventMail::class, fn ($mail) => $mail->event === GiftcardLetters::PURCHASED);

        // Paid again (a second callback, a manager's edit): nothing more.
        $order->update(['notes' => 'touched']);
        app(OrderChanges::class)->flush();
        $this->assertSame(2, Card::where('order_id', $order->id)->count());
    }

    public function test_a_card_for_a_date_waits_for_it(): void
    {
        $order = $this->buy(['amount' => '300', 'to_email' => 'later@example.test', 'deliver_on' => now()->addDays(3)->toDateString()]);

        app(RecordPayment::class)->record($order, PaymentState::PAID);
        app(OrderChanges::class)->flush();

        $card = Card::where('order_id', $order->id)->firstOrFail();
        $this->assertNull($card->delivered_at);

        $this->travel(4)->days();
        $this->assertSame(1, app(Delivery::class)->sendDue());
        $this->assertNotNull($card->fresh()->delivered_at);
    }

    public function test_a_card_bought_for_oneself_is_the_buyers(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $order = $this->buy(['amount' => '300']);
        app(RecordPayment::class)->record($order, PaymentState::PAID);
        app(OrderChanges::class)->flush();

        $this->assertSame($user->id, Card::where('order_id', $order->id)->value('owner_user_id'));
    }

    public function test_a_refunded_sale_cancels_what_is_left_on_the_card(): void
    {
        $order = $this->buy(['amount' => '300']);
        app(RecordPayment::class)->record($order, PaymentState::PAID);
        app(OrderChanges::class)->flush();

        app(\Torgash\Checkout\Orders\Refund::class)->refund($order->fresh(), $order->fresh()->paid_total);
        app(OrderChanges::class)->flush();

        $card = Card::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(0, $card->balance);
        $this->assertSame('disabled', $card->state());
    }

    public function test_a_coupon_never_discounts_a_gift_card(): void
    {
        \Torgash\Checkout\Models\Coupon::create(['code' => 'TEN', 'type' => 'percent', 'value' => 10, 'enabled' => true]);

        $this->post('http://localhost/cart/add/gift-card', ['qty' => 1, 'config' => ['giftcard' => ['amount' => '1000']]]);
        $this->post('http://localhost/cart/coupon', ['code' => 'TEN']);

        $this->assertSame(0, app(\Torgash\Checkout\CouponManager::class)->discount());
    }

    public function test_a_gift_card_cannot_pay_for_another_by_default(): void
    {
        [, $code] = app(Issuer::class)->issue(5000_00);

        $this->post('http://localhost/cart/add/gift-card', ['qty' => 1, 'config' => ['giftcard' => ['amount' => '1000']]]);
        $this->post('http://localhost/cart/coupon', ['code' => $code]);

        $totals = app(\Torgash\Checkout\Totals::class);
        $this->assertSame(0, $totals->prepaid(app(\Torgash\Cart\CartManager::class), 1000_00, 0));
    }

    public function test_the_product_form_tab_makes_a_product_a_gift_card(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $product = Product::create(['sku' => 'GIFT-2', 'name' => ['en' => 'Gift 500'], 'slug' => 'gift-500', 'price' => 500_00, 'status' => 'active']);

        hook_action('catalog.product.saved', ['product' => $product, 'request' => request()->merge([
            'giftcard_form' => '1', 'giftcard' => ['is' => '1', 'free_amount' => '0'],
        ])]);

        $this->assertTrue(GiftProduct::whereKey($product->id)->exists());
        $this->assertFalse(GiftProduct::find($product->id)->free_amount);
    }

    // ---------------------------------------------------------------- helpers

    protected function buy(array $answers, int $qty = 1): Order
    {
        $this->post('http://localhost/cart/add/gift-card', ['qty' => $qty, 'config' => ['giftcard' => $answers]])->assertOk();

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $this->cash->id,
        ])->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }
}
