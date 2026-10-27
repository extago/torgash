<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Support\Phones;
use App\Core\Verification\Channel;
use App\Core\Verification\FormVerification;
use App\Core\Verification\SendResult;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Which contact an account must have is the shop's choice (PLAN §44, step
 * 3b): an email, a phone, either or both. No invented address is ever stored;
 * a phone is proven by a code on the very form that puts it on an account; and
 * the last way into an account can never be removed.
 */
class AccountContactTest extends TestCase
{
    /** @var list<string> codes the test SMS channel was asked to send */
    public array $sms = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['torgash.verification.check_mail_domain' => false]);
        Mail::fake();

        $test = $this;
        hook_listen('core.verification.channels', fn (array $all) => $all + ['sms' => new class($test) implements Channel
        {
            public function __construct(private $test) {}

            public function label(): string { return 'SMS'; }

            public function kinds(): array { return ['phone']; }

            public function available(): bool { return true; }

            public function supports(string $kind, string $address): bool { return (Phones::parse($address)['mobile'] ?? false) === true; }

            public function send(string $kind, string $address, array $message): SendResult
            {
                $this->test->sms[] = $message['code'];

                return SendResult::sent('ok');
            }
        }]);
    }

    protected function mode(string $mode): void
    {
        app(SettingsManager::class)->set('core', 'account_contact', $mode);
    }

    public function test_by_default_an_email_is_required_as_before(): void
    {
        $this->post('http://localhost/account/register', $this->form(['email' => '']))->assertSessionHasErrors('email');
        $this->post('http://localhost/account/register', $this->form(['phone' => '+380935550107']))->assertSessionHasErrors('phone');
    }

    public function test_a_shop_by_phone_registers_with_a_confirmed_phone_and_no_email(): void
    {
        $this->mode('phone');

        $this->get('http://localhost/account/register')->assertOk()->assertSee('name="phone"', false);

        // Not confirmed yet: refused.
        $this->post('http://localhost/account/register', $this->form(['phone' => '0935550107', 'country' => 'UA', 'email' => '']))
            ->assertSessionHasErrors('phone');
        $this->assertFalse(User::where('phone', '+380935550107')->exists());

        $cookie = $this->confirm('+380935550107');

        $this->withCookie(FormVerification::COOKIE, $cookie)
            ->post('http://localhost/account/register', $this->form(['phone' => '+380935550107', 'email' => '']))
            ->assertRedirect();

        $user = User::where('phone', '+380935550107')->firstOrFail();
        $this->assertNull($user->email, 'no invented address');
        $this->assertNotNull($user->phone_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_either_takes_one_of_the_two(): void
    {
        $this->mode('either');

        $this->post('http://localhost/account/register', $this->form(['email' => '']))->assertSessionHasErrors(['email', 'phone']);
        $this->post('http://localhost/account/register', $this->form(['email' => 'either-'.uniqid().'@example.com']))->assertRedirect();
    }

    public function test_the_last_way_in_is_never_removed(): void
    {
        $this->mode('either');
        $user = User::factory()->create(['email' => 'two-'.uniqid().'@example.com', 'phone' => '+380935550107', 'phone_verified_at' => now()]);
        $this->actingAs($user);

        // Email off: the proven phone still signs in.
        $this->delete('http://localhost/account/contact/email')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->email);

        // And the phone is the last one: kept.
        $this->delete('http://localhost/account/contact/phone')->assertRedirect()->assertSessionHasErrors('contact');
        $this->assertSame('+380935550107', $user->fresh()->phone);

        // A shop that requires an email does not let it go at all.
        $this->mode('email');
        $other = User::factory()->create(['email' => 'keep-'.uniqid().'@example.com', 'phone' => '+380935550100', 'phone_verified_at' => now()]);
        $this->actingAs($other)->delete('http://localhost/account/contact/email')->assertSessionHasErrors('contact');
        $this->assertNotNull($other->fresh()->email);
    }

    public function test_the_admin_makes_a_phone_customer_and_letters_simply_do_not_go(): void
    {
        $this->mode('phone');
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->post('http://localhost/admin/customers', [
            'name' => 'Phone Buyer', 'email' => '', 'phone' => '+380935550101', 'send_invite' => 1, 'after_save' => 'close',
        ])->assertRedirect();

        $customer = User::where('phone', '+380935550101')->firstOrFail();
        $this->assertNull($customer->email);
        $this->assertNotNull($customer->phone_verified_at, 'the shop vouches for a phone it types');
        Mail::assertNothingQueued();

        $this->get('http://localhost/admin/customers?q=935550101')->assertOk()->assertSee('Phone Buyer');
        $this->assertFalse(\App\Core\Mail\Letters::send('account.email_changed', null, []));
    }

    public function test_without_a_phone_channel_the_phone_choices_fall_back_to_email(): void
    {
        $this->mode('phone');
        hook_listen('core.verification.channels', fn (array $all) => array_diff_key($all, ['sms' => 1]), 99);

        $this->assertSame('email', app(\App\Core\Access\AccountContact::class)->mode());
    }

    protected function confirm(string $phone): string
    {
        $this->post('http://localhost/verify-contact', ['verify_form' => 'account.phone', 'phone' => $phone])
            ->assertOk()->assertSee('verify_code', false);

        $done = $this->post('http://localhost/verify-contact/check', [
            'verify_form' => 'account.phone', 'phone' => $phone, 'verify_code' => end($this->sms),
        ])->assertOk()->assertSee(__('The phone is confirmed.'));

        return $done->getCookie(FormVerification::COOKIE)->getValue();
    }

    protected function form(array $overrides): array
    {
        return $overrides + [
            'name' => 'New Customer',
            'email' => 'new-'.uniqid().'@example.com',
            'password' => 'long-enough-1',
            'password_confirmation' => 'long-enough-1',
        ];
    }
}
