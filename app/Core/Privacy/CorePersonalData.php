<?php

namespace App\Core\Privacy;

use App\Models\Address;
use App\Models\VerificationCode;
use Carbon\CarbonInterface;

/**
 * Core's own answer to `core.personal_data` (PLAN §49): the account, its
 * saved addresses, and the verification codes — which carry an address, a
 * phone and an IP and are worth nothing a month later.
 *
 * The account itself is not erased here: deleting a user is the privacy
 * module's last step, through the model, so every owner's
 * `core.user.deleting` still runs.
 */
class CorePersonalData
{
    public static function register(PersonalData $registry): void
    {
        $registry->register('account', [
            'label' => 'Account',
            'export' => fn (Subject $s) => $s->user ? [[
                'name' => $s->user->name,
                'email' => $s->user->email,
                'phone' => $s->user->phone,
                'created_at' => $s->user->created_at?->toIso8601String(),
            ]] : [],
        ]);

        $registry->register('addresses', [
            'label' => 'Saved addresses',
            'export' => fn (Subject $s) => $s->user
                ? Address::where('user_id', $s->user->id)->get(['label', 'full_name', 'phone', 'address_line', 'city', 'postal_code'])->toArray()
                : [],
            'erase' => fn (Subject $s) => $s->user ? Address::where('user_id', $s->user->id)->delete() : 0,
        ]);

        $registry->register('verification_codes', [
            'label' => 'Verification codes',
            // A used or expired code is a record of an address and an IP and
            // nothing else; a month is long enough to look into an abuse.
            'default_days' => 30,
            'retention' => fn (CarbonInterface $before) => VerificationCode::where('created_at', '<', $before)->delete(),
            'erase' => fn (Subject $s) => $s->scope(VerificationCode::query(), 'user_id', 'address', 'address')->delete(),
        ]);
    }
}
