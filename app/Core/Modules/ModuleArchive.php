<?php

namespace App\Core\Modules;

use App\Core\Support\CodeArchive;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * A module arriving as a .zip — put where it belongs on disk, so it appears on
 * the modules screen to be installed, or replaces the version already there.
 *
 * The archive itself is read the careful way of every code archive
 * (App\Core\Support\CodeArchive: names checked before anything is written,
 * one module per archive, bounded). What is the module's own:
 *
 * - **Staged, then swapped**: everything is unpacked into storage first and
 *   validated there (the manifest must parse); only then is it moved into
 *   modules/. Replacing a module keeps the old folder in
 *   `storage/app/module-backups/` and puts it back if the swap or the new
 *   version's migrations fail.
 * - **Only forwards**: a module already on disk is replaced only by a HIGHER
 *   version. Re-uploading the same one, or an older one, is refused rather
 *   than silently rolling a shop back.
 *
 * Who may do this at all is the controller's question — the super group only,
 * since uploading PHP is more than managing modules.
 */
class ModuleArchive extends CodeArchive
{
    public function __construct(protected ModuleManager $modules) {}

    /**
     * @return array{id: string, name: string, version: string, replaced: ?string}
     */
    public function put(string $zipPath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException(__('This is not a zip archive.'));
        }

        try {
            [$prefix, $files] = $this->inspect($zip);
            $data = json_decode((string) $zip->getFromName($prefix.'manifest.json'), true);

            if (! is_array($data) || ! is_string($data['id'] ?? null) || ! preg_match('/^[a-z0-9][a-z0-9_-]*\.[a-z0-9][a-z0-9_.-]*$/', $data['id']) || empty($data['provider'])) {
                throw new RuntimeException(__('The archive\'s manifest.json does not name a module (an id like vendor.name, and a provider).'));
            }

            $id = $data['id'];
            $version = (string) ($data['version'] ?? '0.0.0');
            $existing = $this->modules->find($id);

            if ($existing && version_compare($version, $existing->version, '<=')) {
                throw new RuntimeException(__(':module :current is already here; the archive holds :version. Only a newer version replaces it.', [
                    'module' => $id, 'current' => $existing->version, 'version' => $version,
                ]));
            }

            $staging = storage_path('app/module-uploads/'.Str::uuid());
            $this->extract($zip, $prefix, $files, $staging);
        } catch (Throwable $e) {
            if (isset($staging)) {
                File::deleteDirectory($staging);
            }

            throw $e;
        } finally {
            $zip->close();
        }

        try {
            // Parsed the way the engine will parse it — a manifest that fails
            // here would fail on every page once it sat in modules/.
            $manifest = ModuleManifest::fromFile($staging.DIRECTORY_SEPARATOR.'manifest.json');
            // Before anything on disk moves: a version this core cannot run
            // would otherwise replace one that it can.
            $this->modules->assertFitsCore($manifest);
            $target = $existing?->path ?? config('torgash.modules_path').DIRECTORY_SEPARATOR.$id;

            $existing
                ? $this->replace($existing, $staging, $target)
                : $this->place($staging, $target);
        } finally {
            File::deleteDirectory($staging);
        }

        return ['id' => $id, 'name' => $manifest->name, 'version' => $version, 'replaced' => $existing?->version];
    }

    protected function marker(): string
    {
        return 'manifest.json';
    }

    protected function place(string $staging, string $target): void
    {
        if (is_dir($target)) {
            throw new RuntimeException(__('A folder called :folder is already in modules/ but holds no module by that id.', ['folder' => basename($target)]));
        }

        if (! File::moveDirectory($staging, $target)) {
            throw new RuntimeException(__('Could not write the module into modules/ — check that the folder is writable.'));
        }

        $this->modules->forget();
    }

    /**
     * The old folder is set aside, the new one moved in, and — if the module
     * is installed — its new migrations run. Anything failing puts the old
     * folder back, so a broken upload leaves the shop where it was.
     */
    protected function replace(ModuleManifest $existing, string $staging, string $target): void
    {
        // What the old version shipped, read before it moves: a migration the
        // new one adds is checked against the other modules' (upgrade()).
        $previous = array_map(fn ($file) => basename($file, '.php'), glob(($existing->migrationsPath() ?? '-').'/*.php') ?: []);
        $backup = storage_path('app/module-backups/'.$existing->id.'-'.$existing->version.'-'.now()->format('Ymd-His'));
        File::ensureDirectoryExists(dirname($backup));

        if (! File::moveDirectory($target, $backup)) {
            throw new RuntimeException(__('Could not set the current version aside — check that modules/ is writable.'));
        }

        try {
            if (! File::moveDirectory($staging, $target)) {
                throw new RuntimeException(__('Could not write the module into modules/ — check that the folder is writable.'));
            }

            $this->modules->forget();
            $this->modules->upgrade($existing->id, $previous);
        } catch (Throwable $e) {
            File::deleteDirectory($target);
            File::moveDirectory($backup, $target);
            $this->modules->forget();

            throw $e;
        }
    }
}
