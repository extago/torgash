<?php

namespace Tests\Feature\Core;

use App\Core\Access\AccessGuard;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * admin/admin is a developer's convenience and never a live shop's door:
 * outside local/testing the seeder makes no such account, the password is
 * refused at both login forms, and the owner comes from `torgash:admin`.
 */
class InstallPasswordTest extends TestCase
{
    protected function live(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        // Outside `testing` the CSRF check stops skipping itself; it is not what is measured here.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    }

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');

        parent::tearDown();
    }

    public function test_the_installation_password_works_on_a_developers_machine(): void
    {
        $this->post('http://localhost/admin/login', ['email' => 'admin@torgash.local', 'password' => 'admin'])
            ->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_a_live_shop_refuses_it_at_both_doors(): void
    {
        $this->live();

        $this->post('http://localhost/admin/login', ['email' => 'admin@torgash.local', 'password' => 'admin'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('http://localhost/account/login', ['email' => 'admin@torgash.local', 'password' => 'admin'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_live_seeder_makes_no_administrator(): void
    {
        $this->live();
        User::where('email', 'admin@torgash.local')->delete();

        (new CoreSeeder)->run();

        $this->assertNull(User::where('email', 'admin@torgash.local')->first());
    }

    public function test_the_console_makes_the_owner_and_resets_a_password(): void
    {
        $this->artisan('torgash:admin', ['email' => 'Owner@Shop.test', '--password' => 'a-long-enough-one', '--name' => 'Olena'])
            ->assertSuccessful();

        $owner = User::where('email', 'owner@shop.test')->firstOrFail();
        $this->assertSame('Olena', $owner->name);
        $this->assertTrue($owner->hasRole(AccessGuard::SUPER_ROLE));
        $this->assertTrue(Hash::check('a-long-enough-one', $owner->password));

        $this->artisan('torgash:admin', ['email' => 'owner@shop.test', '--password' => 'another-long-one'])->assertSuccessful();
        $this->assertTrue(Hash::check('another-long-one', $owner->fresh()->password));
        $this->assertSame(1, User::where('email', 'owner@shop.test')->count());
    }

    public function test_the_console_refuses_a_weak_password_and_makes_one_up_when_asked_nothing(): void
    {
        $this->artisan('torgash:admin', ['email' => 'weak@shop.test', '--password' => 'admin'])->assertFailed();
        $this->artisan('torgash:admin', ['email' => 'weak@shop.test', '--password' => 'short'])->assertFailed();
        $this->assertNull(User::where('email', 'weak@shop.test')->first());

        $this->artisan('torgash:admin', ['email' => 'quiet@shop.test', '--no-interaction' => true])
            ->expectsOutputToContain('Password: ')
            ->assertSuccessful();
        $this->assertNotNull(User::where('email', 'quiet@shop.test')->first());
    }
}
