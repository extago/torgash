<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An address becomes one spelling (`User::normalEmail`), and the database holds
 * to it. A shop may already have the same address on two accounts in different
 * capitals. The one that keeps it is the one a mistake would hurt most: staff,
 * then an address someone proved, then whoever had it first. The other loses
 * only the address (a phone still signs in), and the log names it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $lost = [];

        $shared = DB::table('users')->whereNotNull('email')
            ->selectRaw('lower(email) as address')->groupByRaw('lower(email)')->havingRaw('count(*) > 1')
            ->pluck('address');

        foreach ($shared as $address) {
            $accounts = User::query()->with('roles')->whereRaw('lower(email) = ?', [$address])->get()
                ->sortBy(fn (User $user) => [$user->roles->isEmpty(), $user->email_verified_at === null, $user->id]);

            foreach ($accounts->slice(1) as $account) {
                DB::table('users')->where('id', $account->id)->update(['email' => null, 'email_verified_at' => null]);
                $lost[] = $account->id;
            }
        }

        DB::statement('update users set email = lower(email) where email <> lower(email)');
        DB::statement('create unique index users_email_lower_unique on users (lower(email))');

        if ($lost !== []) {
            logger()->error('These accounts held an address another account has in different capitals, and have lost it. Check them on the Customers screen: '.implode(', ', $lost));
        }
    }

    public function down(): void
    {
        DB::statement('drop index if exists users_email_lower_unique');
    }
};
