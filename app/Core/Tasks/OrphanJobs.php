<?php

namespace App\Core\Tasks;

use App\Core\Modules\ModuleManifest;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Jobs whose code is gone (PLAN §45.6).
 *
 * A queued job is a serialised object; when its class has left the disk — a
 * module uninstalled, a mailable folded into another — the worker unpacks an
 * "incomplete object" and the job fails with a stack nobody can act on. Two
 * answers: an uninstalled module takes its WAITING jobs with it, and failed
 * jobs whose class no longer exists can be cleared in one go (they can never
 * be retried — there is nothing left to run).
 */
class OrphanJobs
{
    /**
     * Removes the jobs waiting in the queue that belong to the module — by
     * the namespaces its manifest autoloads. Returns how many.
     */
    public function dropForModule(ModuleManifest $manifest): int
    {
        $prefixes = array_keys((array) ($manifest->autoload ?? []));
        if ($prefixes === []) {
            return 0;
        }

        try {
            $ids = DB::table('jobs')->get(['id', 'payload'])
                ->filter(fn ($job) => $this->startsWithAny($this->displayName($job->payload), $prefixes))
                ->pluck('id')->all();
        } catch (Throwable) {
            return 0;
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('jobs')->whereIn('id', $chunk)->delete();
        }

        return count($ids);
    }

    /** @return list<int|string> ids of failed jobs whose class is no longer anywhere */
    public function missingFailed(): array
    {
        // A switched-off module's classes are not autoloaded, but they are
        // there: its failed jobs can still be retried once it is back on.
        $modules = app(\App\Core\Modules\ModuleManager::class);
        foreach ($modules->all() as $manifest) {
            $modules->registerAutoload($manifest);
        }

        try {
            return DB::table('failed_jobs')->get(['id', 'payload'])
                ->filter(function ($job) {
                    $class = $this->displayName($job->payload);

                    return $class !== '' && ! class_exists($class) && ! interface_exists($class);
                })
                ->pluck('id')->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function pruneMissingFailed(): int
    {
        $ids = $this->missingFailed();

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('failed_jobs')->whereIn('id', $chunk)->delete();
        }

        return count($ids);
    }

    protected function displayName(string $payload): string
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? (string) ($decoded['displayName'] ?? '') : '';
    }

    /** @param  list<string>  $prefixes */
    protected function startsWithAny(string $class, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with(ltrim($class, '\\'), ltrim($prefix, '\\'))) {
                return true;
            }
        }

        return false;
    }
}
