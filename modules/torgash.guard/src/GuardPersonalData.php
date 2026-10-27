<?php

namespace Torgash\Guard;

use App\Core\Privacy\PersonalData;
use Carbon\CarbonInterface;
use Torgash\Guard\Models\AuditEntry;

/**
 * The audit trail in core's personal-data registry (PLAN §49). Who changed
 * what stays — that is the trail's whole point — but the IP address it was
 * done from is personal data, and old enough it says nothing an investigation
 * still needs.
 */
class GuardPersonalData
{
    public static function register(PersonalData $registry): void
    {
        $registry->register('guard_ip', [
            'label' => 'IP addresses in the audit trail',
            'default_days' => 180,
            'retention' => fn (CarbonInterface $before) => AuditEntry::where('created_at', '<', $before)
                ->whereNotNull('ip')->update(['ip' => null]),
        ]);
    }
}
