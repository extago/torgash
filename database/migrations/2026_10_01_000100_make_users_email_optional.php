<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An account may have no email (PLAN §44, step 3b): a shop that works by phone
 * lets a customer register, sign in and get back in with a code to a phone,
 * and let go of an email they no longer want on the account.
 *
 * NULL, never a made-up address: an invented one gets letters sent to it,
 * leaks into every export and CRM, and turns "has an email" into a rule every
 * reader has to remember. What is required is the shop's setting
 * (`core.account_contact`, `App\Core\Access\AccountContact`), enforced where an
 * account is made or changed. The unique index stays — Postgres counts NULLs
 * as distinct.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table users alter column email drop not null');
    }

    public function down(): void
    {
        // Put back only when nothing would break it: an account with no email
        // has to be given one by hand first.
        if (! DB::table('users')->whereNull('email')->exists()) {
            DB::statement('alter table users alter column email set not null');
        }
    }
};
