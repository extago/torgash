<?php

namespace App\Core\Updates;

use App\Core\Tasks\TrackedJob;
use App\Models\BackgroundTask;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The System page's "Install": download the offered release and run the
 * Updater on it, in the background (PLAN §45.8). The worker running this is
 * old code; the swap and the new process do not depend on it, and
 * CodeChanged restarts it afterwards.
 */
class InstallUpdate extends TrackedJob
{
    /** Where packages handed in by the owner wait (System → Install from a file, or put there by FTP). */
    public const INCOMING = 'app/update/incoming';

    /**
     * @param  string|null  $package  a package already on this server — uploaded or put into INCOMING —
     *                                for a shop that cannot reach the update server; null: the offered one
     */
    public function __construct(public ?string $package = null) {}

    public function label(): string
    {
        return 'Install an update';
    }

    protected function run(): void
    {
        // A rollback restores the database from the backup THIS job made, in
        // which this very task is still "running" and its job still reserved.
        // An hour later the queue would hand it out again and install the
        // version the owner has just gone back from. Started before the last
        // rollback means started by that ghost.
        if (($created = $this->task()?->created_at) && ($rolledBack = static::rolledBackAt()) && $created->getTimestamp() < $rolledBack) {
            $this->message('Not installed: the update was rolled back');

            return;
        }

        if ($this->package !== null) {
            // Handed in by the owner: the same Updater checks it — signature,
            // forwards only, every file against its sum — before anything moves.
            $zip = is_file($this->package) ? $this->package : throw new RuntimeException('The package is no longer on the server — upload it again.');
            $this->message('Installing Torgash :version', ['version' => UpdatePackage::open($zip)->version()]);
        } else {
            $server = app(UpdateServer::class);
            $offer = $server->offer() ?? throw new RuntimeException('There is no newer version on offer — check again.');

            $this->message('Downloading Torgash :version', ['version' => $offer['version']]);
            $zip = $server->download($offer);

            $this->message('Installing Torgash :version', ['version' => $offer['version']]);
        }

        $done = (new Updater)->apply($zip);

        // Installed: a handed-in package has done its work and is 30 MB.
        if ($this->package !== null) {
            @unlink($this->package);
        }

        $this->result(['from' => $done['from'], 'to' => $done['to']], 'Torgash :version is installed', ['version' => $done['to']]);
    }

    /** When the last update was rolled back (its plan's last write), or null. */
    public static function rolledBackAt(): ?int
    {
        $plan = (new Updater)->lastPlan();
        if ($plan === null) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($plan), true);

        return ($data['state'] ?? null) === 'rolled back' ? (int) filemtime($plan) : null;
    }

    /**
     * Writes off the task a rollback brought back from its backup, so the
     * System page does not show an install running and the queue skips its
     * job (a cancelled task's job does nothing). Called by the System page.
     */
    public static function settleAfterRollback(): void
    {
        $rolledBack = static::rolledBackAt();
        if ($rolledBack === null) {
            return;
        }

        BackgroundTask::query()
            ->where('name', (new static)->label())
            ->whereIn('status', [BackgroundTask::QUEUED, BackgroundTask::RUNNING])
            // In the application's zone: a timestamp column holds that wall
            // clock, and createFromTimestamp() alone answers in UTC — three
            // hours off on a Moscow shop, which let a ghost install through.
            ->where('created_at', '<', Carbon::createFromTimestamp($rolledBack, config('app.timezone')))
            ->update(['status' => BackgroundTask::CANCELLED, 'finished_at' => now(), 'message' => 'Not installed: the update was rolled back', 'message_params' => null]);
    }
}
