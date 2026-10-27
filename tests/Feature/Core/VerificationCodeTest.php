<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Core\Verification\Channel;
use App\Core\Verification\SendResult;
use App\Core\Verification\Verifier;
use App\Core\Mail\EventMail;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Codes that prove an address (PLAN §44): getting back into an account by a
 * code sent to its email, signing in by one when the shop allows it, and the
 * limits that keep a public "send me a code" form from being a mail cannon.
 */
class VerificationCodeTest extends TestCase
{
    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['torgash.verification.check_mail_domain' => false]);
        Mail::fake();
        RateLimiter::clear('verification');

        $this->customer = User::factory()->create(['email' => 'buyer-'.uniqid().'@example.com', 'password' => 'old-password']);
    }

    protected function ask(array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('http://localhost/account/code', $data + [
            'for' => 'password_reset',
            'login' => $this->customer->email,
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
        ]);
    }

    /** The code as the letter carried it. */
    protected function sentCode(): string
    {
        $code = null;
        Mail::assertSent(EventMail::class, function (EventMail $mail) use (&$code) {
            $code = $mail->payload['code'];

            return true;
        });

        return (string) $code;
    }

    public function test_a_lost_password_is_replaced_after_a_code_sent_to_the_email(): void
    {
        $this->ask()->assertRedirect(route('account.code.enter', ['for' => 'password_reset']));

        Mail::assertSent(EventMail::class, fn ($mail) => $mail->hasTo($this->customer->email));
        $row = VerificationCode::latest('id')->first();
        $this->assertSame('sent', $row->status);
        $this->assertNotSame($this->sentCode(), $row->code_hash, 'only a hash is stored');

        $this->get('http://localhost/account/code/enter?for=password_reset')
            ->assertOk()
            ->assertSee(Verifier::mask('email', $this->customer->email));

        $this->post('http://localhost/account/code/enter', ['for' => 'password_reset', 'code' => $this->sentCode()])
            ->assertRedirect(route('account.password.new'));
        $this->assertAuthenticatedAs($this->customer);
        $this->assertNotNull($this->customer->fresh()->email_verified_at, 'the code proved the address');

        $this->put('http://localhost/account/password/new', ['password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertRedirect(route('account.dashboard'));
        $this->assertTrue(Hash::check('brand-new-pass', $this->customer->fresh()->password));

        // The door closes behind it: a second visit asks for the current password again.
        $this->get('http://localhost/account/password/new')->assertRedirect(route('account.dashboard'));
    }

    public function test_wrong_codes_count_and_the_fifth_closes_the_code(): void
    {
        $this->ask();
        $right = $this->sentCode();
        $wrong = $right === '000000' ? '111111' : '000000';

        for ($i = 1; $i <= Verifier::MAX_ATTEMPTS; $i++) {
            $this->post('http://localhost/account/code/enter', ['for' => 'password_reset', 'code' => $wrong])->assertSessionHasErrors('code');
        }

        // Even the right one no longer opens it.
        $this->post('http://localhost/account/code/enter', ['for' => 'password_reset', 'code' => $right])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_code_is_bound_to_the_browser_that_asked_for_it(): void
    {
        $this->ask();
        $code = $this->sentCode();

        // Another browser: a fresh session holds no code to check against.
        $this->flushSession();
        $this->post('http://localhost/account/code/enter', ['for' => 'password_reset', 'code' => $code])
            ->assertRedirect(route('account.code', ['for' => 'password_reset']));
        $this->assertGuest();
    }

    public function test_the_letters_button_signs_in_on_any_device(): void
    {
        $this->ask();
        $row = VerificationCode::latest('id')->first();
        $code = $this->sentCode();

        $this->flushSession();
        $this->get('http://localhost/account/code/'.$row->id.'/'.$code)->assertRedirect(route('account.password.new'));
        $this->assertAuthenticatedAs($this->customer);
    }

    public function test_a_new_code_waits_for_the_pause(): void
    {
        $this->ask();

        $this->post('http://localhost/account/code/resend', ['for' => 'password_reset'])->assertSessionHasErrors('code');

        $this->travel(61)->seconds();
        $this->post('http://localhost/account/code/resend', ['for' => 'password_reset'])
            ->assertRedirect(route('account.code.enter', ['for' => 'password_reset']));
        $this->assertSame(2, VerificationCode::latest('id')->first()->sends);

        // The second pause is twice as long.
        $this->travel(61)->seconds();
        $this->post('http://localhost/account/code/resend', ['for' => 'password_reset'])->assertSessionHasErrors('code');
    }

    public function test_an_unknown_address_is_said_and_staff_are_sent_to_the_admin(): void
    {
        $this->ask(['login' => 'nobody-'.uniqid().'@example.com'])
            ->assertSessionHasErrors('login')
            ->assertSessionHas('no_account');

        $manager = User::role('admin')->firstOrFail();
        $this->ask(['login' => $manager->email])->assertSessionHasErrors('login')->assertSessionMissing('no_account');

        Mail::assertNothingSent();
    }

    public function test_signing_in_by_code_is_the_shops_choice(): void
    {
        $this->get('http://localhost/account/code?for=sign_in')->assertNotFound();

        app(SettingsManager::class)->set('core', 'sign_in_by_code', true);

        $this->get('http://localhost/account/login')->assertSee(__('Sign in with a code'));
        $this->ask(['for' => 'sign_in'])->assertRedirect(route('account.code.enter', ['for' => 'sign_in']));
        $this->post('http://localhost/account/code/enter', ['for' => 'sign_in', 'code' => $this->sentCode()])
            ->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($this->customer);
    }

    public function test_a_channel_that_rejects_the_address_says_so_to_the_customer(): void
    {
        $channel = new class implements Channel
        {
            public function label(): string { return 'Test'; }

            public function kinds(): array { return ['email']; }

            public function available(): bool { return true; }

            public function supports(string $kind, string $address): bool { return true; }

            public function send(string $kind, string $address, array $message): SendResult
            {
                return SendResult::invalidAddress('mailbox does not exist');
            }
        };
        hook_listen('core.verification.channels', fn (array $all) => $all + ['test' => $channel]);

        $this->ask(['via' => 'email|test'])->assertSessionHasErrors('login');

        $row = VerificationCode::latest('id')->first();
        $this->assertSame('invalid_address', $row->status);
        $this->assertSame('mailbox does not exist', $row->error);
    }

    public function test_the_log_lists_codes_for_the_staff(): void
    {
        $this->ask();

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/verification-codes')
            ->assertOk()
            ->assertSee($this->customer->email);
    }
}
