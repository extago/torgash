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
 * What the BANK takes is a third currency again.
 *
 * The shop's books are dollars, the customer was quoted in dollars — and the
 * only merchant account the shop has is in hryvnia. Nothing before this could
 * express that: the gateway was handed the order's own currency and the bank
 * refused it, at the last step, which is the one a customer never comes back
 * from.
 *
 * So a payment method says which currencies it accepts and which one it charges
 * in otherwise, the choice is frozen onto the order at placement, and the order
 * goes on being printed in the currency the customer agreed to.
 */
class PaymentCurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Currency::updateOrCreate(['code' => 'UAH'], [
            'symbol' => '₴', 'rate' => 41, 'decimals' => 0, 'enabled' => true,
        ]);
        app(Currencies::class)->flush();

        foreach (Storefront::all() as $storefront) {
            $storefront->update(['currencies' => [...($storefront->currencies ?? []), 'UAH']]);
        }
    }

    /** The one the shop is actually set up as: dollar prices, hryvnia acquiring. */
    public function test_a_method_that_takes_only_hryvnia_charges_a_dollar_order_in_hryvnia(): void
    {
        $method = $this->method(['currencies' => ['UAH'], 'charge_currency' => 'UAH']);
        $order = $this->place($method);

        // The books and the quote are untouched — the customer agreed to
        // dollars and the shop reports in dollars.
        $this->assertSame('USD', $order->currency_code);
        $this->assertNull($order->charge_currency);

        // ...and the bank is asked for hryvnia, at the rate frozen right here.
        $this->assertSame('UAH', $order->payment_currency);
        $this->assertSame(41.0, $order->payment_rate);
        // The goods plus the delivery, in kopecks: $15.00 × 41.
        $this->assertSame(1500, $order->total);
        $this->assertSame(615_00, $order->chargeMinor());
        // Which reads back as the order's own total, not a fortyfold overpayment.
        $this->assertSame($order->total, $order->baseFromCharge(615_00));
    }

    /**
     * The amount and the currency handed to a gateway come from the same axis.
     *
     * They did not, once: the amount was converted into the payment currency
     * and the code beside it was still the one the customer was quoted in — so
     * a gateway was handed 42 702 hryvnia labelled "USD", and only refused it
     * because of an unrelated rule of its own. Nothing on any screen showed it.
     */
    public function test_the_gateway_is_handed_the_amount_and_the_currency_of_the_same_axis(): void
    {
        $order = $this->place($this->method(['currencies' => ['UAH'], 'charge_currency' => 'UAH']));

        $charge = \Torgash\Payments\Charge::for($order);

        $this->assertSame('UAH', $charge->currency);
        $this->assertSame(615_00, $charge->amount);
        // ...and back the other way, into the shop's own books.
        $this->assertSame($order->total, $charge->toBase($order));
    }

    /** The order is still printed in dollars, with one line saying what will be taken. */
    public function test_the_confirmation_prints_the_order_in_its_own_currency_and_the_payment_in_the_banks(): void
    {
        $order = $this->place($this->method(['currencies' => ['UAH'], 'charge_currency' => 'UAH']));

        $this->withSession(['last_order_number' => $order->order_number])
            ->get('http://localhost/checkout/'.$order->order_number.'/confirmation')
            ->assertOk()
            ->assertSee("15.00\u{00A0}$")
            ->assertSee('Payment is taken in UAH')
            ->assertSee("615\u{00A0}₴");
    }

    /**
     * A method that can take neither is not a choice.
     *
     * Both halves matter: it is off the screen, and a request naming it anyway
     * is refused — the same rule the shipping options have, for the same reason.
     */
    public function test_a_method_that_cannot_take_the_currency_is_not_offered(): void
    {
        $method = $this->method(['currencies' => ['UAH'], 'charge_currency' => null]);
        PaymentMethod::whereKeyNot($method->id)->update(['enabled' => false]);

        $this->cart();

        $this->get('http://localhost/checkout')
            ->assertOk()
            ->assertDontSee($method->t('name'));

        $this->post('http://localhost/checkout', $this->form($method))
            ->assertSessionHasErrors('payment_method_id');

        $this->assertNull(Order::latest('id')->first());
    }

    /** ...and it IS offered to somebody shopping in the currency it takes. */
    public function test_the_same_method_is_offered_in_the_currency_it_accepts(): void
    {
        $method = $this->method(['currencies' => ['UAH'], 'charge_currency' => null]);

        $this->withSession(['currency' => 'UAH'])->get('http://localhost/checkout');
        $this->cart();

        $this->withSession(['currency' => 'UAH'])
            ->get('http://localhost/checkout')
            ->assertOk()
            ->assertSee($method->t('name'));

        $order = $this->place($method, ['currency' => 'UAH']);

        // Quoted in hryvnia and paid in hryvnia: nothing to convert, so nothing
        // is written down about a payment currency at all.
        $this->assertSame('UAH', $order->charge_currency);
        $this->assertNull($order->payment_currency);
        $this->assertFalse($order->paymentDiffers());
    }

    /** The reverse case: quoted in hryvnia, but the acquiring only takes dollars. */
    public function test_an_order_quoted_in_hryvnia_can_be_charged_in_the_books_currency(): void
    {
        $method = $this->method(['currencies' => ['USD'], 'charge_currency' => 'USD']);
        $order = $this->place($method, ['currency' => 'UAH']);

        $this->assertSame('UAH', $order->charge_currency);
        $this->assertSame('USD', $order->payment_currency);
        // Charged in the books' own currency, so the stored amount goes to the
        // bank untouched — no conversion, and no rounding of one either.
        $this->assertSame($order->total, $order->chargeMinor());
    }

    /**
     * An order placed BEFORE the shop said which currencies its methods take.
     *
     * It has nothing frozen on it, and the method it was sold under can only
     * take hryvnia — so the page must say hryvnia rather than offer a Pay
     * button for an amount the acquirer will refuse. Worked out from the method
     * the order carries the name of.
     */
    public function test_an_order_from_before_the_setting_is_read_through_its_method(): void
    {
        $method = $this->method();
        $order = $this->place($method);

        // ...and only afterwards does the shop discover what its bank takes.
        $method->update(['currencies' => ['UAH'], 'charge_currency' => 'UAH']);

        $order = $order->fresh();

        $this->assertNull($order->payment_currency);
        $this->assertTrue($order->paymentDiffers());
        $this->assertSame('UAH', $order->paymentCurrency());
        $this->assertSame(615_00, $order->chargeMinor());

        $this->withSession(['last_order_number' => $order->order_number])
            ->get('http://localhost/checkout/'.$order->order_number.'/confirmation')
            ->assertOk()
            ->assertSee('Payment is taken in UAH')
            ->assertSee("615\u{00A0}₴");
    }

    /**
     * ...and the live answer stops being live the moment a payment starts: it
     * is written down before anybody is charged, so a rate that moves overnight
     * does not move a bill the customer has already been shown.
     */
    public function test_starting_a_payment_freezes_what_was_being_shown(): void
    {
        $method = $this->method();
        $order = $this->place($method);
        $method->update(['currencies' => ['UAH'], 'charge_currency' => 'UAH']);

        app(\Torgash\Checkout\Orders\PaymentCurrency::class)->freeze($order = $order->fresh());

        $this->assertSame('UAH', $order->fresh()->payment_currency);
        $this->assertSame(41.0, $order->fresh()->payment_rate);

        Currency::where('code', 'UAH')->firstOrFail()->update(['rate' => 50]);
        app(Currencies::class)->flush();

        // Still yesterday's rate, because that is what was agreed.
        $this->assertSame(615_00, $order->fresh()->chargeMinor());
    }

    /** Nothing is said, and nothing is stored, when one currency does everything. */
    public function test_the_ordinary_case_writes_nothing_down(): void
    {
        $order = $this->place($this->method());

        $this->assertNull($order->payment_currency);
        $this->assertNull($order->payment_rate);
        $this->assertSame($order->money($order->total), $order->paymentMoney($order->total));
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string, mixed> $attributes */
    protected function method(array $attributes = []): PaymentMethod
    {
        return PaymentMethod::create($attributes + [
            'name' => ['en' => 'Card via a hryvnia account'],
            'enabled' => true,
            'position' => 99,
        ]);
    }

    protected function cart(): void
    {
        Product::firstOrCreate(['sku' => 'PAYCUR-1'], [
            'name' => ['en' => 'Payable widget'], 'slug' => 'payable-widget',
            'price' => 10_00, 'stock' => 5, 'status' => 'active',
        ]);

        $this->post('http://localhost/cart/add/payable-widget', ['qty' => 1])->assertOk();
    }

    /** @return array<string, mixed> */
    protected function form(PaymentMethod $method): array
    {
        return [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Kyiv',
            'country' => 'UA',
            'payment_method_id' => $method->id,
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
        ];
    }

    /** @param array{currency?: string} $options */
    protected function place(PaymentMethod $method, array $options = []): Order
    {
        $session = isset($options['currency']) ? ['currency' => $options['currency']] : [];

        $this->withSession($session);
        $this->cart();

        $this->withSession($session)
            ->post('http://localhost/checkout', $this->form($method))
            ->assertRedirect();

        return Order::latest('id')->firstOrFail();
    }
}
