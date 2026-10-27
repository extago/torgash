<?php

namespace Tests\Feature\Giftcards;

use App\Core\Mail\EventMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Giftcards\Issuer;
use Torgash\Giftcards\Models\Card;
use Torgash\Giftcards\Models\GiftProduct;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * Every screen a gift card has, drawn: the admin's list, issue form, card and
 * the order's panel; the card's own page; the account; the checkout summary.
 */
class GiftcardScreensTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_the_admin_issues_one_card_and_it_is_sent(): void
    {
        $this->actingAs($this->admin)->post('http://localhost/admin/giftcards', [
            'mode' => 'single',
            'amount' => '250',
            'recipient_name' => 'Olha',
            'recipient_email' => 'olha@example.test',
            'message' => 'Thank you',
        ])->assertRedirect();

        $card = Card::latest('id')->firstOrFail();
        $this->assertSame(250_00, $card->balance);
        $this->assertSame('issued', $card->source);
        $this->assertNotNull($card->delivered_at);
        Mail::assertQueued(EventMail::class, fn ($mail) => $mail->event === 'giftcards.received');

        $this->actingAs($this->admin)->get('http://localhost/admin/giftcards')->assertOk()->assertSee($card->masked());
        $this->actingAs($this->admin)->get('http://localhost/admin/giftcards/'.$card->id)->assertOk()->assertSee('Olha');
        $this->actingAs($this->admin)->get('http://localhost/admin/giftcards/'.$card->id.'/code')->assertOk()->assertSee($card->formattedCode());
        $this->actingAs($this->admin)->get('http://localhost/admin/giftcards/create')->assertOk();
    }

    public function test_a_batch_is_pending_until_activated_and_its_codes_download(): void
    {
        $this->actingAs($this->admin)->post('http://localhost/admin/giftcards', [
            'mode' => 'batch', 'amount' => '100', 'count' => 3, 'batch' => 'till-1',
        ])->assertRedirect();

        $cards = Card::where('batch', 'till-1')->get();
        $this->assertCount(3, $cards);
        $this->assertSame(['pending'], $cards->map->state()->unique()->values()->all());

        $csv = $this->actingAs($this->admin)->get('http://localhost/admin/giftcards/export?batch=till-1')->assertOk()->streamedContent();
        $this->assertStringContainsString($cards[0]->formattedCode(), $csv);

        $this->actingAs($this->admin)->post('http://localhost/admin/giftcards/'.$cards[0]->id.'/activate')->assertRedirect();
        $this->assertSame('active', $cards[0]->fresh()->state());
    }

    public function test_a_correction_needs_a_reason_and_cannot_go_below_zero(): void
    {
        [$card] = app(Issuer::class)->issue(100_00);

        $this->actingAs($this->admin)->post('http://localhost/admin/giftcards/'.$card->id.'/adjust', ['direction' => 'take', 'amount' => '30'])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post('http://localhost/admin/giftcards/'.$card->id.'/adjust', ['direction' => 'take', 'amount' => '300', 'reason' => 'x'])
            ->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->post('http://localhost/admin/giftcards/'.$card->id.'/adjust', ['direction' => 'take', 'amount' => '30', 'reason' => 'Damaged goods compensation reversed']);

        $this->assertSame(70_00, $card->fresh()->balance);
    }

    public function test_a_manager_puts_a_card_on_a_placed_order_and_takes_it_off(): void
    {
        $order = $this->placeOrder();
        [$card, $code] = app(Issuer::class)->issue(300_00);

        $this->actingAs($this->admin)->post('http://localhost/admin/giftcards/orders/'.$order->order_number.'/attach', ['code' => $code])
            ->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(300_00, $order->prepaid_total);
        $this->assertSame(0, $card->fresh()->balance);

        $this->actingAs($this->admin)->get('http://localhost/admin/orders/'.$order->order_number)->assertOk()->assertSee($card->masked());

        $this->actingAs($this->admin)->post('http://localhost/admin/giftcards/orders/'.$order->order_number.'/detach/'.$card->id);
        $this->assertSame(0, $order->fresh()->prepaid_total);
        $this->assertSame(300_00, $card->fresh()->balance);
    }

    public function test_the_cards_page_and_the_account(): void
    {
        [$card, $code] = app(Issuer::class)->issue(400_00, ['sender_name' => 'Grandma', 'message' => 'For books']);

        $this->get('http://localhost/gift/'.$card->public_token)->assertOk()->assertSee('Grandma')->assertSee('For books');
        $this->get('http://localhost/gift/'.str_repeat('a', 40))->assertNotFound();

        $customer = User::factory()->create();
        $this->actingAs($customer)->post('http://localhost/account/gift-cards', ['code' => $code])->assertSessionHasNoErrors();
        $this->assertSame($customer->id, $card->fresh()->owner_user_id);

        $this->actingAs($customer)->get('http://localhost/account/gift-cards')->assertOk()->assertSee($card->masked());
    }

    public function test_the_checkout_summary_shows_the_card_and_what_is_left_to_pay(): void
    {
        Product::create(['sku' => 'GS-1', 'name' => ['en' => 'Lamp'], 'slug' => 'gs-lamp', 'price' => 900_00, 'stock' => 5, 'status' => 'active']);
        [$card, $code] = app(Issuer::class)->issue(200_00);

        $this->post('http://localhost/cart/add/gs-lamp', ['qty' => 1]);
        $this->post('http://localhost/cart/coupon', ['code' => $code]);

        $this->get('http://localhost/cart')->assertOk()->assertSee($card->masked());
        $this->get('http://localhost/checkout')->assertOk()->assertSee($card->masked())->assertSee(__('To pay'));
    }

    public function test_a_gift_card_product_page_asks_for_the_amount(): void
    {
        $product = Product::create(['sku' => 'GS-GIFT', 'name' => ['en' => 'Present'], 'slug' => 'gs-present', 'price' => 0, 'status' => 'active', 'availability_status' => 'unlimited']);
        GiftProduct::create(['product_id' => $product->id, 'free_amount' => true]);

        $this->get('http://localhost/product/gs-present')->assertOk()->assertSee('config[giftcard][amount]', false);

        $this->actingAs($this->admin)->get('http://localhost/admin/catalog/products/'.$product->id.'/edit')->assertOk()->assertSee('giftcard_form', false)
            // A section of the shared "Modules" tab, not a tab of its own.
            ->assertSee('data-module-section="giftcard"', false)
            ->assertDontSee("tab = 'giftcard'", false)
            ->assertSee("tab = 'modules'", false);
    }

    protected function placeOrder(): Order
    {
        Product::create(['sku' => 'GS-2', 'name' => ['en' => 'Chair'], 'slug' => 'gs-chair', 'price' => 900_00, 'stock' => 5, 'status' => 'active']);
        $cash = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => 91]);

        $this->post('http://localhost/cart/add/gs-chair', ['qty' => 1]);
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test', 'address_line' => '1 Street', 'city' => 'Town',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $cash->id,
        ])->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }
}
