<?php

namespace App\Http\Controllers\Admin;

use App\Core\Assets\BuildStyles;
use App\Core\Assets\StyleBuilder;
use App\Core\Backups\Backups;
use App\Core\Backups\CreateBackup;
use App\Core\Settings\CoreSettings;
use App\Core\Settings\SettingsManager;
use App\Core\System\AuditDependencies;
use App\Core\System\SystemFacts;
use App\Http\Controllers\Controller;
use App\Models\BackgroundTask;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin → System (PLAN §45): what the server is, and the backups.
 *
 * Updates from an update server are not here yet — there is no server to ask;
 * the version is shown so that, when there is, the question has its answer.
 */
class SystemController extends Controller
{
    public function index(SystemFacts $facts)
    {
        \App\Core\Updates\InstallUpdate::settleAfterRollback();

        return view('admin.system.index', [
            'engine' => $facts->engine(),
            'limits' => $facts->limits(),
            'extensions' => $facts->extensions(),
            'database' => $facts->database(),
            'disk' => $facts->disk(),
            'background' => $facts->background(),
            'warnings' => $facts->warnings(),
            'audit' => setting(CoreSettings::ID, 'dependency_audit'),
            'auditRunning' => $this->running('Check the libraries for known vulnerabilities'),
            'updates' => [
                'current' => (string) config('torgash.version'),
                'last' => app(\App\Core\Updates\UpdateServer::class)->last(),
                'offer' => app(\App\Core\Updates\UpdateServer::class)->offer(),
                'running' => $this->running('Install an update'),
                'address' => (string) setting(CoreSettings::ID, \App\Core\Updates\UpdateServer::ADDRESS),
                'default' => app(\App\Core\Updates\UpdateServer::class)->defaultUrl(),
                'url' => app(\App\Core\Updates\UpdateServer::class)->url(),
                // Packages handed in by hand, for a shop that cannot download.
                'incoming' => app(\App\Core\Updates\IncomingPackages::class)->all(),
                'upload_limit' => min(self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size'))),
            ],
            // PLAN §55: the demo shop, while it is there — a reminder and the way out.
            'demo' => [
                'records' => app(\App\Core\Demo\DemoData::class)->count(),
                'running' => $this->running('Remove the demo data'),
            ],
            'styles' => [
                'builder' => app(StyleBuilder::class)->binary(),
                'platform' => app(StyleBuilder::class)->platform(),
                'last' => app(StyleBuilder::class)->last(),
                'running' => $this->running('Rebuild the stylesheet'),
            ],
        ]);
    }

    public function audit()
    {
        AuditDependencies::startIfIdle();

        return back()->with('status', __('The check has started — it needs a queue worker and takes up to a minute.'));
    }

    public function checkUpdates(\App\Core\Updates\UpdateServer $server)
    {
        $found = $server->check();

        return back()->with(isset($found['error']) ? 'error' : 'status', isset($found['error'])
            ? __('The update server could not be asked: :reason', ['reason' => $found['error']])
            : ($server->offer() ? __('Torgash :version is available.', ['version' => $server->offer()['version']]) : __('This is the newest version.')));
    }

    public function updateServer(\Illuminate\Http\Request $request, \App\Core\Updates\UpdateServer $server)
    {
        $data = $request->validate(['address' => ['nullable', 'string', 'max:500', 'url:https,http']]);
        $server->setAddress($data['address'] ?? null);

        return back()->with('status', ($data['address'] ?? '') === ''
            ? __('The shop asks the built-in update server again.')
            : __('The update server address is saved. Check for updates to try it.'));
    }

    public function installUpdate(\App\Core\Updates\UpdateServer $server)
    {
        abort_unless($server->offer(), 404);
        \App\Core\Updates\InstallUpdate::settleAfterRollback();
        \App\Core\Updates\InstallUpdate::startIfIdle();

        return back()->with('status', __('The update has started: a backup first, then the shop goes into maintenance for a minute or two. It needs a queue worker.'));
    }

    /** A package uploaded by hand: checked and kept, installed by a second, deliberate click. */
    public function uploadUpdate(\Illuminate\Http\Request $request, \App\Core\Updates\IncomingPackages $incoming)
    {
        $request->validate(['package' => ['required', 'file', 'extensions:zip']], [
            'package.uploaded' => __('The file did not arrive — larger than the upload limit? Put it into :folder by FTP instead.', ['folder' => 'storage/app/update/incoming']),
        ]);

        try {
            $version = $incoming->store($request->file('package'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect(route('admin.system').'#updates')->with('status', __('Torgash :version is uploaded and its signature checks out. Install it below.', ['version' => $version]));
    }

    /** Installs a waiting package — the same job and the same checks as an offered one. */
    public function installUpdateFile(\Illuminate\Http\Request $request, \App\Core\Updates\IncomingPackages $incoming)
    {
        $path = $incoming->path((string) $request->input('name')) ?? abort(404);
        \App\Core\Updates\InstallUpdate::settleAfterRollback();
        \App\Core\Updates\InstallUpdate::startIfIdle($path);

        return back()->with('status', __('The update has started: a backup first, then the shop goes into maintenance for a minute or two. It needs a queue worker.'));
    }

    public function deleteUpdateFile(\Illuminate\Http\Request $request, \App\Core\Updates\IncomingPackages $incoming)
    {
        $incoming->delete((string) $request->input('name'));

        return back()->with('status', __('The package is deleted.'));
    }

    /** `64M` / `1G` / `2048` (as PHP's ini writes sizes) in bytes. */
    protected static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public function pruneJobs(\App\Core\Tasks\OrphanJobs $orphans)
    {
        $removed = $orphans->pruneMissingFailed();

        return back()->with('status', trans_choice(':count failed job without code removed.|:count failed jobs without code removed.', $removed, ['count' => $removed]));
    }

    public function styles()
    {
        BuildStyles::startIfIdle();

        return back()->with('status', __('The stylesheet is being rebuilt — it needs a queue worker and takes a few seconds.'));
    }

    public function removeDemo()
    {
        \App\Core\Demo\RemoveDemo::startIfIdle();

        return back()->with('status', __('The demo data is being removed — it needs a queue worker and takes a minute or so.'));
    }

    public function backups(Backups $backups)
    {
        return view('admin.system.backups', [
            'backups' => $backups->all(),
            'disk' => $backups->diskName(),
            'settings' => [
                'disk' => Backups::setting('disk', 'local'),
                'files' => (bool) Backups::setting('files', true),
                'code' => (bool) Backups::setting('code', false),
                'schedule' => Backups::setting('schedule', 'off'),
                'keep' => (int) Backups::setting('keep', 7),
            ],
            'pgDump' => Backups::toolVersion('pg_dump'),
            'running' => $this->running('Make a backup'),
            'onS3' => app(\App\Core\Files\FileStorage::class)->onS3(),
        ]);
    }

    public function saveBackupSettings(Request $request, SettingsManager $settings)
    {
        $validated = $request->validate([
            'disk' => ['required', Rule::in(['local', 'private'])],
            'schedule' => ['required', Rule::in(['off', 'daily', 'weekly'])],
            'keep' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $settings->set(CoreSettings::ID, 'backup_disk', $validated['disk']);
        $settings->set(CoreSettings::ID, 'backup_schedule', $validated['schedule']);
        $settings->set(CoreSettings::ID, 'backup_keep', (int) $validated['keep']);
        $settings->set(CoreSettings::ID, 'backup_files', $request->boolean('files'));
        $settings->set(CoreSettings::ID, 'backup_code', $request->boolean('code'));

        return back()->with('status', __('Settings saved.'));
    }

    public function backup()
    {
        CreateBackup::startIfIdle();

        return back()->with('status', __('The backup has started. It appears in the list when it is done — it needs a queue worker.'));
    }

    public function download(Backups $backups, string $name)
    {
        abort_unless($backups->exists($name), 404);

        return $backups->disk()->download(Backups::DIR.'/'.$name, $name);
    }

    public function destroy(Backups $backups, string $name)
    {
        $backups->delete($name);

        return back()->with('status', __('Backup deleted.'));
    }

    protected function running(string $label): bool
    {
        return BackgroundTask::query()->where('name', $label)->whereIn('status', [BackgroundTask::QUEUED, BackgroundTask::RUNNING])->exists();
    }
}
