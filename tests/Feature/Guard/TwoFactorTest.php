<?php

namespace Tests\Feature\Guard;

use App\Core\Settings\SettingsManager;
use App\Models\CustomerGroup;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Guard\Models\AuditEntry;
use Torgash\Guard\Models\TwoFactorSecret;
use Torgash\Guard\TwoFactor\Enrolment;
use Torgash\Guard\TwoFactor\Requirement;
use Torgash\Guard\TwoFactor\Stamp;
use Torgash\Guard\TwoFactor\Totp;

/**
 * The second factor.
 *
 * What is pinned here is not "a code works" — it is the handful of ways this
 * feature stops being one. A code that works twice; a shop that demands one and
 * locks its own manager out; an enrolment that arms itself before anybody has
 * proved they can read it; a machine on an API token refused because a person
 * elsewhere has a phone; a secret copied into the audit trail. Each of those is
 * a test below, and each of them is a decision written down in
 * `TwoFactor\Requirement`, `RequireTwoFactor` or `Enrolment`.
 */
class TwoFactorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(SettingsManager::class);
        $settings->set('torgash.guard', 'two_factor_staff', Requirement::OFFERED);
        $settings->set('torgash.guard', 'two_factor_staff_groups', []);
        $settings->set('torgash.guard', 'two_factor_customers', Requirement::OFF);
        $settings->set('torgash.guard', 'two_factor_customer_groups', []);
    }

    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

    protected function customer(string $email = 'buyer@example.test'): User
    {
        return User::create(['name' => 'Иван', 'email' => $email, 'password' => 'secret123']);
    }

    /** Somebody with a working second factor, and the secret to answer with. */
    protected function armed(User $user): TwoFactorSecret
    {
        $enrolment = app(Enrolment::class);
        $record = $enrolment->start($user);

        $this->assertNotNull($enrolment->confirm($user, Totp::code($record->secret)));

        // The step that armed it is spent — that is the single-use rule, and it
        // is why every test below moves the clock past it rather than reusing
        // the same six digits. In life those thirty seconds pass by themselves.
        $this->travel(31)->seconds();

        return $record->fresh();
    }

    // ---------------------------------------------------------- the algorithm

    /**
     * The reason this module carries no TOTP dependency: the RFC publishes test
     * vectors, so "is our arithmetic right" is a question with a printed
     * answer rather than a matter of trusting somebody.
     *
     * The vectors are eight digits and ours are six, which is what every
     * authenticator app implements — the six are the low-order half of the
     * same number, so each one is compared as its own last six.
     */
    public function test_the_codes_are_the_ones_rfc_6238_prints(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');

        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);

        foreach ([
            59 => '94287082',
            1111111109 => '07081804',
            1111111111 => '14050471',
            1234567890 => '89005924',
            2000000000 => '69279037',
            20000000000 => '65353130',
        ] as $at => $expected) {
            $this->assertSame(
                substr($expected, -6),
                Totp::code($secret, Totp::timestep($at)),
                "RFC 6238 vector at T={$at}",
            );
        }
    }

    /**
     * A code is good once. Without this the six digits are a password somebody
     * read over your shoulder — valid for the rest of their minute.
     */
    public function test_a_code_cannot_be_used_twice(): void
    {
        $secret = Totp::secret();
        $step = Totp::verify($secret, Totp::code($secret));

        $this->assertNotNull($step);
        $this->assertNull(Totp::verify($secret, Totp::code($secret), $step));
    }

    /** A phone whose clock is half a minute out still works; last week does not. */
    public function test_the_window_is_one_step_either_way(): void
    {
        $secret = Totp::secret();
        $now = Totp::timestep();

        $this->assertNotNull(Totp::verify($secret, Totp::code($secret, $now - 1)));
        $this->assertNotNull(Totp::verify($secret, Totp::code($secret, $now + 1)));
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $now - 3)));
    }

    // ----------------------------------------------------------- the enrolment

    /**
     * Enrolment is two acts, and the first one arms nothing.
     *
     * A row written by opening the page must lock nobody out: the camera does
     * not focus, the app will not install, the tab gets closed. Anything else
     * and the person finds out at their next login.
     */
    public function test_a_started_enrolment_locks_nobody_out(): void
    {
        $admin = $this->admin();

        app(Enrolment::class)->start($admin);

        $this->assertFalse(TwoFactorSecret::where('user_id', $admin->id)->firstOrFail()->isConfirmed());

        $this->actingAs($admin)->get('http://localhost/admin')->assertOk();
    }

    public function test_a_wrong_code_does_not_arm_it(): void
    {
        $admin = $this->admin();
        app(Enrolment::class)->start($admin);

        $this->assertNull(app(Enrolment::class)->confirm($admin, '000000'));
        $this->assertNull(app(Enrolment::class)->active($admin));
    }

    /**
     * Arming it mints the recovery codes at the same moment — the only moment
     * they can honestly be shown, before there is anything to lose — and
     * counts the session that did it as having answered. Making somebody who
     * has this second proved they hold the phone type another code is theatre.
     */
    public function test_confirming_mints_recovery_codes_and_asks_nothing_further(): void
    {
        $admin = $this->admin();
        $record = app(Enrolment::class)->start($admin);

        $response = $this->actingAs($admin)
            ->post('http://localhost/admin/guard/security/confirm', ['code' => Totp::code($record->secret)]);

        $response->assertRedirect(route('admin.guard.security'));
        $response->assertSessionHas('guard.recovery_codes');

        $this->assertCount(Enrolment::CODES, session('guard.recovery_codes'));
        $this->assertCount(Enrolment::CODES, $record->fresh()->recovery_codes);

        // Straight on, no challenge in between.
        $this->get('http://localhost/admin')->assertOk();
    }

    // ------------------------------------------------------------- the door

    /**
     * A new session gets asked, and until it answers the shop is exactly four
     * things: the code box, its POST, the way out, and a module's static
     * asset. That claim is the whole security argument for checking this in
     * the `web` group rather than inside the login form, so it is asserted
     * from both sides.
     */
    public function test_a_new_session_is_asked_and_can_reach_nothing_else(): void
    {
        $admin = $this->admin();
        $this->armed($admin);

        // A fresh session: authenticated, and it has answered nothing.
        $this->actingAs($admin);

        $this->get('http://localhost/admin')->assertRedirect(route('guard.two_factor'));
        $this->get('http://localhost/admin/products')->assertRedirect(route('guard.two_factor'));
        $this->get('http://localhost/')->assertRedirect(route('guard.two_factor'));

        // ...including the page that could switch the second factor off. A
        // password alone must not be able to undo the second factor.
        $this->get('http://localhost/admin/guard/security')->assertRedirect(route('guard.two_factor'));

        $this->get('http://localhost/two-factor')->assertOk()->assertSee('000000', false);
    }

    public function test_the_right_code_puts_them_where_they_were_going(): void
    {
        $admin = $this->admin();
        $record = $this->armed($admin);

        $this->actingAs($admin);

        // Where they were going is remembered from the GET that was stopped.
        $this->get('http://localhost/admin/products')->assertRedirect(route('guard.two_factor'));

        $this->post('http://localhost/two-factor', ['code' => Totp::code($record->secret)])
            ->assertSessionHasNoErrors()
            ->assertRedirect('http://localhost/admin/products');

        $this->get('http://localhost/admin')->assertOk();
    }

    /**
     * A recovery code works, is spent by working, and lands the person on their
     * own security page — the only place that can answer the two things they
     * now need: how many are left, and "this phone is gone".
     */
    public function test_a_recovery_code_signs_in_once_and_is_then_gone(): void
    {
        $admin = $this->admin();
        $record = $this->armed($admin);
        $codes = $record->recovery_codes;

        $this->actingAs($admin);

        $this->post('http://localhost/two-factor', ['code' => $codes[0]])
            ->assertRedirect(route('admin.guard.security'));

        $this->assertSame(Enrolment::CODES - 1, $record->fresh()->codesLeft());

        // The same one again, in a session that has to answer afresh.
        $this->actingAs($admin->fresh());
        app(Stamp::class)->forget();

        $this->post('http://localhost/two-factor', ['code' => $codes[0]])
            ->assertSessionHasErrors('code');
    }

    /**
     * Five wrong codes and the password goes back. Not a rate limit that leaves
     * the session open — a limit that waits is a limit that can be waited out.
     */
    public function test_too_many_wrong_codes_takes_the_password_back(): void
    {
        $admin = $this->admin();
        $this->armed($admin);
        $this->actingAs($admin);

        for ($i = 1; $i < Stamp::MAX_MISSES; $i++) {
            $this->post('http://localhost/two-factor', ['code' => '000000'])
                ->assertSessionHasErrors('code');
            $this->assertTrue(auth()->check(), "still signed in after {$i} wrong code(s)");
        }

        $this->post('http://localhost/two-factor', ['code' => '000000'])
            ->assertRedirect(route('admin.login'));

        $this->assertFalse(auth()->check());
    }

    /**
     * ...and signing in again is no way round it. Somebody who holds the
     * password could otherwise buy five more guesses with every login, and a
     * million codes are then only patience. The account keeps its own count;
     * past it even the right code is not read.
     */
    public function test_signing_in_again_does_not_buy_more_guesses(): void
    {
        $admin = $this->admin();
        $record = $this->armed($admin);
        $rounds = (int) ceil(\Torgash\Guard\TwoFactor\Attempts::PER_ACCOUNT / Stamp::MAX_MISSES);

        for ($round = 0; $round < $rounds; $round++) {
            $this->actingAs($admin->fresh());

            for ($i = 0; $i < Stamp::MAX_MISSES; $i++) {
                $this->post('http://localhost/two-factor', ['code' => '000000']);
            }
        }

        $this->actingAs($admin->fresh());

        $this->post('http://localhost/two-factor', ['code' => Totp::code($record->secret)])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('email');

        $this->assertFalse(auth()->check());
        $this->assertStringContainsString('Too many wrong codes for this account', session('errors')->first('email'));
    }

    /** A wrong second factor is a failed sign-in, counted by whoever counts them. */
    public function test_a_wrong_code_is_announced_as_a_failed_sign_in(): void
    {
        $admin = $this->admin();
        $this->armed($admin);
        $this->actingAs($admin);

        \Illuminate\Support\Facades\Event::fake([\Illuminate\Auth\Events\Failed::class]);

        $this->post('http://localhost/two-factor', ['code' => '000000']);

        \Illuminate\Support\Facades\Event::assertDispatched(\Illuminate\Auth\Events\Failed::class);
    }

    // --------------------------------------------------------- who has to have one

    /**
     * Requiring it never locks anybody out: somebody who must have one and has
     * none is sent to the enrolment page — and that page, and only that page,
     * is open to them.
     */
    public function test_required_sends_a_manager_to_enrol_rather_than_refusing_them(): void
    {
        app(SettingsManager::class)->set('torgash.guard', 'two_factor_staff', Requirement::REQUIRED);

        $admin = $this->admin();
        $this->actingAs($admin);

        $this->get('http://localhost/admin')->assertRedirect(route('admin.guard.security'));
        $this->get('http://localhost/admin/guard/security')->assertOk();

        // And the whole way through, in one go.
        $record = app(Enrolment::class)->start($admin);

        $this->post('http://localhost/admin/guard/security/confirm', ['code' => Totp::code($record->secret)])
            ->assertRedirect(route('admin.guard.security'));

        $this->get('http://localhost/admin')->assertOk();
    }

    /** The group list is the narrow way of demanding one, and it is read only in the middle mode. */
    public function test_a_permission_group_can_be_required_while_everybody_else_chooses(): void
    {
        $group = Role::create(['name' => 'Кассир']);
        $cashier = $this->customer('cashier@example.test');
        $cashier->assignRole($group);

        $settings = app(SettingsManager::class);
        $settings->set('torgash.guard', 'two_factor_staff_groups', [$group->id]);

        $requirement = app(Requirement::class);

        $this->assertTrue($requirement->required($cashier->fresh()));
        $this->assertFalse($requirement->required($this->admin()));

        // ...and it says nothing at all once the shop has said "required" or
        // "off": those two mean what the words mean.
        $settings->set('torgash.guard', 'two_factor_staff', Requirement::OFF);
        $this->assertFalse($requirement->required($cashier->fresh()));
        $this->assertFalse($requirement->offered($cashier->fresh()));
    }

    /** A customer group is the same sentence in the other vocabulary. */
    public function test_a_customer_group_can_be_required(): void
    {
        $group = CustomerGroup::create(['name' => ['en' => 'Wholesale', 'ru' => 'Оптовики']]);
        $buyer = $this->customer();
        $buyer->update(['customer_group_id' => $group->id]);

        $settings = app(SettingsManager::class);
        $settings->set('torgash.guard', 'two_factor_customers', Requirement::OFFERED);
        $settings->set('torgash.guard', 'two_factor_customer_groups', [$group->id]);

        $this->assertTrue(app(Requirement::class)->required($buyer->fresh()));

        $this->actingAs($buyer->fresh())
            ->get('http://localhost/account')
            ->assertRedirect(route('account.security'));
    }

    /**
     * Off means off, including for somebody who already had one. The shop
     * turned the feature off; asking them for a code they can no longer manage
     * on a page that no longer exists would be a lockout by settings change.
     */
    public function test_switching_it_off_for_an_audience_stops_asking_them(): void
    {
        $buyer = $this->customer();

        app(SettingsManager::class)->set('torgash.guard', 'two_factor_customers', Requirement::OFFERED);
        $this->armed($buyer);

        app(SettingsManager::class)->set('torgash.guard', 'two_factor_customers', Requirement::OFF);

        $this->actingAs($buyer->fresh());
        $this->get('http://localhost/')->assertOk();
        $this->get('http://localhost/account/security')->assertNotFound();
    }

    // ------------------------------------------------------------ turning it off

    public function test_turning_it_off_needs_the_password_and_is_refused_when_the_shop_requires_it(): void
    {
        $admin = $this->admin();
        $this->armed($admin);
        $this->actingAs($admin);
        app(Stamp::class)->confirm($admin);

        $this->delete('http://localhost/admin/guard/security', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        $this->assertNotNull(app(Enrolment::class)->active($admin));

        app(SettingsManager::class)->set('torgash.guard', 'two_factor_staff', Requirement::REQUIRED);

        $this->delete('http://localhost/admin/guard/security', ['current_password' => 'admin'])
            ->assertSessionHasErrors('current_password');
        $this->assertNotNull(app(Enrolment::class)->active($admin));

        app(SettingsManager::class)->set('torgash.guard', 'two_factor_staff', Requirement::OFFERED);

        $this->delete('http://localhost/admin/guard/security', ['current_password' => 'admin'])
            ->assertRedirect(route('admin.guard.security'));

        // The ROW goes, not a flag on it: a secret nothing will check again is
        // a credential the shop is keeping for no reason.
        $this->assertNull(app(Enrolment::class)->record($admin));
    }

    /** The way back in when the phone is gone, and it is not in the admin. */
    public function test_the_console_command_removes_one_and_can_relax_the_requirement(): void
    {
        $admin = $this->admin();
        $this->armed($admin);

        app(SettingsManager::class)->set('torgash.guard', 'two_factor_staff', Requirement::REQUIRED);

        $this->artisan('guard:two-factor', ['email' => $admin->email, '--off' => true])
            ->assertSuccessful();

        $this->assertNull(app(Enrolment::class)->record($admin->fresh()));

        $this->artisan('guard:two-factor', ['--relax' => true])->assertSuccessful();

        $this->assertSame(Requirement::OFFERED, setting('torgash.guard', 'two_factor_staff'));
        $this->assertFalse(app(Requirement::class)->required($admin->fresh()));

        // Nothing is taken away without --off being asked for.
        $this->armed($admin->fresh());
        $this->artisan('guard:two-factor', ['email' => $admin->email])->assertSuccessful();
        $this->assertNotNull(app(Enrolment::class)->active($admin->fresh()));
    }

    // -------------------------------------------------------- the screens

    /**
     * The enrolment page, both of them, drawn for real — the QR picture, the
     * key in groups of four for somebody who cannot scan it, and no code box
     * before there is a secret to answer about.
     *
     * A rendering test rather than a unit one on purpose: this page is the
     * whole feature for the person using it, and the two screens share one
     * partial precisely so that neither can quietly lose half of it.
     */
    public function test_both_enrolment_pages_draw_the_picture_and_the_key(): void
    {
        app(SettingsManager::class)->set('torgash.guard', 'two_factor_customers', Requirement::OFFERED);

        foreach ([
            [$this->admin(), 'http://localhost/admin/guard/security'],
            [$this->customer(), 'http://localhost/account/security'],
        ] as [$user, $url]) {
            $this->actingAs($user);

            // Nothing started yet: an invitation, and no secret anywhere near
            // the page.
            $page = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('<svg', $page, 'the theme icons at least');
            $this->assertStringNotContainsString('name="code"', $page);

            $record = app(Enrolment::class)->start($user);

            $page = $this->get($url)->assertOk()->getContent();

            // The picture itself, and the same secret in a form a human can
            // type. Both, because a camera that will not focus must not be the
            // end of the road.
            $this->assertStringContainsString('viewBox="0 0 190 190"', $page);
            $this->assertStringContainsString(Totp::readable($record->secret), $page);
            $this->assertStringContainsString('name="code"', $page);
        }
    }
    // -------------------------------------------------------- the other parts

    /**
     * The audit records the act and never the credential. A trail kept for six
     * months must not become the second place a working second factor is
     * written down.
     */
    public function test_the_audit_records_the_change_and_not_the_secret(): void
    {
        config(['guard.audit.enabled' => true]);
        AuditEntry::query()->delete();

        $admin = $this->admin();
        $record = app(Enrolment::class)->start($admin);

        $this->actingAs($admin)
            ->post('http://localhost/admin/guard/security/confirm', ['code' => Totp::code($record->secret)])
            ->assertRedirect(route('admin.guard.security'));

        $entries = AuditEntry::where('entity_type', TwoFactorSecret::class)->get();

        $this->assertNotEmpty($entries, 'arming a second factor is worth a row');

        foreach ($entries as $entry) {
            $written = json_encode($entry->changes, JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString($record->fresh()->secret, (string) $written);

            foreach ($record->fresh()->recovery_codes as $code) {
                $this->assertStringNotContainsString($code, (string) $written);
            }
        }
    }

    /**
     * The claim that makes this a PLACE rather than a list of paths: everything
     * credentialed in this engine is registered outside the `web` group, so a
     * machine is untouched by a person's phone. An API token belonging to an
     * account that is REQUIRED to have a second factor and does not have one
     * still works — otherwise switching this on would silently stop the 1C
     * exchange, a price feed and every gateway callback, and each of those
     * fails in a way that does not look like this.
     */
    public function test_a_machine_on_a_token_is_never_asked(): void
    {
        app(SettingsManager::class)->set('torgash.guard', 'two_factor_staff', Requirement::REQUIRED);

        $admin = $this->admin();
        [, $token] = ApiToken::issue($admin, 'Tests');

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('http://localhost/api/v2/admin/products')
            ->assertOk();
    }
}
