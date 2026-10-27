<?php

namespace Torgash\Guard\Console;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Console\Command;
use Torgash\Guard\Models\TwoFactorSecret;
use Torgash\Guard\TwoFactor\Enrolment;
use Torgash\Guard\TwoFactor\Requirement;

/**
 * The way back in when the phone is gone — the same rule as
 * `guard:unblock`, and the same reason for it.
 *
 * A second factor is a door that can close on the person holding the key: a
 * phone dropped in a river, an authenticator app restored onto a handset
 * without its keychain, a manager who left. The recovery codes are the first
 * answer and this is the second, and it deliberately does NOT live in the
 * admin, because "log in to turn off the thing stopping you logging in" is not
 * a way out of anything.
 *
 *     php artisan guard:two-factor
 *     php artisan guard:two-factor manager@shop.test --off
 *     php artisan guard:two-factor --all --off
 *     php artisan guard:two-factor --relax
 *
 * `--relax` is the other half of the same emergency, exactly as `--watch` is
 * for blocking: a shop that ticked "required of everybody" and then found its
 * one administrator cannot enrol — no phone at hand, a broken camera, a clock
 * an hour out — is locked out of its own admin by a setting it can only reach
 * from inside. This puts the requirement back to "offered", which refuses
 * nobody and takes nothing away from the people who already have one.
 *
 * `--off` is asked for explicitly and never inferred from an email being
 * typed: everything this command can do to somebody weakens their account.
 */
class TwoFactorCommand extends Command
{
    protected $signature = 'guard:two-factor
        {email? : Whose second factor to look at, or to remove}
        {--off : Actually remove it — nothing is taken away without this}
        {--all : Every account that has one}
        {--relax : Stop REQUIRING it; those who have one keep it}';

    protected $description = 'Look at, or take away, a second factor — the way back in when a phone is gone.';

    public function handle(Enrolment $enrolment, Requirement $requirement, SettingsManager $settings): int
    {
        if ($this->option('relax')) {
            $this->relax($settings);
        }

        $email = (string) $this->argument('email');

        if ($this->option('all')) {
            return $this->all($enrolment);
        }

        if ($email === '') {
            return $this->option('relax') ? self::SUCCESS : $this->state($requirement);
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No account with the address {$email}.");

            return self::FAILURE;
        }

        $record = $enrolment->record($user);

        if (! $record) {
            $this->info("{$email} has no second factor. Nothing to do.");

            return self::SUCCESS;
        }

        if (! $this->option('off')) {
            $this->line($record->isConfirmed()
                ? "{$email}: on since {$record->confirmed_at->toDateTimeString()}, {$record->codesLeft()} recovery code(s) left."
                : "{$email}: an enrolment that was never finished. It stops nobody from signing in.");
            $this->comment('Pass --off to remove it.');

            return self::SUCCESS;
        }

        $enrolment->disable($user);

        $this->info("Removed. {$email} signs in with their password alone, and can set a new one up on the Security page.");

        if ($requirement->required($user->fresh())) {
            // Said out loud, because otherwise this looks like it did nothing:
            // the shop demands one, so the next page they open is the enrolment
            // screen. Which is usually exactly right — a new phone — and
            // occasionally not, which is what --relax is for.
            $this->comment('This shop REQUIRES one of them, so they will be asked to enrol on their next page. Add --relax if that is not what you want.');
        }

        return self::SUCCESS;
    }

    protected function all(Enrolment $enrolment): int
    {
        $count = TwoFactorSecret::count();

        if (! $this->option('off')) {
            $this->line("{$count} account(s) have a second factor. Pass --off to remove every one of them.");

            return self::SUCCESS;
        }

        TwoFactorSecret::query()->delete();

        $this->info("Removed: {$count}. Everybody signs in with their password alone.");

        return self::SUCCESS;
    }

    protected function relax(SettingsManager $settings): void
    {
        foreach (['two_factor_staff', 'two_factor_customers'] as $key) {
            if (setting('torgash.guard', $key) === Requirement::REQUIRED) {
                $settings->set('torgash.guard', $key, Requirement::OFFERED);
                $this->info("{$key}: no longer required. It is still offered.");
            }
        }

        // The group lists are the other way of requiring it, and a shop that
        // has locked itself out through one of those has to be told which
        // knob did it — clearing them silently would leave the setting screen
        // saying something different from what happened.
        foreach (['two_factor_staff_groups', 'two_factor_customer_groups'] as $key) {
            if ((array) setting('torgash.guard', $key, []) !== []) {
                $settings->set('torgash.guard', $key, []);
                $this->info("{$key}: cleared, so no group is required to have one.");
            }
        }
    }

    protected function state(Requirement $requirement): int
    {
        $this->line('Staff:     '.(string) setting('torgash.guard', 'two_factor_staff', Requirement::OFFERED));
        $this->line('Customers: '.(string) setting('torgash.guard', 'two_factor_customers', Requirement::OFF));
        $this->newLine();

        $records = TwoFactorSecret::with('user')->orderBy('id')->get();

        if ($records->isEmpty()) {
            $this->comment('Nobody has a second factor yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Account', 'State', 'Codes left', 'Last used'],
            $records->map(fn (TwoFactorSecret $r) => [
                $r->user?->email ?? '#'.$r->user_id,
                $r->isConfirmed() ? 'on' : 'unfinished',
                $r->codesLeft(),
                $r->last_used_at?->toDateTimeString() ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
