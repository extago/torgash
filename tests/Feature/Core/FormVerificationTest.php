<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Support\Phones;
use App\Core\Verification\Channel;
use App\Core\Verification\FormVerification;
use App\Core\Verification\SendResult;
use App\Core\Mail\EventMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * "Confirm the contact by a code" on a public form (PLAN §44.3): the order
 * waits for its phone — or its email, where the shop cannot send an SMS —
 * and a contact proven once in a browser is not asked again there. The SMS
 * provider is a test channel that remembers the codes.
 */
class FormVerificationTest extends TestCase
{
    /** @var list<string> */
    public array $sms = [];

    protected PaymentMethod $payment;

    protected function setUp(): void
    {
        parent::setUp();

        config(['torgash.verification.check_mail_domain' => false]);
        Mail::fake();

        $this->payment = PaymentMethod::create(['name' => ['en' => 'Invoice'], 'enabled' => true, 'position' => 99]);
        Product::create([
            'sku' => 'FV-1', 'name' => ['en' => 'Verified widget'], 'slug' => 'verified-widget',
            'price' => 10_00, 'stock' => 5, 'status' => 'active',
        ]);
        $this->post('http://localhost/cart/add/verified-widget', ['qty' => 1])->assertOk();

        app(SettingsManager::class)->set('core', 'verify_forms', ['checkout', 'account.register']);
    }

    protected function smsChannel(): void
    {
        $test = $this;
        $channel = new class($test) implements Channel
        {
            public function __construct(private $test) {}

            public function label(): string { return 'SMS'; }

            public function kinds(): array { return ['phone']; }

            public function available(): bool { return true; }

            public function supports(string $kind, string $address): bool { return (Phones::parse($address)['mobile'] ?? false) === true; }

            public function send(string $kind, string $address, array $message): SendResult
            {
                $this->test->sms[] = $message['code'];

                return SendResult::sent();
            }
        };
        hook_listen('core.verification.channels', fn (array $all) => $all + ['sms' => $channel]);
    }

    /** @return array<string, mixed> */
    protected function order(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Olena',
            'email' => 'guest-'.uniqid().'@example.com',
            'phone' => '+380 93 555 0107',
            'address_line' => '1 Test Street',
            'city' => 'Kyiv',
            'country' => 'UA',
            'postal_code' => '01001',
            'payment_method_id' => $this->payment->id,
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
        ];
    }

    public function test_an_order_waits_for_its_phone_and_the_browser_remembers_it(): void
    {
        $this->smsChannel();
        $this->assertSame('phone', app(FormVerification::class)->kindFor('checkout'));

        $this->post('http://localhost/checkout', $this->order())->assertSessionHasErrors('phone');
        $this->assertSame(0, Order::count());

        $this->post('http://localhost/verify-contact', ['verify_form' => 'checkout', 'verify_field' => 'phone', 'phone' => '0935550107', 'country' => 'UA'])
            ->assertOk()->assertSee('verify_code', false);

        $done = $this->post('http://localhost/verify-contact/check', [
            'verify_form' => 'checkout', 'verify_field' => 'phone', 'phone' => '+380935550107', 'verify_code' => $this->sms[0],
        ])->assertOk()->assertSee(__('The phone is confirmed.'));

        $cookie = $done->getCookie(FormVerification::COOKIE);
        $this->assertNotNull($cookie);

        // The same browser: the order goes, and the next one is not asked again.
        $this->withCookie(FormVerification::COOKIE, $cookie->getValue())
            ->post('http://localhost/checkout', $this->order())->assertRedirect();
        $this->assertSame(1, Order::count());
    }

    public function test_without_an_sms_gateway_the_email_is_what_is_confirmed(): void
    {
        $this->assertSame('email', app(FormVerification::class)->kindFor('checkout'));

        $email = 'guest-'.uniqid().'@example.com';
        $this->post('http://localhost/checkout', $this->order(['email' => $email]))->assertSessionHasErrors('email');

        $this->post('http://localhost/verify-contact', ['verify_form' => 'checkout', 'email' => $email])->assertOk();
        Mail::assertSent(EventMail::class, fn ($mail) => $mail->hasTo($email));
    }

    public function test_a_signed_in_customer_is_never_asked(): void
    {
        $this->smsChannel();
        $customer = User::factory()->create();   // no phone on the account at all

        $this->actingAs($customer)->get('http://localhost/checkout')->assertDontSee('data-contact-verify', false);
        $this->post('http://localhost/checkout', $this->order(['email' => $customer->email, 'phone' => '+380 93 000 0000']))->assertRedirect();
        $this->assertSame(1, Order::count());
    }

    public function test_an_unticked_form_asks_nothing(): void
    {
        app(SettingsManager::class)->set('core', 'verify_forms', []);

        $this->post('http://localhost/checkout', $this->order())->assertRedirect();
        $this->get('http://localhost/checkout')->assertDontSee('data-contact-verify', false);
    }

    public function test_an_account_waits_for_its_email_and_is_born_verified(): void
    {
        $email = 'new-'.uniqid().'@example.com';
        $form = ['name' => 'Olena', 'email' => $email, 'password' => 'long-password', 'password_confirmation' => 'long-password'];

        $this->post('http://localhost/account/register', $form)->assertSessionHasErrors('email');

        $this->post('http://localhost/verify-contact', ['verify_form' => 'account.register', 'email' => $email])->assertOk();
        $code = '';
        Mail::assertSent(EventMail::class, function ($mail) use (&$code) {
            $code = $mail->payload['code'];

            return true;
        });
        $cookie = $this->post('http://localhost/verify-contact/check', ['verify_form' => 'account.register', 'email' => $email, 'verify_code' => $code])
            ->getCookie(FormVerification::COOKIE);

        $this->withCookie(FormVerification::COOKIE, $cookie->getValue())
            ->post('http://localhost/account/register', $form)->assertRedirect();

        $this->assertNotNull(User::where('email', $email)->value('email_verified_at'));
    }
}
