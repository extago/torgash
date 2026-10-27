<?php

namespace Tests\Feature\Checkout;

use App\Core\Settings\SettingsManager;
use App\Core\Mail\EventMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * A guest typing the email of an existing account at checkout (PLAN §44.2/1,
 * the owner's mockup): the order is refused until they sign in — by a code
 * to that email, right in the form — and then lands in their history, with
 * the basket still theirs. The shop can switch it off (`existing_account`).
 */
class CheckoutAccountTest extends TestCase
{
    protected PaymentMethod $payment;

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['torgash.verification.check_mail_domain' => false]);
        Mail::fake();

        $this->payment = PaymentMethod::create(['name' => ['en' => 'Invoice'], 'enabled' => true, 'position' => 99]);
        $this->customer = User::factory()->create(['email' => 'regular-'.uniqid().'@example.com']);

        Product::create([
            'sku' => 'CA-1', 'name' => ['en' => 'Account widget'], 'slug' => 'account-widget',
            'price' => 10_00, 'stock' => 5, 'status' => 'active',
        ]);
        $this->post('http://localhost/cart/add/account-widget', ['qty' => 1])->assertOk();
    }

    /** @return array<string, mixed> */
    protected function form(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Olena',
            'email' => strtoupper($this->customer->email),
            'address_line' => '1 Test Street',
            'city' => 'Kyiv',
            'country' => 'UA',
            'postal_code' => '01001',
            'payment_method_id' => $this->payment->id,
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
        ];
    }

    public function test_a_guest_cannot_order_to_an_accounts_email(): void
    {
        $this->post('http://localhost/checkout', $this->form())->assertSessionHasErrors('email');
        $this->assertSame(0, Order::count());
    }

    public function test_the_guest_signs_in_by_a_code_inside_the_form_and_the_order_is_theirs(): void
    {
        $this->post('http://localhost/checkout/account', ['email' => $this->customer->email])
            ->assertOk()->assertSee(__('Send the code to the email'));

        $this->post('http://localhost/checkout/account/code', ['email' => $this->customer->email])
            ->assertOk()->assertSee('account_code', false);

        $code = '';
        Mail::assertSent(EventMail::class, function ($mail) use (&$code) {
            $code = $mail->payload['code'];

            return $mail->hasTo($this->customer->email);
        });

        $this->post('http://localhost/checkout/account/verify', ['email' => $this->customer->email, 'account_code' => $code])
            ->assertHeader('HX-Refresh', 'true');
        $this->assertAuthenticatedAs($this->customer);

        // The basket survived the sign-in, and the order is now theirs.
        $this->post('http://localhost/checkout', $this->form())->assertRedirect();
        $this->assertSame($this->customer->id, Order::latest('id')->firstOrFail()->user_id);
    }

    public function test_an_address_nobody_uses_opens_no_panel(): void
    {
        $this->post('http://localhost/checkout/account', ['email' => 'free-'.uniqid().'@example.com'])
            ->assertOk()->assertContent('');
    }

    public function test_the_shop_may_let_such_a_guest_order_anyway(): void
    {
        app(SettingsManager::class)->set('torgash.checkout', 'existing_account', 'allow');

        $this->post('http://localhost/checkout/account', ['email' => $this->customer->email])->assertContent('');
        $this->post('http://localhost/checkout', $this->form())->assertRedirect();
        $this->assertNull(Order::latest('id')->firstOrFail()->user_id, 'a guest order, not attached');
    }
}
