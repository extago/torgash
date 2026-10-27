<?php

namespace App\Core\Backups;

use App\Core\Files\FileStorage;
use App\Core\Settings\CoreSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Backups of the shop (PLAN §45): one zip holding the database (pg_dump's own
 * format), the uploaded files while they are a local folder, optionally the
 * modules' and themes' code, and a manifest saying what is in it.
 *
 * Kept on a disk the shop chooses — the server's own folder, or the PRIVATE
 * disk, which is the bucket when files live in S3 (a copy somewhere other
 * than the server it protects). Never the public disk: a backup is every
 * customer's data in one file.
 *
 * What is NOT in it, on purpose: `.env` (passwords and keys — keep that file
 * apart, the screen says so), `vendor/` (composer install rebuilds it), and the
 * files when they are already in a bucket.
 *
 * The way BACK is a console command (`torgash:backup-restore`), never only a
 * button: an update that broke the admin closes the door a button would be
 * behind — the same thinking as `guard:unblock`.
 */
class Backups
{
    public const DIR = 'backups';

    /** Folders of the two disks that are the engine's scratch, not the shop's files. */
    public const SKIP = ['backups', 'exim', '.torgash-probe', 'files-tmp', 'media-tmp'];

    public const NAME_PATTERN = '/^torgash-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/';

    // ------------------------------------------------------------ settings

    public static function setting(string $key, mixed $default = null): mixed
    {
        return setting(CoreSettings::ID, 'backup_'.$key, $default);
    }

    /** `local` (the server's folder) or `private` (the bucket under S3). */
    public function diskName(): string
    {
        return self::setting('disk', 'local') === 'private' ? 'private' : 'local';
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    // ------------------------------------------------------------ listing

    /** @return Collection<int, array{name: string, bytes: int, at: int}> newest first */
    public function all(): Collection
    {
        $disk = $this->disk();

        return collect($disk->files(self::DIR))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => preg_match(self::NAME_PATTERN, $name))
            ->map(fn (string $name) => [
                'name' => $name,
                'bytes' => (int) $disk->size(self::DIR.'/'.$name),
                'at' => (int) $disk->lastModified(self::DIR.'/'.$name),
            ])
            // By the NAME, which is the moment it was made: a file's mtime is
            // the second it was copied (two backups in one second tie, and
            // pruning then kept the older), and a bucket may report neither.
            ->sortByDesc('name')
            ->values();
    }

    public function exists(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1 && $this->disk()->exists(self::DIR.'/'.$name);
    }

    public function delete(string $name): void
    {
        if ($this->exists($name)) {
            $this->disk()->delete(self::DIR.'/'.$name);
        }
    }

    // ------------------------------------------------------------ making one

    /**
     * Makes a backup and returns its name. `$say` hears what is being done,
     * for a tracked task's message or the console.
     *
     * @param  callable(string, array<string, mixed>): void|null  $say
     */
    public function create(?callable $say = null, ?bool $withFiles = null, ?bool $withCode = null): string
    {
        $say ??= fn () => null;
        $withFiles ??= (bool) self::setting('files', true);
        $withCode ??= (bool) self::setting('code', false);
        $files = app(FileStorage::class);

        $name = 'torgash-'.now()->format('Y-m-d-His').'.zip';
        $work = $this->workDir();
        $zipPath = $work.DIRECTORY_SEPARATOR.$name;

        try {
            $say('Dumping the database', []);
            $dump = $work.DIRECTORY_SEPARATOR.'database.dump';
            $this->pg('pg_dump', ['-Fc', '--no-owner', '--no-acl', '-f', $dump, $this->connection()['database']]);

            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create '.$zipPath);
            }

            $zip->addFile($dump, 'database.dump');
            $contents = ['database'];
            $counts = [];

            if ($withFiles && ! $files->onS3()) {
                foreach (FileStorage::DISKS as $diskName) {
                    $say('Adding the :disk files', ['disk' => $diskName]);
                    $counts[$diskName] = $this->addTree($zip, (string) config("filesystems.disks.$diskName.root"), 'files/'.$diskName, self::SKIP);
                }
                $contents[] = 'files';
            }

            if ($withCode) {
                $say('Adding the modules and themes', []);
                $counts['modules'] = $this->addTree($zip, (string) config('torgash.modules_path'), 'code/modules');
                $counts['themes'] = $this->addTree($zip, (string) config('torgash.themes_path'), 'code/themes');
                $contents[] = 'code';
            }

            $zip->addFromString('manifest.json', json_encode([
                'engine' => config('torgash.version'),
                'created_at' => now()->toIso8601String(),
                'app_url' => config('app.url'),
                'database' => $this->connection()['database'],
                'migrations' => DB::table('migrations')->count(),
                'contents' => $contents,
                'files_in_bucket' => $withFiles && $files->onS3(),
                'counts' => $counts,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $say('Packing', []);
            $zip->close();

            $say('Storing the backup', []);
            $stream = fopen($zipPath, 'rb');
            $this->disk()->writeStream(self::DIR.'/'.$name, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->prune();

            return $name;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** Keeps the newest N (the shop's setting); 0 keeps every one. */
    public function prune(): int
    {
        $keep = (int) self::setting('keep', 7);

        if ($keep <= 0) {
            return 0;
        }

        $old = $this->all()->slice($keep);
        $old->each(fn (array $backup) => $this->delete($backup['name']));

        return $old->count();
    }

    // ------------------------------------------------------------ the way back

    /**
     * Restores a backup: the database (into this one, or `$database` — an
     * existing, empty database to check a backup without touching the shop),
     * and the files when asked and when it has them.
     *
     * @param  callable(string): void|null  $say
     * @return array<string, mixed> the backup's manifest
     */
    public function restore(string $name, ?string $database = null, bool $withFiles = true, ?callable $say = null): array
    {
        $say ??= fn () => null;

        if (! $this->exists($name)) {
            throw new RuntimeException("There is no backup called $name.");
        }

        $work = $this->workDir();

        try {
            $say('Fetching the backup');
            $zipPath = $work.DIRECTORY_SEPARATOR.$name;
            $read = $this->disk()->readStream(self::DIR.'/'.$name);
            $write = fopen($zipPath, 'wb');
            stream_copy_to_stream($read, $write);
            fclose($write);
            if (is_resource($read)) {
                fclose($read);
            }

            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('The backup is not a readable zip.');
            }

            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true) ?: [];
            $zip->extractTo($work, 'database.dump');

            $say('Restoring the database');
            $dump = $work.DIRECTORY_SEPARATOR.'database.dump';
            $target = $database ?: $this->connection()['database'];
            $list = $this->withoutExtensions($dump, $target);

            $this->pg('pg_restore', [
                '--clean', '--if-exists', '--no-owner', '--no-acl', '--single-transaction',
                '-L', $list,
                '-d', $target,
                $dump,
            ]);

            if ($withFiles && $database === null && in_array('files', $manifest['contents'] ?? [], true)) {
                foreach (FileStorage::DISKS as $diskName) {
                    $say("Restoring the $diskName files");
                    $prefix = 'files/'.$diskName.'/';
                    $disk = Storage::disk($diskName);

                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $entry = (string) $zip->getNameIndex($i);

                        if (str_starts_with($entry, $prefix) && ! str_ends_with($entry, '/')) {
                            $disk->writeStream(substr($entry, strlen($prefix)), $zip->getStream($entry));
                        }
                    }
                }
            }

            $zip->close();

            return $manifest;
        } finally {
            File::deleteDirectory($work);
        }
    }

    // ------------------------------------------------------------ PostgreSQL

    /** @return array{host: string, port: string, username: string, password: string, database: string} */
    protected function connection(): array
    {
        $c = (array) config('database.connections.'.config('database.default'));

        return [
            'host' => (string) ($c['host'] ?? '127.0.0.1'),
            'port' => (string) ($c['port'] ?? '5432'),
            'username' => (string) ($c['username'] ?? 'postgres'),
            'password' => (string) ($c['password'] ?? ''),
            'database' => (string) ($c['database'] ?? ''),
        ];
    }

    /**
     * The dump's table of contents with its extensions left out, as a file
     * for `pg_restore -L`.
     *
     * An extension (pg_trgm, which the search's indexes need) belongs to the
     * role that created it — on a real server `postgres`, not the shop's own
     * user — and `--clean` begins by dropping it: "must be owner of extension
     * pg_trgm", the whole single transaction refused, and the restore an
     * update's rollback depends on never happened (the first live shop,
     * 2026-10-22). The extensions are already in the shop's database; for
     * another database they are created first, if this role may.
     */
    protected function withoutExtensions(string $dump, string $database): string
    {
        $c = $this->connection();
        $result = Process::timeout(120)->run([self::binary('pg_restore'), '-l', $dump]);

        if (! $result->successful()) {
            throw new RuntimeException('pg_restore: '.Str::limit(trim($result->errorOutput()) ?: 'cannot read the dump', 800));
        }

        $kept = [];
        $extensions = [];

        foreach (preg_split('/\R/', $result->output()) as $line) {
            if (preg_match('/^\s*\d+;\s*\d+\s+\d+\s+EXTENSION\s+-\s+(\S+)/', $line, $m)) {
                $extensions[] = $m[1];

                continue;
            }

            if (preg_match('/\bCOMMENT\s+-\s+EXTENSION\b/', $line)) {
                continue;
            }

            $kept[] = $line;
        }

        if ($database !== $c['database']) {
            foreach ($extensions as $extension) {
                try {
                    $this->pg('psql', ['-d', $database, '-c', 'create extension if not exists '.preg_replace('/[^A-Za-z0-9_]/', '', $extension)]);
                } catch (Throwable) {
                    // Not this role's to create: the restore says what is missing.
                }
            }
        }

        $list = dirname($dump).DIRECTORY_SEPARATOR.'restore.list';
        file_put_contents($list, implode("\n", $kept)."\n");

        return $list;
    }

    /** The full path of pg_dump / pg_restore, or the bare name to find on the PATH. */
    public static function binary(string $tool): string
    {
        $dir = rtrim((string) config('torgash.pg_bin', ''), '/\\');

        if ($dir === '') {
            return $tool;
        }

        $path = $dir.DIRECTORY_SEPARATOR.$tool.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');

        return is_file($path) ? $path : $tool;
    }

    /** "pg_dump (PostgreSQL) 18.3", or null when it cannot be run. */
    public static function toolVersion(string $tool = 'pg_dump'): ?string
    {
        try {
            $result = Process::timeout(15)->run([self::binary($tool), '--version']);

            return $result->successful() ? trim($result->output()) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param  list<string>  $arguments */
    protected function pg(string $tool, array $arguments): void
    {
        $c = $this->connection();

        $result = Process::timeout(3600)
            ->env(['PGPASSWORD' => $c['password']])
            ->run([self::binary($tool), '-h', $c['host'], '-p', $c['port'], '-U', $c['username'], ...$arguments]);

        if (! $result->successful()) {
            throw new RuntimeException($tool.': '.Str::limit(trim($result->errorOutput()) ?: 'exit code '.$result->exitCode(), 800));
        }
    }

    // ------------------------------------------------------------ helpers

    /** Adds a folder's files under $prefix, skipping top-level $skip folders; returns how many. */
    protected function addTree(ZipArchive $zip, string $root, string $prefix, array $skip = []): int
    {
        if ($root === '' || ! is_dir($root)) {
            return 0;
        }

        $root = rtrim(realpath($root) ?: $root, '/\\');
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (in_array(strtok($relative, '/'), $skip, true)) {
                continue;
            }

            $zip->addFile($file->getPathname(), $prefix.'/'.$relative);
            $count++;
        }

        return $count;
    }

    protected function workDir(): string
    {
        $dir = storage_path('app/backup-tmp/'.Str::uuid());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /** By the shop's schedule — `startIfIdle`, so a slow one is never stacked on. */
    public static function schedule(Schedule $schedule): void
    {
        $every = (string) self::setting('schedule', 'off');

        if ($every === 'off') {
            return;
        }

        $event = $schedule->call(fn () => CreateBackup::startIfIdle())->name('core:backup');

        $every === 'weekly' ? $event->weeklyOn(0, '03:40') : $event->dailyAt('03:40');
    }
}
