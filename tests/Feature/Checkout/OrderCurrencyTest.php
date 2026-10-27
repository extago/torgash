<?php

namespace Tests\Feature\Checkout;

use App\Core\Support\Currencies;
use App\Models\Currency;
use App\Models\Storefront;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * The shop's books and the customer's money are two different currencies.
 *
 * A merchant keeps prices in dollars because that is how the goods are bought;
 * the customer switches the storefront to hryvnia and pays hryvnia, which is
 * also the only currency the acquiring takes. So an order is STORED in the
 * shop's own currency (its reports must not move because somebody used a
 * switcher) and CHARGED in the one the customer was shopping in, at the rate
 * frozen onto it.
 *
 * Frozen is the word that matters: an invoice reprinted next month has to show
 * the number the customer agreed to, not today's rate applied to yesterday's
 * order.
 */
class OrderCurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Currency::updateOrCreate(['code' => 'UAH'], [
            'symbol' => '₴', 'rate' => 41, 'decimals' => 0, 'enabled' => true,
        ]);
        app(Currencies::class)->flush();

        // The switcher only honours currencies the STOREFRONT offers — a code
        // in the session that this shop does not sell in is ignored, which is
        // also what stops a hand-edited cookie renaming everybody's prices.
        // Resolved from the table, not from current_storefront(): outside a
        // request there is no current one yet.
        foreach (Storefront::all() as $storefront) {
            $storefront->update(['currencies' => [...($storefront->currencies ?? []), 'UAH']]);
        }
    }

    /** Placed while browsing hryvnia: quoted, printed and charged in hryvnia. */
    public function test_an_order_is_charged_in_the_currency_the_customer_was_shopping_in(): void
    {
        $order = $this->order(['charge_currency' => 'UAH', 'charge_rate' => 41]);

        $this->withSession([
            'last_order_number' => $order->order_number,
            'currency' => 'UAH',
        ])->get('http://localhost/checkout/'.$order->order_number.'/confirmation')
            ->assertOk()
            // 1042.25 × 41, in whole hryvnia as this shop shows them.
            ->assertSee("42\u{00A0}732\u{00A0}₴")
            ->assertDontSee("1\u{00A0}042.25\u{00A0}$");

        // And the bank is asked for exactly that, in kopecks.
        $this->assertSame(4273200, $order->chargeMinor());
        // ...which reads back as the order's own total, so a callback about it
        // is not a fortyfold overpayment.
        $this->assertSame(1042_25, $order->baseFromCharge(4273200));
    }

    /** The rate is the one at the time, not today's: a reprint must match the receipt. */
    public function test_the_rate_is_frozen_onto_the_order(): void
    {
        $order = $this->order(['charge_currency' => 'UAH', 'charge_rate' => 41]);

        Currency::where('code', 'UAH')->update(['rate' => 50]);
        app(Currencies::class)->flush();

        $this->withSession(['last_order_number' => $order->order_number, 'currency' => 'UAH'])
            ->get('http://localhost/checkout/'.$order->order_number.'/confirmation')
            ->assertOk()
            ->assertSee("42\u{00A0}732\u{00A0}₴")
            ->assertDontSee("52\u{00A0}112\u{00A0}₴");
    }

    /** Switching afterwards changes nothing, and the page says which currency it is in. */
    public function test_switching_the_header_afterwards_does_not_restate_the_debt(): void
    {
        $order = $this->order(['charge_currency' => 'UAH', 'charge_rate' => 41]);

        $this->withSession([
            'last_order_number' => $order->order_number,
            'currency' => 'USD',
        ])->get('http://localhost/checkout/'.$order->order_number.'/confirmation')
            ->assertOk()
            ->assertSee("42\u{00A0}732\u{00A0}₴")
            ->assertSee('This order is in UAH')
            // ...and what that is worth today, marked as today's and never as
            // the amount owed.
            ->assertSee('at today&#039;s rate', false);
    }

    /**
     * End to end: what the customer had switched the shop to is what the order
     * is charged in, frozen at that moment's rate.
     */
    public function test_placing_an_order_freezes_the_currency_the_customer_chose(): void
    {
        Product::create([
            'sku' => 'CUR-1', 'name' => ['en' => 'Priced widget'], 'slug' => 'priced-widget',
            'price' => 10_00, 'stock' => 5, 'status' => 'active',
        ]);

        $this->withSession(['currency' => 'UAH'])
            ->post('http://localhost/cart/add/priced-widget', ['qty' => 1])->assertOk();

        $this->withSession(['currency' => 'UAH'])->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Kyiv',
            'country' => 'UA',
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
        ])->assertRedirect();

        $order = Order::latest('id')->firstOrFail();

        // The books stay in the shop's own currency; the charge does not.
        $this->assertSame('USD', $order->currency_code);
        $this->assertSame('UAH', $order->charge_currency);
        $this->assertSame(41.0, $order->charge_rate);
    }

    /** The ordinary case says nothing: one currency, no arithmetic to explain. */
    public function test_nothing_is_said_when_there_is_nothing_to_explain(): void
    {
        $order = $this->order();

        $this->withSession(['last_order_number' => $order->order_number])
            ->get('http://localhost/checkout/'.$order->order_number.'/confirmation')
            ->assertOk()
            ->assertSee("1\u{00A0}042.25\u{00A0}$")
            ->assertDontSee('This order is in')
            ->assertDontSee('Payment is taken in');
    }

    /** @param array<string, mixed> $overrides */
    protected function order(array $overrides = []): Order
    {
        return Order::create($overrides + [
            'order_number' => 'TG-CU'.random_int(1000, 9999),
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'currency_code' => 'USD',
            'subtotal' => 1042_25,
            'shipping_cost' => 0,
            'total' => 1042_25,
            'status' => 'new',
            'payment_method_name' => PaymentMethod::where('enabled', true)->orderBy('id')->firstOrFail()->t('name'),
            'payment_state' => PaymentState::PENDING,
            'items' => [],
        ]);
    }
}
