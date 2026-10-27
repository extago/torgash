<?php

namespace App\Core\Access;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * `torgash:admin` — the shop's owner, made or given a new password from the
 * server's console. This is how a live install gets its first account (the
 * seeder makes `admin@torgash.local` / `admin` only on a developer's
 * machine), and the way back in when the owner's password is forgotten and
 * nobody else can reset it.
 */
class AdminCommand extends Command
{
    protected $signature = 'torgash:admin
        {email : The owner\'s email — made if new, given a new password if not}
        {--name= : The name shown in the admin (for a new account)}
        {--password= : The password; left out, it is asked for, or made up when nobody can be asked}';

    protected $description = 'Create the shop owner\'s account, or set a new password for it';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->error("\"$email\" is not an email address.");

            return self::FAILURE;
        }

        [$password, $madeUp] = $this->password();
        if ($password === null) {
            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $new = ! $user->exists;
        $user->name = $this->option('name') ?: ($user->name ?: Str::before($email, '@'));
        $user->password = $password;
        $user->email_verified_at ??= now();
        $user->save();
        $user->assignRole(Role::findOrCreate(AccessGuard::SUPER_ROLE));

        $this->info(($new ? 'Created' : 'New password set for')." $email — an administrator.");
        if ($madeUp) {
            $this->line("Password: $password");
            $this->warn('Shown once. Write it down, sign in and change it in your profile.');
        }

        return self::SUCCESS;
    }

    /** @return array{0: ?string, 1: bool}  the password, and whether it was made up here */
    protected function password(): array
    {
        $typed = $this->option('password');

        if ($typed === null && $this->input->isInteractive()) {
            $typed = (string) $this->secret('Password (leave empty to have one made up)');
            if ($typed !== '' && $typed !== (string) $this->secret('The same again')) {
                $this->error('The two passwords differ.');

                return [null, false];
            }
        }

        if ($typed === null || $typed === '') {
            return [Str::password(20, symbols: false), true];
        }

        $check = Validator::make(['password' => $typed], ['password' => ['required', Password::min(10), 'not_in:'.InstallPassword::PASSWORD]]);
        if ($check->fails()) {
            $this->error($check->errors()->first('password'));

            return [null, false];
        }

        return [$typed, false];
    }
}
