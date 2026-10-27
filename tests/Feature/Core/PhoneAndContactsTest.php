<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Core\Support\Phones;
use App\Core\Verification\Channel;
use App\Core\Verification\SendResult;
use App\Core\Mail\EventMail;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A phone on the account and changing contacts by a code (PLAN §44, step 3):
 * numbers read by each country's own rules, an address written only once a
 * code sent to it came back, and a proven phone good for signing in and for
 * getting back in. The SMS provider is a test channel that remembers codes.
 */
class PhoneAndContactsTest extends TestCase
{
    protected User $customer;

    /** @var list<array{address: string, code: string}> */
    public array $sms = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['torgash.verification.check_mail_domain' => false]);
        Mail::fake();

        $this->customer = User::factory()->create(['email' => 'buyer-'.uniqid().'@example.com', 'password' => 'old-password']);
    }

    /** An SMS channel that keeps what it was asked to send. */
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
                $this->test->sms[] = ['address' => $address, 'code' => $message['code']];

                return SendResult::sent('ok');
            }
        };
        hook_listen('core.verification.channels', fn (array $all) => $all + ['sms' => $channel]);
    }

    protected function mailedCode(): string
    {
        $code = '';
        Mail::assertSent(EventMail::class, function (EventMail $mail) use (&$code) {
            $code = $mail->payload['code'];

            return true;
        });

        return $code;
    }

    public function test_numbers_are_read_by_their_own_countrys_rules(): void
    {
        $this->assertSame('+380675550128', Phones::parse('067 555 01 28', 'UA')['e164']);
        $this->assertSame('+380675550128', Phones::parse('380675550128')['e164'], 'a number with its code but no plus');
        $this->assertSame('PL', Phones::parse('+48 501 234 567')['country']);
        $this->assertSame('US', Phones::parse('+1 650 253 0000')['country']);        $this->assertNull(Phones::parse('+380 67 555'), 'too short to be anybody');
        $this->assertFalse(Phones::parse('+380 44 123 4567')['mobile'], 'a Kyiv landline takes no SMS');
    }

    public function test_the_email_is_changed_only_through_a_code_sent_to_the_new_one(): void
    {
        $old = $this->customer->email;
        $new = 'new-'.uniqid().'@example.com';

        // The profile form no longer takes an email at all.
        $this->actingAs($this->customer)->put('http://localhost/account/profile', ['name' => 'Renamed', 'email' => $new]);
        $this->assertSame($old, $this->customer->fresh()->email);

        $this->post('http://localhost/account/contact/email', ['address' => $new])
            ->assertRedirect(route('account.contact.enter', 'email'));
        Mail::assertSent(EventMail::class, fn ($mail) => $mail->hasTo($new));

        $this->post('http://localhost/account/contact/email/enter', ['code' => $this->mailedCode()])
            ->assertRedirect(route('account.dashboard'));

        $fresh = $this->customer->fresh();
        $this->assertSame($new, $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);
        Mail::assertQueued(EventMail::class, fn ($mail) => $mail->event === 'account.email_changed' && $mail->hasTo($old));
    }

    public function test_an_address_of_another_account_is_refused_before_a_code_goes(): void
    {
        $other = User::factory()->create(['email' => 'taken-'.uniqid().'@example.com']);

        $this->actingAs($this->customer)
            ->post('http://localhost/account/contact/email', ['address' => strtoupper($other->email)])
            ->assertSessionHasErrors('address');

        Mail::assertNothingSent();
    }

    public function test_a_phone_is_offered_only_where_something_can_carry_a_code_to_it(): void
    {
        $this->actingAs($this->customer)->get('http://localhost/account/contact/phone')->assertNotFound();
        $this->get('http://localhost/account')->assertDontSee(__('Add a phone'));

        $this->smsChannel();
        $this->get('http://localhost/account')->assertSee(__('Add a phone'));
    }

    public function test_a_proven_phone_signs_in_and_restores_access(): void
    {
        $this->smsChannel();

        $this->actingAs($this->customer)
            ->post('http://localhost/account/contact/phone', ['address' => '+380 67 555 01 28'])
            ->assertRedirect(route('account.contact.enter', 'phone'));
        $this->post('http://localhost/account/contact/phone/enter', ['code' => $this->sms[0]['code']])
            ->assertRedirect(route('account.dashboard'));

        $fresh = $this->customer->fresh();
        $this->assertSame(['+380675550128', 'UA'], [$fresh->phone, $fresh->phone_country]);
        $this->assertNotNull($fresh->phone_verified_at);

        // Signing in with the phone and the password.
        $this->post('http://localhost/account/logout');
        $this->post('http://localhost/account/login', ['email' => '380675550128', 'password' => 'old-password'])
            ->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($this->customer);
        $this->post('http://localhost/account/logout');

        // Getting back in by the phone: two ways to reach the account, so it asks which.
        $stamp = Crypt::encryptString((string) (time() - 30));
        $this->post('http://localhost/account/code', ['for' => 'password_reset', 'login' => '+380675550128', FormGuard::STAMP => $stamp])
            ->assertSessionHas('code_options', fn ($options) => array_key_exists('phone|sms', $options) && array_key_exists('email|email', $options));

        $this->post('http://localhost/account/code', ['for' => 'password_reset', 'login' => '+380675550128', 'via' => 'phone|sms'])
            ->assertRedirect(route('account.code.enter', ['for' => 'password_reset']));

        $this->post('http://localhost/account/code/enter', ['for' => 'password_reset', 'code' => end($this->sms)['code']])
            ->assertRedirect(route('account.password.new'));
        $this->assertAuthenticatedAs($this->customer);
    }

    public function test_an_unproven_phone_is_nobodys(): void
    {
        $this->smsChannel();
        $this->customer->forceFill(['phone' => '+380675550128'])->save();   // written, never proven

        $this->post('http://localhost/account/login', ['email' => '+380675550128', 'password' => 'old-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_default_country_reads_a_number_typed_without_its_code(): void
    {
        app(SettingsManager::class)->set('core', 'default_country', 'pl');

        $this->assertSame('PL', Phones::defaultCountry());
        $this->assertSame('+48501234567', Phones::parse('501 234 567')['e164']);
    }
}
