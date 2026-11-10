<?php

namespace Tests\Feature\Core;

use App\Core\Access\AccessGuard;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An address is one spelling. `Victim@example.com` and `victim@example.com`
 * were two accounts to the unique index and one to the sign-in by code, which
 * took whichever row came first: the real owner typed their own address, proved
 * it from their own inbox, and could land in an account a stranger held the
 * password of.
 */
class EmailOneSpellingTest extends TestCase
{
    protected const MIGRATION = 'database/migrations/2026_11_09_000200_make_user_emails_one_spelling.php';

    public function test_an_address_is_kept_in_one_spelling(): void
    {
        $user = User::create(['name' => 'A', 'email' => '  Someone@Example.TEST ', 'password' => 'long-enough-1']);

        $this->assertSame('someone@example.test', $user->fresh()->email);

        $user->update(['email' => 'Other@Example.TEST']);
        $this->assertSame('other@example.test', $user->fresh()->email);

        $user->forceFill(['email' => ''])->save();
        $this->assertNull($user->fresh()->email, 'no address is no address, not an empty one');
    }

    public function test_registering_an_address_again_in_other_capitals_is_refused(): void
    {
        User::create(['name' => 'Owner', 'email' => 'victim@example.test', 'password' => 'long-enough-1']);

        $this->post('http://localhost/account/register', [
            'name' => 'Stranger', 'email' => 'Victim@Example.test',
            'password' => 'long-enough-2', 'password_confirmation' => 'long-enough-2',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(1, User::whereRaw('lower(email) = ?', ['victim@example.test'])->count());
    }

    public function test_the_admin_forms_refuse_it_and_an_account_may_keep_its_own(): void
    {
        $admin = User::where('email', 'admin@torgash.local')->firstOrFail();
        User::create(['name' => 'Taken', 'email' => 'taken@example.test', 'password' => 'long-enough-1']);
        $customer = User::create(['name' => 'Customer', 'email' => 'customer@example.test', 'password' => 'long-enough-1']);

        $this->actingAs($admin)->put('http://localhost/admin/customers/'.$customer->id, ['name' => 'Customer', 'email' => 'TAKEN@example.test'])
            ->assertSessionHasErrors('email');

        $this->actingAs($admin)->put('http://localhost/admin/customers/'.$customer->id, ['name' => 'Customer', 'email' => 'CUSTOMER@example.test'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('http://localhost/admin/administrators', ['name' => 'Second', 'email' => 'TAKEN@example.test'])
            ->assertSessionHasErrors('email');
    }

    public function test_the_database_refuses_it_whatever_wrote_it(): void
    {
        User::create(['name' => 'Owner', 'email' => 'victim@example.test', 'password' => 'long-enough-1']);

        $this->expectException(QueryException::class);

        DB::table('users')->insert(['name' => 'Raw', 'email' => 'VICTIM@example.test', 'password' => 'x']);
    }

    public function test_signing_in_does_not_depend_on_capitals(): void
    {
        $customer = User::create(['name' => 'Owner', 'email' => 'buyer@example.test', 'password' => 'long-enough-1']);

        $this->post('http://localhost/account/login', ['email' => 'Buyer@Example.TEST', 'password' => 'long-enough-1']);
        $this->assertAuthenticatedAs($customer);

        auth()->logout();

        $this->post('http://localhost/admin/login', ['email' => 'Admin@Torgash.LOCAL', 'password' => 'admin']);
        $this->assertAuthenticatedAs(User::where('email', 'admin@torgash.local')->firstOrFail());
    }

    public function test_an_import_finds_the_account_in_other_capitals(): void
    {
        $customer = User::create(['name' => 'Owner', 'email' => 'buyer@example.test', 'password' => 'long-enough-1']);

        $found = hook_filter('exim.match.customer', null, ['row' => ['email' => 'BUYER@example.test']]);

        $this->assertSame($customer->id, $found['model']->id);
    }

    /**
     * A shop updating may already hold both. The account that keeps the address
     * is the one a mistake would hurt most: staff, then an address someone
     * proved, then whoever had it first. The other loses it, and is named.
     */
    public function test_the_update_leaves_one_account_for_an_address(): void
    {
        DB::statement('drop index users_email_lower_unique');

        $customer = $this->row('Shared@example.test');
        $staff = $this->row('shared@example.test');
        User::findOrFail($staff)->assignRole(Role::findOrCreate(AccessGuard::SUPER_ROLE));

        $unproven = $this->row('Proven@example.test');
        $proven = $this->row('proven@example.test', ['email_verified_at' => now()]);

        $first = $this->row('Second@example.test');
        $later = $this->row('SECOND@example.test', ['email_verified_at' => null]);

        $alone = $this->row('Alone@Example.test');

        Log::spy();

        (require base_path(self::MIGRATION))->up();

        $this->assertSame('shared@example.test', DB::table('users')->where('id', $staff)->value('email'));
        $this->assertNull(DB::table('users')->where('id', $customer)->value('email'));

        $this->assertSame('proven@example.test', DB::table('users')->where('id', $proven)->value('email'));
        $this->assertNull(DB::table('users')->where('id', $unproven)->value('email'));

        $this->assertSame('second@example.test', DB::table('users')->where('id', $first)->value('email'));
        $this->assertNull(DB::table('users')->where('id', $later)->value('email'));

        $this->assertSame('alone@example.test', DB::table('users')->where('id', $alone)->value('email'));

        $this->assertTrue(DB::table('pg_indexes')->where('indexname', 'users_email_lower_unique')->exists());

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message) => str_contains($message, (string) $customer) && str_contains($message, (string) $unproven) && str_contains($message, (string) $later)
        );
    }

    protected function row(string $email, array $more = []): int
    {
        return DB::table('users')->insertGetId($more + ['name' => $email, 'email' => $email, 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }
}
