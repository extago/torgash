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

    /** The address of the button in the letter. */
    protected function sentLink(): string
    {
        $link = null;
        Mail::assertSent(EventMail::class, function (EventMail $mail) use (&$link) {
            $link = $mail->payload['link'] ?? null;

            return true;
        });

        return (string) $link;
    }

    public function test_the_letters_button_signs_in_on_any_device(): void
    {
        $this->ask();
        $link = $this->sentLink();

        $this->flushSession();
        $this->get($link)->assertOk();
        $this->assertGuest();

        $this->post($link)->assertRedirect(route('account.password.new'));
        $this->assertAuthenticatedAs($this->customer);
    }

    /** A mail filter opens every link of a letter before the person does; a code it spent would be dead on arrival. */
    public function test_opening_the_button_spends_nothing(): void
    {
        $this->ask();
        $row = VerificationCode::latest('id')->first();
        $link = $this->sentLink();

        $this->flushSession();
        $this->get($link)->assertOk()->assertSee('method="POST"', false);
        $this->get($link)->assertOk();

        $this->assertGuest();
        $this->assertNull($row->fresh()->verified_at);
        $this->assertSame(0, $row->fresh()->attempts);

        $this->post($link)->assertRedirect(route('account.password.new'));
        $this->assertAuthenticatedAs($this->customer);
    }

    public function test_the_button_of_something_else_opens_nothing(): void
    {
        $this->ask();
        $row = VerificationCode::latest('id')->first();
        $row->forceFill(['purpose' => 'contact_form'])->save();

        $this->get($this->sentLink())->assertNotFound();
    }

    /** Row ids go up by one, and a button that counted wrong secrets let anyone close anyone's code. */
    public function test_a_stranger_cannot_close_somebody_elses_code(): void
    {
        $this->ask();
        $row = VerificationCode::latest('id')->first();
        $link = $this->sentLink();

        $this->flushSession();

        for ($i = 0; $i < Verifier::MAX_ATTEMPTS + 1; $i++) {
            $this->post('http://localhost/account/code/'.$row->id.'/00000'.$i);
        }

        $this->assertSame(0, $row->fresh()->attempts);
        $this->assertGuest();

        $this->post($link)->assertRedirect(route('account.password.new'));
        $this->assertAuthenticatedAs($this->customer);
    }

    public function test_the_six_digits_do_not_open_the_button(): void
    {
        $this->ask();
        $row = VerificationCode::latest('id')->first();
        $code = $this->sentCode();

        $this->flushSession();
        $this->post('http://localhost/account/code/'.$row->id.'/'.$code);

        $this->assertGuest();
        $this->assertNull($row->fresh()->verified_at);
    }

    public function test_a_new_code_retires_the_button_of_the_old_one(): void
    {
        $this->ask();
        $row = VerificationCode::latest('id')->first();
        $old = $this->sentLink();

        $this->travel(2)->minutes();
        $this->post('http://localhost/account/code/resend', ['for' => 'password_reset'])->assertSessionHasNoErrors();

        $this->assertSame(2, $row->fresh()->sends);
        $this->flushSession();
        $this->post($old);

        $this->assertGuest();
    }

    public function test_the_button_has_a_limit_of_its_own(): void
    {
        $seen = [];

        for ($i = 0; $i < 40; $i++) {
            $seen[] = $this->post('http://localhost/account/code/999999/x')->status();
        }

        $this->assertContains(429, $seen);
    }

    /** What every request of a parallel burst holds: the row as it was before any of them counted. */
    public function test_a_burst_gets_five_tries_and_no_more(): void
    {
        $this->ask();
        $id = VerificationCode::latest('id')->value('id');
        $right = $this->sentCode();
        $wrong = $right === '000000' ? '111111' : '000000';
        $verifier = app(Verifier::class);

        $copies = VerificationCode::whereKey($id)->get()->all();
        $late = VerificationCode::find($id);

        for ($i = 0; $i < Verifier::MAX_ATTEMPTS; $i++) {
            $verifier->check(VerificationCode::find($id), $wrong);
        }

        $this->assertSame(0, $late->attempts, 'still what it read');
        $this->assertNotNull($verifier->check($late, $right), 'the count is the table\'s');
        $this->assertNull(VerificationCode::find($id)->verified_at);
        $this->assertCount(1, $copies);
    }

    public function test_a_right_code_works_once_for_two_requests_at_once(): void
    {
        $this->ask();
        $id = VerificationCode::latest('id')->value('id');
        $right = $this->sentCode();
        $verifier = app(Verifier::class);

        $first = VerificationCode::find($id);
        $second = VerificationCode::find($id);

        $this->assertNull($verifier->check($first, $right));
        $this->assertNotNull($verifier->check($second, $right));
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

    /**
     * "Continue on this device" in a letter confirming a new email did
     * nothing: the link knew only signing in, and a signed-in customer never
     * reached it (the live shop, 2026-11-07). It confirms the address now —
     * signed in, or on another device without signing anybody in.
     */
    public function test_the_letters_button_confirms_a_new_email_on_any_device(): void
    {
        $customer = $this->customer;
        $customer->forceFill(['email_verified_at' => null])->save();
        $mail = fn () => Mail::sent(EventMail::class)->last()->payload;

        // Signed in, the same browser.
        $this->actingAs($customer)->post('http://localhost/account/contact/email', ['address' => $customer->email])
            ->assertRedirect(route('account.contact.enter', 'email'));
        $this->assertNotSame('', (string) $mail()['link'], 'the letter carries the button');
        $this->actingAs($customer)->get($mail()['link'])->assertOk()->assertSee('method="POST"', false);
        $this->assertNull($customer->fresh()->email_verified_at, 'opening it decides nothing');
        $this->actingAs($customer)->post($mail()['link'])
            ->assertRedirect(route('account.dashboard'))->assertSessionHas('status');
        $this->assertNotNull($customer->fresh()->email_verified_at);

        // Another device: nobody signed in, the new address confirmed all the same.
        $new = 'new-'.uniqid().'@example.com';
        $this->actingAs($customer)->post('http://localhost/account/contact/email', ['address' => $new]);
        $link = (string) $mail()['link'];
        auth()->logout();
        $this->flushSession();
        $this->post($link)->assertRedirect(route('account.login'))->assertSessionHas('status');
        $this->assertGuest();
        $this->assertSame($new, $customer->fresh()->email);

        // Used once.
        $this->post($link)->assertRedirect(route('account.login'))->assertSessionHasErrors('login');
    }

    /** A form's code is waited for on the page that asked: its letter has no button to go elsewhere. */
    public function test_a_code_whose_page_waits_elsewhere_is_sent_without_a_button(): void
    {
        $row = new VerificationCode(['purpose' => \App\Core\Verification\FormVerification::PURPOSE, 'kind' => 'email', 'address' => $this->customer->email]);

        $link = (new \ReflectionMethod(Verifier::class, 'link'))->invoke(app(Verifier::class), $row, '123456');

        $this->assertNull($link);
    }
}
