<?php

namespace Tests\Feature\Checkout;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Automations\Context;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\CheckoutField;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * The shop's own questions at checkout (Models\CheckoutField,
 * Orders\CheckoutFields): asked where placed, of the buyers and deliveries
 * they name, and kept on the order as a list — read by the order's card,
 * its letter, an automation and the API, never written into its notes.
 */
class OwnCheckoutFieldsTest extends TestCase
{
    protected PaymentMethod $payment;

    protected string $delivered;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->payment = PaymentMethod::create(['name' => ['en' => 'Invoice'], 'enabled' => true, 'position' => 99]);
        $this->delivered = (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id');

        Product::create(['sku' => 'OCF-1', 'name' => ['en' => 'Roses'], 'slug' => 'ocf-roses', 'price' => 10_00, 'stock' => 5, 'status' => 'active']);
        $this->post('http://localhost/cart/add/ocf-roses', ['qty' => 1])->assertOk();
    }

    /** @return array<string, mixed> */
    protected function form(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Olena',
            'email' => 'olena@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Kyiv',
            'country' => 'UA',
            'postal_code' => '01001',
            'payment_method_id' => $this->payment->id,
            'shipping_method_id' => $this->delivered,
        ];
    }

    protected function field(array $attributes): CheckoutField
    {
        return CheckoutField::create($attributes + ['type' => 'text', 'place' => 'recipient', 'buyer' => 'all', 'enabled' => true]);
    }

    public function test_an_answer_is_kept_on_the_order_and_read_everywhere(): void
    {
        $this->field(['code' => 'recipient', 'label' => ['en' => 'Who takes it'], 'help' => ['en' => 'For the courier'], 'required' => true]);

        $this->get('http://localhost/checkout')->assertOk()
            ->assertSee('name="fields[recipient]"', false)->assertSee('Who takes it')->assertSee('For the courier');

        $this->post('http://localhost/checkout', $this->form())->assertSessionHasErrors('fields.recipient');

        $this->post('http://localhost/checkout', $this->form(['fields' => ['recipient' => 'Anna, the neighbour'], 'notes' => 'Ring twice']))->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame([['code' => 'recipient', 'label' => 'Who takes it', 'value' => 'Anna, the neighbour']], $order->fields);
        $this->assertSame('Ring twice', $order->notes, 'the notes stay the customer\'s own words');

        $this->assertStringContainsString('Anna, the neighbour', view('torgash.checkout::mail.order-details', ['order' => $order])->render());
        $context = Context::forOrder($order, 'test');
        $this->assertSame('Anna, the neighbour', $context['order']['fields']['recipient']);
        $this->assertSame('Who takes it: Anna, the neighbour', $context['order']['fields_text']);

        $this->actingAs(User::role('admin')->firstOrFail())->get(route('admin.orders.show', $order->order_number))->assertOk()
            ->assertSee('Anna, the neighbour');
    }

    public function test_a_question_for_one_delivery_is_neither_required_nor_kept_for_another(): void
    {
        $courier = ShippingMethod::create(['name' => ['en' => 'OCF courier'], 'enabled' => true, 'position' => 99]);
        $this->field(['code' => 'hand_to', 'label' => ['en' => 'Hand it to'], 'required' => true, 'place' => 'delivery', 'shipping_methods' => [(string) $courier->id]]);

        // Collected: not asked, so not required — and a stray answer is not kept.
        $this->post('http://localhost/checkout', $this->form(['fields' => ['hand_to' => 'stale']]))->assertRedirect();
        $this->assertNull(Order::latest('id')->firstOrFail()->fields);

        $field = CheckoutField::where('code', 'hand_to')->firstOrFail();
        $this->assertTrue($field->appliesTo(null, $courier->id.':door'), 'an offer of the method counts as the method');
        $this->assertFalse($field->appliesTo(null, $this->delivered));
        $this->assertFalse($field->appliesTo(null, $courier->id.':door', needsDelivery: false));
    }

    public function test_a_company_question_is_asked_of_a_company(): void
    {
        app(SettingsManager::class)->set('torgash.checkout', 'business_orders', true);
        $this->field(['code' => 'iban', 'label' => ['en' => 'IBAN'], 'required' => true, 'buyer' => 'company']);

        $this->post('http://localhost/checkout', $this->form())->assertRedirect();
        $this->assertNull(Order::latest('id')->firstOrFail()->fields);

        $this->post('http://localhost/cart/add/ocf-roses', ['qty' => 1]);
        $this->post('http://localhost/checkout', $this->form(['buyer_type' => 'company', 'company_name' => 'Acme']))
            ->assertSessionHasErrors('fields.iban');
    }

    public function test_a_choice_is_kept_in_the_base_language_whatever_the_customers(): void
    {
        $this->field(['code' => 'found_us', 'type' => 'radio', 'label' => ['en' => 'How did you find us?', 'uk' => 'Як ви нас знайшли?'],
            'options' => ['en' => "Google\nTikTok\nA friend", 'uk' => "Google\nTikTok\nДрузі"]]);

        $this->get('http://localhost/checkout')->assertOk()->assertSee('A friend');

        $this->post('http://localhost/checkout', $this->form(['fields' => ['found_us' => '2']]))->assertRedirect();
        $this->assertSame('A friend', Order::latest('id')->firstOrFail()->fields[0]['value']);

        $this->post('http://localhost/cart/add/ocf-roses', ['qty' => 1]);
        $this->post('http://localhost/checkout', $this->form(['fields' => ['found_us' => '7']]))->assertSessionHasErrors('fields.found_us');
    }

    public function test_the_admin_makes_a_field_and_its_code(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.checkout.fields.store'), [
            'label' => 'Who takes the parcel', 'type' => 'text', 'place' => 'delivery', 'buyer' => 'all',
            'required' => '1', 'enabled' => '1', 'shipping_methods' => [$this->delivered],
        ])->assertRedirect();

        $field = CheckoutField::latest('id')->firstOrFail();
        $this->assertSame('who_takes_the_parcel', $field->code);
        $this->assertSame([$this->delivered], $field->methodIds());
        $this->assertTrue($field->required);

        $this->actingAs($admin)->get(route('admin.checkout.fields.index'))->assertOk()->assertSee('Who takes the parcel');
        $this->actingAs($admin)->get(route('admin.checkout.fields.edit', $field))->assertOk()->assertSee('order.fields.who_takes_the_parcel');

        // A choice without answers is refused.
        $this->actingAs($admin)->post(route('admin.checkout.fields.store'), [
            'label' => 'Pick one', 'type' => 'select', 'place' => 'notes', 'buyer' => 'all', 'options' => '',
        ])->assertSessionHasErrors('options');
    }
}
