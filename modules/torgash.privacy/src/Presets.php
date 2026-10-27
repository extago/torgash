<?php

namespace Torgash\Privacy;

/**
 * What each law asks for, as settings (PLAN §49). A preset FILLS the settings
 * form; the shop can change any value afterwards — a starting point, never a
 * lock. Retention is days per personal-data entry (0 = keep).
 *
 * Not legal advice, and the screen says so: the law also asks for things no
 * software does — the policy's text, a notice to the regulator, where the
 * server stands.
 */
class Presets
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            // Ask before anything that is not necessary; a month to answer.
            'eu' => [
                'consent_mode' => 'opt_in',
                'respect_gpc' => true,
                'google_consent_mode' => true,
                'answer_days' => 30,
                'retention' => ['order_ip' => 180, 'guard_ip' => 180, 'verification_codes' => 30, 'consent_ip' => 365],
            ],
            // Allowed until refused; "do not sell or share" = the GPC signal
            // and the "only necessary" choice; 45 days to answer.
            'us' => [
                'consent_mode' => 'opt_out',
                'respect_gpc' => true,
                'google_consent_mode' => true,
                'answer_days' => 45,
                'retention' => ['order_ip' => 365, 'guard_ip' => 365, 'verification_codes' => 30, 'consent_ip' => 730],
            ],
            // Consent first, a separate consent document, ten WORKING days
            // to answer — fourteen calendar days is the safe reading.
            'ru' => [
                'consent_mode' => 'opt_in',
                'respect_gpc' => false,
                'google_consent_mode' => true,
                'answer_days' => 14,
                'retention' => ['order_ip' => 180, 'guard_ip' => 180, 'verification_codes' => 30, 'consent_ip' => 1095],
            ],
            // Ukraine's own law reads like the GDPR and is being brought in
            // line with it.
            'ua' => [
                'consent_mode' => 'opt_in',
                'respect_gpc' => false,
                'google_consent_mode' => true,
                'answer_days' => 30,
                'retention' => ['order_ip' => 180, 'guard_ip' => 180, 'verification_codes' => 30, 'consent_ip' => 365],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $regime): ?array
    {
        return self::all()[$regime] ?? null;
    }
}
