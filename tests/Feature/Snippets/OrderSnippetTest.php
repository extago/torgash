<?php

namespace Tests\Feature\Snippets;

use App\Core\Support\Currencies;
use App\Models\Currency;
use App\Models\Storefront;
use Tests\TestCase;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Snippets\Models\Snippet;
use Torgash\Snippets\Placements;

/**
 * The thank-you page is the one page a shop always wants a snippet on, and the
 * one where a snippet needs to know something: which order, for how much.
 *
 * The numbers are written for a MACHINE. `format_price()` would give "1 042.25 $"
 * — a thousands separator and a symbol, which every analytics tag ever pasted
 * into a shop chokes on. So the tokens answer with a bare decimal and the ISO
 * code beside it, and the amount is the one the customer agreed to, at the rate
 * frozen on the order.
 */
class OrderSnippetTest extends TestCase
{
    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->order = Order::create([
            'order_number' => 'TG-SN'.random_int(1000, 9999),
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
            'items' => [
                ['sku' => 'A-1', 'name' => 'Widget', 'qty' => 2, 'unit_price' => 500_00, 'line_total' => 1000_00],
            ],
        ]);
    }

    protected function snippet(string $code): Snippet
    {
        app(Placements::class)->flush();

        return Snippet::create([
            'name' => 'Ecommerce tag',
            'placement' => 'footer',
            'code' => $code,
            'enabled' => true,
            'rules_match' => 'all',
            'rules' => [['kind' => 'route', 'operator' => 'is', 'value' => 'checkout.confirmation']],
        ]);
    }

    protected function confirmation()
    {
        return $this->withSession(['last_order_number' => $this->order->order_number])
            ->get('http://localhost/checkout/'.$this->order->order_number.'/confirmation');
    }

    public function test_the_thank_you_page_can_report_the_order(): void
    {
        $this->snippet('<script>track("{order_number}", {order_total}, "{order_currency}");</script>');

        $this->confirmation()->assertOk()
            ->assertSee('track("'.$this->order->order_number.'", 1042.25, "USD");', false);
    }

    /** The lines, in the shape an ecommerce tag wants them. */
    public function test_the_items_are_available_as_json(): void
    {
        $this->snippet('<script>var items = {order_items_json};</script>');

        $this->confirmation()->assertOk()
            ->assertSee('var items = [{"sku":"A-1","name":"Widget","price":"500.00","quantity":2}];', false);
    }

    /** Charged in another currency: the tag reports what the customer agreed to. */
    public function test_the_amount_is_the_one_the_customer_was_charged(): void
    {
        Currency::updateOrCreate(['code' => 'UAH'], [
            'symbol' => '₴', 'rate' => 41, 'decimals' => 0, 'enabled' => true,
        ]);
        app(Currencies::class)->flush();

        foreach (Storefront::all() as $storefront) {
            $storefront->update(['currencies' => [...($storefront->currencies ?? []), 'UAH']]);
        }

        $this->order->update(['charge_currency' => 'UAH', 'charge_rate' => 41]);
        $this->snippet('<script>track({order_total}, "{order_currency}");</script>');

        $this->confirmation()->assertOk()->assertSee('track(42732.25, "UAH");', false);
    }

    /** ...and nowhere else, because there is no order to ask about. */
    public function test_the_tokens_are_silent_off_that_page(): void
    {
        app(Placements::class)->flush();

        Snippet::create([
            'name' => 'Everywhere', 'placement' => 'footer', 'enabled' => true, 'rules_match' => 'all',
            'code' => '<script>var n = "{order_number}";</script>',
        ]);

        $this->get('http://localhost/catalog')->assertOk()->assertSee('var n = "";', false);
    }
}
