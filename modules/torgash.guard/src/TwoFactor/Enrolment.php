<?php

namespace Torgash\Guard\TwoFactor;

use App\Models\User;
use Torgash\Guard\Models\TwoFactorSecret;

/**
 * The one place a second factor is created, armed, answered or taken away.
 *
 * The same shape as `PlaceOrder` for an order or `Submit` for an enquiry, and
 * for the same reason: there are four doors into this — the manager's own
 * security page, the customer's, the challenge screen and a console command —
 * and four copies of "is this code right, and what does accepting it mean"
 * would be four chances to get the single-use rule wrong.
 *
 * Enrolment is deliberately two acts. `start()` writes an UNCONFIRMED row and
 * hands back a secret to show; `confirm()` arms it, but only against a code the
 * person read off their own phone. Anything else — a shop that armed it the
 * moment the QR was drawn — locks out everybody whose camera did not work, and
 * they find out at the next login rather than now.
 */
class Enrolment
{
    /**
     * How many recovery codes, and what they look like.
     *
     * Eight is enough to survive a lost phone and a couple of panicked
     * mistakes, and few enough to fit on the piece of paper this is meant to be
     * written on. The alphabet has no `0`/`o` and no `1`/`l` in it, because
     * these are read out loud and typed by somebody who has already had a bad
     * morning.
     */
    public const CODES = 8;

    protected const CODE_ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';

    /** A wrong TOTP code, a wrong recovery code, and what accepting one was. */
    public const BY_APP = 'app';

    public const BY_RECOVERY = 'recovery';

    public function record(User $user): ?TwoFactorSecret
    {
        return TwoFactorSecret::where('user_id', $user->getKey())->first();
    }

    /** Their second factor if they actually have one — armed, not half-set-up. */
    public function active(User $user): ?TwoFactorSecret
    {
        $record = $this->record($user);

        return $record?->isConfirmed() ? $record : null;
    }

    /**
     * Begin, or begin again.
     *
     * An unconfirmed row is replaced outright rather than reused: somebody who
     * opens the page twice has two QR codes on two screens, and the only safe
     * reading is that the newest one is the one they are looking at. An ARMED
     * row is left alone — turning an existing second factor off is a separate,
     * deliberate act, and never a side effect of visiting a page.
     */
    public function start(User $user): TwoFactorSecret
    {
        if ($existing = $this->active($user)) {
            return $existing;
        }

        $record = $this->record($user) ?? new TwoFactorSecret(['user_id' => $user->getKey()]);

        $record->fill([
            'secret' => Totp::secret(),
            'recovery_codes' => null,
            'confirmed_at' => null,
            'last_timestep' => null,
            'last_used_at' => null,
        ])->save();

        return $record;
    }

    /**
     * Arm it — and mint the recovery codes at the same moment, which is the
     * only moment they can honestly be shown: before there is anything to lose.
     *
     * Returns the codes, once. They are stored (encrypted) so the page can show
     * them again, but a person who never saw them at all is a person who will
     * discover that on the day their phone is gone.
     *
     * @return array<int, string>|null the codes, or null if the code was wrong
     */
    public function confirm(User $user, string $code): ?array
    {
        $record = $this->record($user);

        if (! $record || $record->isConfirmed()) {
            return null;
        }

        $step = Totp::verify($record->secret, $code, $record->last_timestep);

        if ($step === null) {
            return null;
        }

        $codes = $this->newCodes();

        $record->fill([
            'confirmed_at' => now(),
            'last_timestep' => $step,
            'last_used_at' => now(),
            'recovery_codes' => $codes,
        ])->save();

        return $codes;
    }

    /**
     * The answer at the door: six digits from the app, or one recovery code.
     *
     * Returns HOW it was answered, because the two mean different things to the
     * person — a recovery code spent is one fewer, and they have to be told
     * that at the moment it happens rather than left to find out.
     */
    public function verify(User $user, string $answer): ?string
    {
        $record = $this->active($user);

        if (! $record) {
            return null;
        }

        $step = Totp::verify($record->secret, $answer, $record->last_timestep);

        if ($step !== null) {
            $record->forceFill(['last_timestep' => $step, 'last_used_at' => now()])->save();

            return self::BY_APP;
        }

        return $this->spendRecoveryCode($record, $answer) ? self::BY_RECOVERY : null;
    }

    /**
     * A recovery code is REMOVED by being used, which is the whole of what
     * makes it single-use — there is no "used" flag to forget to check.
     */
    protected function spendRecoveryCode(TwoFactorSecret $record, string $answer): bool
    {
        $answer = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $answer) ?? '');
        $left = [];
        $found = false;

        foreach ($record->recovery_codes ?? [] as $stored) {
            // Compared without its dash and timing-safely, and every code is
            // walked even after a match: returning early would leak which one
            // it was by how long the answer took.
            if (! $found && hash_equals(str_replace('-', '', $stored), $answer)) {
                $found = true;

                continue;
            }

            $left[] = $stored;
        }

        if ($found) {
            $record->forceFill(['recovery_codes' => $left, 'last_used_at' => now()])->save();
        }

        return $found;
    }

    /** A fresh set, replacing whatever is left of the old one. */
    public function regenerateCodes(User $user): array
    {
        $record = $this->active($user);

        if (! $record) {
            return [];
        }

        $codes = $this->newCodes();
        $record->forceFill(['recovery_codes' => $codes])->save();

        return $codes;
    }

    /**
     * Off. The row goes rather than being flagged: a secret nothing will ever
     * check again is a credential the shop is storing for no reason, and this
     * module's whole argument for living in its own table is that switching it
     * off leaves nothing behind.
     */
    public function disable(User $user): void
    {
        $this->record($user)?->delete();
    }

    /** @return array<int, string> */
    protected function newCodes(): array
    {
        return array_map(fn () => $this->newCode(), range(1, self::CODES));
    }

    protected function newCode(): string
    {
        $alphabet = self::CODE_ALPHABET;
        $code = '';

        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        // Written with a dash so it reads as two halves — it is going onto
        // paper, and the dash is ignored on the way back in.
        return substr($code, 0, 5).'-'.substr($code, 5);
    }
}
