<?php

namespace App\Core\Modules;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * A module arriving as a .zip — put where it belongs on disk, so it appears on
 * the modules screen to be installed, or replaces the version already there.
 *
 * This is the one door through which the admin writes CODE onto the server,
 * so every rule here is about the archive being somebody else's:
 *
 * - **Names are checked per entry, before anything is written.** An absolute
 *   path, a drive letter, a `..` segment, a NUL, a symlink — any one refuses
 *   the whole archive. Nothing is extracted by ZipArchive::extractTo(), which
 *   trusts the names; each file is written by hand to a path built here.
 * - **One module per archive**: `manifest.json` at the root or in one
 *   top-level folder, and nothing outside that folder.
 * - **Bounded**: a file count and an uncompressed total, read from the
 *   archive's own directory before a byte is inflated — a small zip that
 *   unpacks to gigabytes is the oldest trick there is.
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
class ModuleArchive
{
    /** Files an archive may hold. */
    public const MAX_FILES = 5000;

    /** Bytes it may unpack to. */
    public const MAX_BYTES = 100 * 1024 * 1024;

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

    /**
     * Every entry's name checked, the module's folder found, the size added up
     * — all from the archive's directory, before anything is inflated.
     *
     * @return array{0: string, 1: list<array{index: int, path: string}>}
     */
    protected function inspect(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_FILES) {
            throw new RuntimeException(__('The archive holds more than :n files.', ['n' => self::MAX_FILES]));
        }

        $names = [];
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));

            $zip->getExternalAttributesIndex($i, $system, $attributes);
            $isLink = $system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000;

            if ($name === '' || $isLink || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('~^[a-zA-Z]:~', $name)
                || in_array('..', explode('/', $name), true)) {
                throw new RuntimeException(__('The archive holds a path that is not allowed: :path', ['path' => Str::limit($name, 80)]));
            }

            $total += (int) ($stat['size'] ?? 0);
            $names[$i] = $name;
        }

        if ($total > self::MAX_BYTES) {
            throw new RuntimeException(__('The archive unpacks to more than :mb MB.', ['mb' => self::MAX_BYTES / 1024 / 1024]));
        }

        // manifest.json at the root, or in exactly one top-level folder.
        $prefix = in_array('manifest.json', $names, true) ? '' : null;

        if ($prefix === null) {
            $candidates = array_values(array_filter($names, fn ($n) => preg_match('~^[^/]+/manifest\.json$~', $n)));

            if (count($candidates) !== 1) {
                throw new RuntimeException(__('The archive has no manifest.json — at its root or in one folder.'));
            }

            $prefix = substr($candidates[0], 0, -strlen('manifest.json'));
        }

        $files = [];

        foreach ($names as $index => $name) {
            if (! str_starts_with($name, $prefix)) {
                throw new RuntimeException(__('The archive holds more than one module.'));
            }

            if (! str_ends_with($name, '/')) {
                $files[] = ['index' => $index, 'path' => substr($name, strlen($prefix))];
            }
        }

        return [$prefix, $files];
    }

    /** @param list<array{index: int, path: string}> $files */
    protected function extract(ZipArchive $zip, string $prefix, array $files, string $staging): void
    {
        File::ensureDirectoryExists($staging);

        // Counted as written, not as declared: the sizes in an archive's
        // directory are whatever its author typed there.
        $left = self::MAX_BYTES;

        foreach ($files as $file) {
            $target = $staging.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['path']);
            File::ensureDirectoryExists(dirname($target));

            $in = $zip->getStream($zip->getNameIndex($file['index']));
            $out = fopen($target, 'wb');

            if (! $in || ! $out) {
                throw new RuntimeException(__('Could not unpack :path.', ['path' => $file['path']]));
            }

            $left -= (int) stream_copy_to_stream($in, $out, $left + 1);
            fclose($in);
            fclose($out);

            if ($left < 0) {
                throw new RuntimeException(__('The archive unpacks to more than :mb MB.', ['mb' => self::MAX_BYTES / 1024 / 1024]));
            }
        }
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
