<?php

namespace Tests\Feature\Core;

use App\Core\Access\PasswordSessions;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * A new password is its owner saying "only I get in now". Whoever was in with
 * the old one, by a session, a remembered browser or a link still in a mailbox,
 * is out.
 */
class PasswordChangeTest extends TestCase
{
    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->customer = User::create(['name' => 'Buyer', 'email' => 'buyer-'.uniqid().'@example.test', 'password' => 'first-password']);
    }

    protected function signIn(): void
    {
        $this->post('http://localhost/account/login', ['email' => $this->customer->email, 'password' => 'first-password']);

        $this->assertAuthenticatedAs($this->customer);
    }

    /** What a change made on another device leaves behind: the row, and nothing in this session. */
    protected function changedElsewhere(): void
    {
        DB::table('users')->where('id', $this->customer->id)->update(['password' => Hash::make('second-password')]);

        Auth::forgetGuards();
    }

    // -------------------------------------------------------------- sessions

    public function test_a_password_changed_elsewhere_ends_this_session(): void
    {
        $this->signIn();
        $this->get('http://localhost/account')->assertOk();

        $this->changedElsewhere();

        $this->get('http://localhost/account')->assertRedirect(route('account.login'));
        $this->assertGuest();
    }

    public function test_a_public_page_goes_on_for_them_as_a_guest(): void
    {
        $this->signIn();
        $this->changedElsewhere();

        $this->get('http://localhost/')->assertOk();

        $this->assertGuest();
        $this->assertNull(session(PasswordSessions::SESSION), 'a session that ended holds nothing of it');
    }

    public function test_the_session_that_changed_it_goes_on(): void
    {
        $this->signIn();

        $this->put('http://localhost/account/password', [
            'current_password' => 'first-password',
            'password' => 'second-password',
            'password_confirmation' => 'second-password',
        ])->assertRedirect();

        Auth::forgetGuards();

        $this->get('http://localhost/account')->assertOk();
    }

    /** A manager editing their own row on the staff screen holds a copy of it, not `$request->user()`. */
    public function test_a_change_through_another_copy_of_the_row_keeps_the_session_it_was_made_in(): void
    {
        $this->signIn();

        User::find($this->customer->id)->forceFill(['password' => 'second-password'])->save();
        Auth::forgetGuards();

        $this->get('http://localhost/account')->assertOk();
    }

    public function test_a_session_from_before_this_check_is_not_thrown_out(): void
    {
        $this->signIn();
        session()->forget(PasswordSessions::SESSION);
        Auth::forgetGuards();

        $this->get('http://localhost/account')->assertOk();

        $this->assertNotNull(session(PasswordSessions::SESSION.'.'.$this->customer->id));
    }

    public function test_signing_in_with_the_new_password_is_not_held_against_an_old_stamp(): void
    {
        $this->signIn();
        Auth::logout();
        $this->changedElsewhere();

        $this->post('http://localhost/account/login', ['email' => $this->customer->email, 'password' => 'second-password']);
        Auth::forgetGuards();

        $this->get('http://localhost/account')->assertOk();
    }

    /** The manager's own stamp, and the customer they are looking as is somebody else. */
    public function test_looking_as_a_customer_does_not_end_the_managers_session(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $this->post('http://localhost/admin/login', ['email' => $admin->email, 'password' => 'admin']);
        $this->assertAuthenticatedAs($admin);

        $this->post(route('admin.customers.impersonate', $this->customer))->assertRedirect();
        Auth::forgetGuards();

        $this->get('http://localhost/account')->assertOk();
        $this->assertTrue(Auth::user()->is($this->customer), 'the storefront request is the customer\'s');
        Auth::forgetGuards();

        $this->get('http://localhost/account')->assertOk();
        Auth::forgetGuards();

        $this->get('http://localhost/admin')->assertOk();
    }

    // ------------------------------------------------- what else stops working

    public function test_a_password_set_by_any_door_takes_the_remembered_browsers_and_the_links_with_it(): void
    {
        $this->customer->forceFill(['remember_token' => 'old-token'])->saveQuietly();
        $reset = Password::broker('users')->createToken($this->customer);
        $invite = Password::broker('invites')->createToken($this->customer);

        $copy = User::find($this->customer->id);
        $copy->password = 'second-password';
        $copy->save();

        $copy = $copy->fresh();
        $this->assertNotSame('old-token', $copy->remember_token);
        $this->assertFalse(Password::broker('users')->tokenExists($copy, $reset));
        $this->assertFalse(Password::broker('invites')->tokenExists($copy, $invite));
    }

    public function test_saving_anything_else_leaves_them_alone(): void
    {
        $this->customer->forceFill(['remember_token' => 'old-token'])->saveQuietly();
        $reset = Password::broker('users')->createToken($this->customer);

        $this->customer->update(['name' => 'Another name']);

        $copy = $this->customer->fresh();
        $this->assertSame('old-token', $copy->remember_token);
        $this->assertTrue(Password::broker('users')->tokenExists($copy, $reset));
    }

    // ------------------------------------------------- a link lasts as long as it says

    /** @return array<string, string> */
    protected function newPassword(User $user, string $token): array
    {
        return ['token' => $token, 'email' => $user->email, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'];
    }

    public function test_the_invite_flag_does_not_stretch_a_reset_link(): void
    {
        $staff = User::role('admin')->firstOrFail();
        $token = Password::broker('users')->createToken($staff);
        $this->travel(2)->days();

        foreach ([[], ['invite' => 1]] as $extra) {
            $this->from('http://localhost/admin/password/reset/x')
                ->post('http://localhost/admin/password/reset', $this->newPassword($staff, $token) + $extra)
                ->assertSessionHasErrors('email');
        }

        $this->assertFalse(Hash::check('new-password-1', $staff->fresh()->password));
    }

    public function test_an_invitation_still_lasts_its_week(): void
    {
        $staff = User::role('admin')->firstOrFail();
        $token = Password::broker('invites')->createToken($staff);
        $this->travel(6)->days();

        $this->post('http://localhost/admin/password/reset', $this->newPassword($staff, $token) + ['invite' => 1])
            ->assertRedirect(route('admin.login'));

        $this->assertTrue(Hash::check('new-password-1', $staff->fresh()->password));
    }

    public function test_an_invitation_is_not_a_reset_link(): void
    {
        $staff = User::role('admin')->firstOrFail();
        $token = Password::broker('invites')->createToken($staff);

        $this->from('http://localhost/admin/password/reset/x')
            ->post('http://localhost/admin/password/reset', $this->newPassword($staff, $token))
            ->assertSessionHasErrors('email');
    }
}
