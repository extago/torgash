<?php

namespace Tests\Feature\Core;

use App\Core\Access\LoginThrottle;
use App\Core\Support\ClientAddress;
use App\Models\User;
use Tests\TestCase;

/**
 * A password box that pauses.
 *
 * Both sign-in forms used to answer a wrong password as fast as anybody could
 * ask, for ever: the guard's blocking counts failures, but it watches by
 * default and is a module a shop may not have. The pause is core's now, and
 * always on — see `App\Core\Access\LoginThrottle` for the two counters and why
 * there is no third one per account.
 */
class LoginThrottleTest extends TestCase
{
    public function test_the_admin_door_pauses_after_five_wrong_passwords(): void
    {
        $this->wrongAdminPasswords(LoginThrottle::PER_LOGIN);

        // The right password, too late: refused before it is even checked.
        $this->post('http://localhost/admin/login', ['email' => 'admin@torgash.local', 'password' => 'admin'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('Too many attempts to sign in', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_the_pause_ends(): void
    {
        $this->wrongAdminPasswords(LoginThrottle::PER_LOGIN);

        $this->travel(LoginThrottle::PER_LOGIN_SECONDS + 1)->seconds();

        $this->post('http://localhost/admin/login', ['email' => 'admin@torgash.local', 'password' => 'admin'])
            ->assertRedirect();

        $this->assertAuthenticated();
    }

    /** A person who mistyped and then got it right starts from nothing next time. */
    public function test_the_right_password_forgives_that_logins_misses(): void
    {
        $this->wrongAdminPasswords(LoginThrottle::PER_LOGIN - 1);

        $this->post('http://localhost/admin/login', ['email' => 'admin@torgash.local', 'password' => 'admin']);
        $this->assertAuthenticated();
        $this->post('http://localhost/admin/logout');

        $this->wrongAdminPasswords(LoginThrottle::PER_LOGIN - 1);

        $this->post('http://localhost/admin/login', ['email' => 'admin@torgash.local', 'password' => 'admin']);
        $this->assertAuthenticated();
    }

    public function test_the_customer_door_pauses_too(): void
    {
        User::create(['name' => 'Buyer', 'email' => 'buyer@example.test', 'password' => 'secret123']);

        for ($i = 0; $i < LoginThrottle::PER_LOGIN; $i++) {
            $this->post('http://localhost/account/login', ['email' => 'buyer@example.test', 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }

        $this->post('http://localhost/account/login', ['email' => 'buyer@example.test', 'password' => 'secret123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** One address walking a list of accounts is stopped, whichever accounts it tries. */
    public function test_one_client_trying_many_logins_is_paused(): void
    {
        for ($i = 0; $i < LoginThrottle::PER_CLIENT; $i++) {
            $this->post('http://localhost/account/login', ['email' => "nobody{$i}@example.test", 'password' => 'wrong']);
        }

        User::create(['name' => 'Buyer', 'email' => 'buyer@example.test', 'password' => 'secret123']);

        $this->post('http://localhost/account/login', ['email' => 'buyer@example.test', 'password' => 'secret123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** A new last group of an IPv6 address is the same client, not a fresh allowance. */
    public function test_an_ipv6_client_is_its_whole_64(): void
    {
        for ($i = 1; $i <= LoginThrottle::PER_LOGIN; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::'.dechex($i)])
                ->post('http://localhost/admin/login', ['email' => 'admin@torgash.local', 'password' => 'wrong']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:aaaa:bbbb:cccc:dddd'])
            ->post('http://localhost/admin/login', ['email' => 'admin@torgash.local', 'password' => 'admin'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_client_is_an_ipv4_address_or_an_ipv6_64(): void
    {
        $this->assertSame('203.0.113.9', ClientAddress::subject('203.0.113.9'));
        $this->assertSame('203.0.113.9', ClientAddress::subject('::ffff:203.0.113.9'));
        $this->assertSame('2001:db8:1:2::/64', ClientAddress::subject('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        $this->assertSame('2001:db8:1:2::/64', ClientAddress::subject('2001:db8:1:2::/64'));
        $this->assertSame('not an address', ClientAddress::subject(' not an address '));
    }

    protected function wrongAdminPasswords(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->post('http://localhost/admin/login', ['email' => 'admin@torgash.local', 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }
    }
}
