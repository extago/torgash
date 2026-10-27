<?php

namespace App\Core\Updates;

use App\Core\Backups\Backups;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Installs a release package onto this shop (PLAN §45.7):
 *
 *     check the package → back the database up → unpack and check every file
 *     → copy the rollback script out of the code → maintenance mode
 *     → swap the owned paths, one by one, each written into the plan first
 *     → a NEW process finishes on the new code (migrations, providers, 45.4)
 *     → the shop is switched back on.
 *
 * Anything that fails after the first move is undone by the rollback script,
 * which is plain PHP outside the code it restores, puts the previous paths
 * back and lets the old code restore the database from the backup made at
 * the start. The previous version stays in storage/app/update/<version>/previous
 * until the next update, so `torgash:update-rollback` works days later too.
 */
class Updater
{
    /** @var callable(string): void */
    protected $say;

    /**
     * @param  array{backup?: bool, maintenance?: bool, finish?: bool}  $steps  all on outside the tests
     */
    public function __construct(
        protected ?string $root = null,
        protected ?string $work = null,
        protected array $steps = [],
    ) {
        $this->root = rtrim(str_replace('\\', '/', $root ?? base_path()), '/');
        $this->work = rtrim(str_replace('\\', '/', $work ?? storage_path('app/update')), '/');
        $this->steps += ['backup' => true, 'maintenance' => true, 'finish' => true];
    }

    public function rollbackScript(): string
    {
        return $this->work.'/rollback.php';
    }

    /** The plan of the last update, or null. */
    public function lastPlan(): ?string
    {
        $plans = glob($this->work.'/*/plan.json') ?: [];
        usort($plans, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $plans[0] ?? null;
    }

    /**
     * @param  callable(string): void|null  $say
     * @return array{from: string, to: string, secret: ?string}
     */
    public function apply(string $zipPath, ?callable $say = null, ?string $current = null): array
    {
        $this->say = $say ?? fn () => null;
        $current ??= (string) config('torgash.version');

        $package = UpdatePackage::open($zipPath);
        $package->assertApplicable($current);
        $to = $package->version();
        ($this->say)("Torgash $current → $to: the package is signed and complete.");

        $backup = null;
        if ($this->steps['backup']) {
            ($this->say)('Backing up the database');
            $backup = app(Backups::class)->create(null, false, false);
        }

        // Each run keeps a whole previous version; only the last one is still
        // worth rolling back to.
        $last = $this->lastPlan();
        // Only the runs — directories named by a version. The work directory
        // also holds `downloads/`, where the package being applied may lie:
        // sweeping it too deleted the package before it was unpacked (the
        // first real update did exactly that).
        foreach (glob($this->work.'/*', GLOB_ONLYDIR) ?: [] as $old) {
            if (! preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', basename($old))) {
                continue;
            }
            if ($last === null || str_replace('\\', '/', $old) !== str_replace('\\', '/', dirname($last))) {
                File::deleteDirectory($old);
            }
        }

        $run = $this->work.'/'.$to;
        File::deleteDirectory($run);
        File::ensureDirectoryExists($run);

        ($this->say)('Unpacking and checking every file');
        $package->extractTo($run.'/new');

        // Out of the code it restores, before anything moves — and the NEW
        // version's script when the package has one: it is signed like the
        // rest, and a fix to the way back then protects this very update
        // instead of the next one (found when 0.1.3's fix did not run on the
        // rollback from 0.1.3).
        $stub = $run.'/new/app/Core/Updates/rollback.php.stub';
        File::copy(is_file($stub) ? $stub : __DIR__.'/rollback.php.stub', $this->rollbackScript());

        $owned = array_values(array_unique([...(new Distribution($this->root))->paths(), ...$package->paths()]));
        $plan = [
            'from' => $current, 'to' => $to, 'root' => $this->root, 'run' => $run,
            'paths' => $owned, 'swapped' => [], 'backup' => $backup, 'php' => \App\Core\Support\Php::command(),
            'state' => 'swapping', 'started_at' => gmdate('c'),
        ];
        $planFile = $run.'/plan.json';
        $this->writePlan($planFile, $plan);

        $secret = null;
        if ($this->steps['maintenance']) {
            $secret = Str::random(32);
            Artisan::call('down', ['--secret' => $secret, '--retry' => 60]);
        }

        // Loaded now: after the swap the autoloader reads the NEW vendor.
        class_exists(Process::class);

        try {
            foreach ($owned as $path) {
                $plan['swapped'][] = $path;
                $this->writePlan($planFile, $plan);
                $this->move($this->root.'/'.$path, $run.'/previous/'.$path);
                $this->move($run.'/new/'.$path, $this->root.'/'.$path);
            }
            $plan['state'] = 'swapped';
            $this->writePlan($planFile, $plan);

            if ($this->steps['finish']) {
                ($this->say)('Finishing on the new code');
                $finish = Process::path($this->root)->timeout(3000)
                    ->run([...\App\Core\Support\Php::command(), $this->root.'/artisan', 'torgash:update-finish', $planFile]);
                if (! $finish->successful()) {
                    throw new RuntimeException(trim($finish->errorOutput() ?: $finish->output()) ?: 'The new version did not finish.');
                }
            }
        } catch (Throwable $e) {
            ($this->say)('Failed: '.$e->getMessage().' — rolling back');
            $this->rollback($planFile, ! $this->steps['backup'] && ! $this->steps['maintenance']);

            throw new RuntimeException(__('The update failed and Torgash :version was put back: :reason', ['version' => $current, 'reason' => $e->getMessage()]), 0, $e);
        }

        return ['from' => $current, 'to' => $to, 'secret' => $secret];
    }

    /**
     * The new code's half, in its own process: providers, migrations of core
     * and of every bundled module that is installed, then 45.4, then back on.
     */
    public function finish(string $planFile): void
    {
        $plan = json_decode((string) file_get_contents($planFile), true);

        foreach (['packages.php', 'services.php', 'config.php', 'events.php'] as $cache) {
            @unlink($this->root.'/bootstrap/cache/'.$cache);
        }
        foreach (glob($this->root.'/bootstrap/cache/routes-*.php') ?: [] as $cache) {
            @unlink($cache);
        }

        Artisan::call('package:discover');
        Artisan::call('migrate', ['--force' => true]);

        $modules = app(\App\Core\Modules\ModuleManager::class);
        $modules->forget();
        foreach ($plan['paths'] ?? [] as $path) {
            if (! str_starts_with($path, 'modules/')) {
                continue;
            }
            $id = substr($path, strlen('modules/'));
            $previous = array_map(fn ($f) => basename($f, '.php'), glob($plan['run'].'/previous/'.$path.'/migrations/*.php') ?: []);
            if ($modules->find($id) && $modules->isInstalled($id)) {
                $modules->upgrade($id, $previous);
            }
        }

        config(['torgash.refresh_on_change' => true]);
        app(\App\Core\System\CodeChanged::class)->refresh();

        $plan['state'] = 'done';
        $plan['finished_at'] = gmdate('c');
        $this->writePlan($planFile, $plan);

        Artisan::call('up');
    }

    /** Runs the rollback script — the same one that works when artisan does not. */
    public function rollback(string $planFile, bool $codeOnly = false, ?callable $say = null): bool
    {
        $this->say = $say ?? $this->say;

        $result = Process::path($this->root)->timeout(3000)
            ->run(array_values(array_filter([...\App\Core\Support\Php::command(), $this->rollbackScript(), $planFile, $codeOnly ? '--code-only' : null])));

        ($this->say ?? fn () => null)(trim($result->output().$result->errorOutput()));

        return $result->successful();
    }

    protected function move(string $from, string $to): void
    {
        if (! file_exists($from)) {
            return;
        }
        File::ensureDirectoryExists(dirname($to));
        if (! @rename($from, $to)) {
            throw new RuntimeException("Could not move $from to $to.");
        }
    }

    protected function writePlan(string $file, array $plan): void
    {
        file_put_contents($file, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
