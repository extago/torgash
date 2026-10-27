<?php

namespace Tests\Feature\Core;

use App\Core\Mail\EventMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A password is chosen from a LINK in a letter, never sent in one (PLAN §41):
 * a manager who lost theirs, a manager just added, a customer made in the
 * admin. The admin's door answers the same whoever asks, and setting a
 * manager's password does not sign them in — the login and its second factor
 * follow.
 */
class PasswordLinksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /** The link the letter carries. */
    protected function linkFrom(string $event): string
    {
        $link = '';
        Mail::assertQueued(EventMail::class, function (EventMail $mail) use ($event, &$link) {
            $link = (string) ($mail->payload['link'] ?? '');

            return $mail->event === $event;
        });

        return $link;
    }

    public function test_a_manager_sets_a_new_password_from_the_link_and_then_signs_in(): void
    {
        $manager = User::role('admin')->firstOrFail();

        $this->post('http://localhost/admin/password/forgot', ['email' => strtoupper($manager->email)])->assertSessionHas('status');
        $link = $this->linkFrom('account.staff_reset');
        $this->assertStringContainsString('/admin/password/reset/', $link);

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $token = basename((string) parse_url($link, PHP_URL_PATH));

        $this->get($link)->assertOk();
        $this->post('http://localhost/admin/password/reset', [
            'token' => $token, 'email' => $query['email'], 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ])->assertRedirect(route('admin.login'));

        $this->assertTrue(Hash::check('brand-new-pass', $manager->fresh()->password));
        $this->assertGuest();
    }

    public function test_the_admins_door_says_nothing_about_who_works_here(): void
    {
        $customer = User::factory()->create();

        $this->post('http://localhost/admin/password/forgot', ['email' => $customer->email])->assertSessionHas('status');
        $this->post('http://localhost/admin/password/forgot', ['email' => 'nobody-'.uniqid().'@example.com'])->assertSessionHas('status');

        Mail::assertNothingQueued();
    }

    public function test_a_customer_made_in_the_admin_chooses_their_password_and_is_in(): void
    {
        $email = 'invited-'.uniqid().'@example.com';

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/customers', ['name' => 'Olena', 'email' => $email, 'send_invite' => '1'])
            ->assertRedirect();
        $this->post('http://localhost/admin/logout');

        $link = $this->linkFrom('account.customer_invite');
        $token = basename((string) parse_url($link, PHP_URL_PATH));

        $this->get($link)->assertOk();
        $this->post('http://localhost/account/password/set', [
            'token' => $token, 'email' => $email, 'password' => 'chosen-pass', 'password_confirmation' => 'chosen-pass',
        ])->assertRedirect(route('account.dashboard'));

        $customer = User::where('email', $email)->firstOrFail();
        $this->assertAuthenticatedAs($customer);
        $this->assertNotNull($customer->email_verified_at);
    }

    public function test_a_new_manager_can_be_invited_instead_of_given_a_password(): void
    {
        $email = 'new-manager-'.uniqid().'@example.com';

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/administrators', ['name' => 'Taras', 'email' => $email, 'roles' => ['admin'], 'send_invite' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('invite=1', $this->linkFrom('account.staff_invite'));
    }
}
