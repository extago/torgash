<?php

namespace Torgash\Exim;

use App\Models\BackgroundTask;
use Torgash\Exim\Jobs\BuildExport;
use Torgash\Exim\Models\EximProfile;

/**
 * When an export's file is built again, and the one place that starts it.
 *
 * Two reasons, both the profile's own. Its cron `schedule` — always, at the
 * times it names, because some changes leave no row behind (a promotion
 * starting at midnight). And `rebuild_on_change`: when the data it reads is
 * no longer what the file was built from (Fingerprint), but not sooner than
 * `rebuild_every` minutes after the last build — fifty edits in an hour cost
 * two builds, not fifty, and an evening of nobody editing costs none. The
 * scheduler asks every minute, so a cron expression is kept to the minute;
 * changes are looked for every fifth one.
 *
 * A build is a background task (Jobs\BuildExport) the admin can watch and
 * stop, one per profile: a profile whose task is still queued or running is
 * not started again.
 */
class Rebuilds
{
    public const SCHEDULE = 'schedule';

    public const CHANGE = 'change';

    public const MANUAL = 'manual';

    /**
     * The profiles whose file is due now, each with its reason. A data
     * fingerprint is taken once per entity, however many profiles share it.
     *
     * @return list<array{0: EximProfile, 1: string}>
     */
    public function due(bool $lookForChanges = true): array
    {
        $profiles = EximProfile::query()
            ->where('type', 'export')
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNotNull('schedule')->orWhere('rebuild_on_change', true))
            ->orderBy('id')
            ->get();

        $fingerprints = [];
        $due = [];

        foreach ($profiles as $profile) {
            if ($profile->isDueForSchedule()) {
                $due[] = [$profile, self::SCHEDULE];

                continue;
            }

            if (! $lookForChanges || ! $profile->rebuild_on_change || ! $profile->hasRested()) {
                continue;
            }

            if (! array_key_exists($profile->entity, $fingerprints)) {
                $fingerprints[$profile->entity] = Fingerprint::of($profile->entity);
            }

            $now = $fingerprints[$profile->entity];

            if ($now === null || $now !== $profile->built_fingerprint) {
                $due[] = [$profile, self::CHANGE];
            }
        }

        return $due;
    }

    /** Starts every build that is due — the scheduler's call, every minute. */
    public function startDue(bool $lookForChanges = true): int
    {
        $started = 0;

        foreach ($this->due($lookForChanges) as [$profile, $reason]) {
            $started += $this->start($profile, $reason) ? 1 : 0;
        }

        return $started;
    }

    /** Puts a build of the profile on the queue, unless one is already there. */
    public function start(EximProfile $profile, string $reason = self::MANUAL): ?BackgroundTask
    {
        if ($profile->isBuilding()) {
            return null;
        }

        $task = BuildExport::start($profile->id, $reason);

        // Bookkeeping, not an edit of the profile: the audit trail would
        // otherwise grow a line per build.
        $profile->forceFill(['build_task_id' => $task->id])->saveQuietly();

        return $task;
    }
}
