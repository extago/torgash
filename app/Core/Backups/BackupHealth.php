<?php

namespace App\Core\Backups;

use App\Models\BackupPlan;
use App\Models\BackupRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Whether the backups are in order (PLAN §67, stage 4) — what the bell, the
 * System page and the letters say. A plan needs attention when:
 *
 * - its last backup failed, or was made but not kept everywhere (partial);
 * - its last check by restoring failed;
 * - its newest backup holds only the files changed since a full one, and
 *   that full backup is gone (PLAN §67.10) — what it would restore is the
 *   pictures of the last day;
 * - it runs by a schedule and its last good backup is older than that
 *   schedule allows (the longest gap between two of its moments, plus three
 *   hours for the backup itself to be made) — a scheduler or a worker that
 *   stopped makes no error anywhere, only this silence.
 *
 * The count IS the list of such plans: nothing is stored as "seen", a good
 * backup clears it.
 */
class BackupHealth
{
    /**
     * @return Collection<int, array{plan: BackupPlan, problem: string, reason: string, since: ?Carbon}>
     */
    public function attention(): Collection
    {
        try {
            if (! Schema::hasTable('backup_plans')) {
                return collect();
            }

            $plans = BackupPlan::query()->where('enabled', true)->orderBy('id')->get();
        } catch (Throwable) {
            return collect();
        }

        $found = collect();

        foreach ($plans as $plan) {
            $last = BackupRun::query()->where('plan_id', $plan->id)
                ->whereIn('status', [BackupRun::DONE, BackupRun::PARTIAL, BackupRun::FAILED])
                ->orderByDesc('id')->first();

            if ($last && in_array($last->status, [BackupRun::FAILED, BackupRun::PARTIAL], true)) {
                $found->push([
                    'plan' => $plan,
                    'problem' => $last->status,
                    'reason' => $last->status === BackupRun::FAILED
                        ? __('The last backup by ":plan" failed: :error', ['plan' => $plan->name, 'error' => \Illuminate\Support\Str::limit((string) $last->error, 200)])
                        : __('The last backup by ":plan" was not stored everywhere: :error', ['plan' => $plan->name, 'error' => \Illuminate\Support\Str::limit((string) $last->error, 200)]),
                    'since' => $last->finished_at,
                ]);

                continue;
            }

            $checked = BackupRun::query()->where('plan_id', $plan->id)->whereNotNull('verified_at')->orderByDesc('verified_at')->first();
            if ($checked && $checked->verify_status === 'failed') {
                $found->push([
                    'plan' => $plan,
                    'problem' => 'verify',
                    'reason' => __('A backup by ":plan" did not restore when checked: :error', ['plan' => $plan->name, 'error' => \Illuminate\Support\Str::limit((string) $checked->verify_error, 200)]),
                    'since' => $checked->verified_at,
                ]);

                continue;
            }

            $newest = BackupRun::query()->where('plan_id', $plan->id)->whereIn('status', [BackupRun::DONE, BackupRun::PARTIAL])->orderByDesc('id')->first();
            if ($newest && $newest->isDifferential() && ! $newest->baseKept()) {
                $found->push([
                    'plan' => $plan,
                    'problem' => 'orphan',
                    'reason' => __('The newest backup by ":plan" holds only the files changed since :when, and the full backup it leans on is gone.', [
                        'plan' => $plan->name, 'when' => $newest->files_since->timezone(config('app.timezone'))->translatedFormat('j M, H:i'),
                    ]),
                    'since' => $newest->finished_at,
                ]);

                continue;
            }

            $limit = $this->allowedHours($plan);
            if ($limit === null) {
                continue;
            }

            $good = BackupRun::query()->where('plan_id', $plan->id)->where('status', BackupRun::DONE)->max('finished_at');
            $since = $good ? Carbon::parse($good) : $plan->created_at;

            if ($since && $since->lt(now()->subHours($limit))) {
                $found->push([
                    'plan' => $plan,
                    'problem' => 'stale',
                    'reason' => $good
                        ? __('No backup by ":plan" since :when — its schedule expects one at least every :hours h.', ['plan' => $plan->name, 'when' => $since->timezone(config('app.timezone'))->translatedFormat('j M, H:i'), 'hours' => $limit - 3])
                        : __('The plan ":plan" has not made a single backup yet — its schedule expects one at least every :hours h.', ['plan' => $plan->name, 'hours' => $limit - 3]),
                    'since' => $since,
                ]);
            }
        }

        return $found;
    }

    /**
     * How many hours may pass without a good backup: the longest gap between
     * two of the schedule's next moments, plus three. Null for a plan that
     * runs only by hand.
     */
    public function allowedHours(BackupPlan $plan): ?int
    {
        $moments = PlanSchedule::next((array) $plan->schedule, 12);

        if (count($moments) < 2) {
            // One moment a year or none: the schedule says too little to judge.
            return $moments === [] ? null : 24 * 400;
        }

        $gap = 0;
        for ($i = 1; $i < count($moments); $i++) {
            $gap = max($gap, $moments[$i - 1]->diffInMinutes($moments[$i], true));
        }

        return (int) ceil($gap / 60) + 3;
    }
}
