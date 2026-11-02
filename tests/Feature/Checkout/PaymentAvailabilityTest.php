<?php

namespace Tests\Feature\Checkout;

use App\Core\Settings\SettingsManager;
use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * Whom a payment method is offered to (PLAN §66, stage 3): the buyer (a
 * person or a company), the customer's group, the delivery. The checkout
 * leaves the others out and refuses them on submit; a manager is only warned.
 */
class PaymentAvailabilityTest extends TestCase
{
    protected PaymentMethod $cash;

    protected PaymentMethod $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        app(SettingsManager::class)->set('torgash.checkout', 'business_orders', true);

        Product::create(['sku' => 'PA-1', 'name' => ['en' => 'Shelf'], 'slug' => 'pa-shelf', 'price' => 500_00, 'stock' => 9, 'status' => 'active']);

        $this->cash = PaymentMethod::create(['name' => ['en' => 'Cash for anybody'], 'enabled' => true, 'position' => -2]);
        $this->invoice = PaymentMethod::create(['name' => ['en' => 'Invoice for companies'], 'enabled' => true, 'position' => -1, 'buyers' => 'company']);
    }

    public function test_the_rules(): void
    {
        $method = PaymentMethod::make(['buyers' => 'company', 'customer_groups' => ['group:7'], 'shipping_methods' => ['3']]);

        $this->assertSame([], $method->refusals('company', ['registered', 'group:7'], '3:branch'));
        $this->assertCount(1, $method->refusals('person', ['registered', 'group:7'], '3'));
        $this->assertCount(1, $method->refusals(null, ['registered', 'group:7'], '3'), 'no answer is a person');
        $this->assertCount(1, $method->refusals('company', ['guest'], '3'));
        $this->assertCount(1, $method->refusals('company', ['registered', 'group:7'], '4'));
        $this->assertCount(1, $method->refusals('company', ['registered', 'group:7'], '3', needsDelivery: false), 'nothing sent is no delivery named');
        $this->assertSame([], PaymentMethod::make([])->refusals(null, ['guest'], null, false), 'nothing narrowed is everybody');
    }

    public function test_a_company_method_appears_when_the_buyer_is_a_company(): void
    {
        $this->post('http://localhost/cart/add/pa-shelf', ['qty' => 1])->assertOk();

        $this->get('http://localhost/checkout')->assertOk()
            ->assertSee('Cash for anybody')->assertDontSee('Invoice for companies');

        // Ticking "Company" re-quotes, and the list comes back with it.
        $this->post('http://localhost/checkout/quote', ['buyer_type' => 'company', 'city' => 'Town', 'payment_method_id' => $this->invoice->id])->assertOk()
            ->assertSee('id="checkout-payments"', false)
            ->assertSee('Invoice for companies')
            ->assertSee('id="checkout-payment-'.$this->invoice->id.'" name="payment_method_id" value="'.$this->invoice->id.'" class="radio radio-primary"'."\n".'                   checked', false);

        // Back to a person: the invoice goes, and the cash is ticked instead.
        $this->post('http://localhost/checkout/quote', ['buyer_type' => 'person', 'city' => 'Town', 'payment_method_id' => $this->invoice->id])->assertOk()
            ->assertDontSee('Invoice for companies');
    }

    public function test_the_submit_refuses_a_method_not_offered(): void
    {
        $this->post('http://localhost/cart/add/pa-shelf', ['qty' => 1])->assertOk();

        $this->post('http://localhost/checkout', $this->form(['payment_method_id' => $this->invoice->id]))
            ->assertSessionHasErrors('payment_method_id');

        $this->post('http://localhost/checkout', $this->form([
            'payment_method_id' => $this->invoice->id, 'buyer_type' => 'company', 'company_name' => 'Acme LLC',
        ]))->assertSessionHasNoErrors();
        $this->assertSame($this->invoice->id, Order::latest('id')->value('payment_method_id'));
    }

    public function test_a_method_for_one_delivery_and_one_group(): void
    {
        $shipping = $this->shippingId();
        $courier = PaymentMethod::create(['name' => ['en' => 'Cash to our courier'], 'enabled' => true, 'shipping_methods' => ['999999']]);
        $wholesale = CustomerGroup::create(['name' => ['en' => 'Wholesale']]);
        $deferred = PaymentMethod::create(['name' => ['en' => 'Deferred for wholesale'], 'enabled' => true, 'customer_groups' => ['group:'.$wholesale->id]]);

        $this->post('http://localhost/cart/add/pa-shelf', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout/quote', ['city' => 'Town', 'shipping_method_id' => $shipping])->assertOk()
            ->assertDontSee('Cash to our courier')->assertDontSee('Deferred for wholesale');

        $courier->update(['shipping_methods' => [explode(':', $shipping)[0]]]);
        $this->post('http://localhost/checkout/quote', ['city' => 'Town', 'shipping_method_id' => $shipping])->assertOk()
            ->assertSee('Cash to our courier');

        $buyer = User::create(['name' => 'Wholesaler', 'email' => 'w@example.test', 'password' => 'secret123', 'customer_group_id' => $wholesale->id]);
        $this->actingAs($buyer)->post('http://localhost/checkout/quote', ['city' => 'Town', 'shipping_method_id' => $shipping])->assertOk()
            ->assertSee('Deferred for wholesale');
    }

    public function test_a_manager_may_use_it_and_is_warned(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('http://localhost/admin/orders/create')->assertOk()
            ->assertSee('Invoice for companies')->assertSee(__('At the checkout: :what', ['what' => __('Companies only')]));

        $this->actingAs($admin)->post('http://localhost/admin/orders', [
            'customer_name' => 'A Person', 'email' => 'person@example.test', 'phone' => '+380931234567',
            'country' => 'UA', 'city' => 'Kyiv', 'address_line' => '1 Lane', 'postal_code' => '01001',
            'lines' => [['product_id' => Product::where('sku', 'PA-1')->value('id'), 'qty' => 1, 'unit_price' => '']],
            'shipping_method_id' => $this->shippingId(),
            'payment_method_id' => $this->invoice->id,
        ])->assertSessionHasNoErrors();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame($this->invoice->id, $order->payment_method_id);

        $this->actingAs($admin)->get('http://localhost/admin/orders/'.$order->order_number)->assertOk()
            ->assertSee(__('This payment method is for companies only.'));
    }

    public function test_the_admin_form_saves_it(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $shipping = explode(':', $this->shippingId())[0];

        $this->actingAs($admin)->get('http://localhost/admin/payments/methods/'.$this->cash->id.'/edit')->assertOk()->assertSee(__('Who it is offered to'));

        $this->actingAs($admin)->put('http://localhost/admin/payments/methods/'.$this->cash->id, [
            'name' => 'Cash for anybody', 'enabled' => '1', 'availability_set' => '1',
            'buyers' => 'person', 'customer_groups' => ['guest', 'registered'], 'shipping_methods' => [$shipping],
        ])->assertSessionHasNoErrors();

        $fresh = $this->cash->fresh();
        $this->assertSame(['person', ['guest', 'registered'], [$shipping]], [$fresh->buyers, $fresh->customer_groups, $fresh->shipping_methods]);

        // A form without the card keeps it; the card emptied is everybody again.
        $this->actingAs($admin)->put('http://localhost/admin/payments/methods/'.$this->cash->id, ['name' => 'Cash for anybody', 'enabled' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('person', $this->cash->fresh()->buyers);
        $this->actingAs($admin)->put('http://localhost/admin/payments/methods/'.$this->cash->id, ['name' => 'Cash for anybody', 'enabled' => '1', 'availability_set' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(['all', null, null], [$this->cash->fresh()->buyers, $this->cash->fresh()->customer_groups, $this->cash->fresh()->shipping_methods]);
    }

    protected function shippingId(): string
    {
        return (string) ShippingMethod::whereNull('provider')->where('enabled', true)->orderBy('id')->value('id');
    }

    /** @return array<string, mixed> */
    protected function form(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Buyer', 'email' => 'pa@example.test', 'address_line' => '1 Street', 'city' => 'Town',
            'shipping_method_id' => $this->shippingId(),
        ];
    }
}
