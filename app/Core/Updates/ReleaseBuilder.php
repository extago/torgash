<?php

namespace App\Core\Updates;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use ZipArchive;

/**
 * Builds a signed release of this install (PLAN §45.7а) — on the machine the
 * engine is developed on, never on a shop: it needs the secret key.
 *
 * The files are what git tracks under the owned paths (a stray log or a
 * build leftover never ships), from a clean working tree for those paths
 * (what is released is a commit, not whatever happens to be on disk), plus
 * `vendor/` installed afresh WITHOUT the dev packages in a scratch copy.
 */
class ReleaseBuilder
{
    /**
     * @param  callable(string): void|null  $say
     * @return string the package's path
     */
    public function build(string $secretB64, ?callable $say = null, bool $allowDirty = false): string
    {
        $say ??= fn () => null;
        $root = base_path();
        $version = (string) config('torgash.version');

        $paths = (new Distribution($root))->paths();
        $tracked = array_values(array_filter($paths, fn ($p) => $p !== 'vendor'));

        if (! $allowDirty) {
            $dirty = trim($this->git(['status', '--porcelain', '--', ...$tracked]));
            if ($dirty !== '') {
                throw new RuntimeException("Uncommitted changes in the released paths — commit them first:\n".$dirty);
            }
        }

        $stage = storage_path('app/releases/build-'.$version);
        File::deleteDirectory($stage);
        File::ensureDirectoryExists($stage);

        $say('Copying the tracked files');
        $list = array_filter(explode("\0", $this->git(['ls-files', '-z', '--', ...$tracked])));
        foreach ($list as $relative) {
            if (! is_file($root.'/'.$relative)) {
                continue;
            }
            File::ensureDirectoryExists(dirname($stage.'/'.$relative));
            File::copy($root.'/'.$relative, $stage.'/'.$relative);
        }

        $say('Installing the libraries without the development ones');
        $composer = Process::path($stage)->timeout(900)->env(['COMPOSER_NO_INTERACTION' => '1'])
            ->run([...\App\Core\Support\Php::command(), $root.'/tools/composer.phar', 'install', '--no-dev', '--optimize-autoloader', '--no-scripts', '--no-progress', '--prefer-dist']);
        if (! $composer->successful()) {
            throw new RuntimeException('composer install failed: '.trim($composer->errorOutput()));
        }

        // Exactly the owned paths, vendor included now.
        $files = (new Distribution($stage))->files($paths);

        $say('Signing and packing '.count($files).' files');
        $zip = storage_path('app/releases/torgash-'.$version.'.zip');
        Release::pack($zip, $version, $paths, $files, $secretB64);
        self::checksum($zip, $secretB64);

        $say('Packing the distribution for a new shop');
        $this->distribution($root, $version, $files, $secretB64);

        File::deleteDirectory($stage);

        return $zip;
    }

    /**
     * The other half of a release (PLAN §54): what somebody downloads to
     * start a shop. The same files as the update — vendor included, so no
     * Composer on the server — at the top of a `torgash-<version>/` folder,
     * plus what only a NEW install needs and an update must never touch:
     * `.env.example`, the empty `storage/` and `bootstrap/cache/` folders,
     * INSTALL notes. Unpacked, it is installed by `php artisan
     * torgash:install` (or, later, /install in a browser).
     *
     * @param  array<string, string>  $files  the update's files: relative => absolute
     * @return string the distribution's path
     */
    public function distribution(string $root, string $version, array $files, string $secretB64): string
    {
        $extras = array_filter(explode("\0", $this->git(['ls-files', '-z', '--', ...self::DISTRIBUTION_EXTRAS])));
        foreach ($extras as $relative) {
            if (is_file($root.'/'.$relative)) {
                $files[$relative] = $root.'/'.$relative;
            }
        }
        ksort($files);

        $path = storage_path('app/releases/torgash-'.$version.'-install.zip');
        @unlink($path);
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write $path.");
        }
        $folder = 'torgash-'.$version.'/';
        foreach ($files as $relative => $source) {
            $zip->addFile($source, $folder.$relative);
        }
        $zip->close();

        self::checksum($path, $secretB64);

        return $path;
    }

    /** Only in the distribution: what a new shop needs and an update leaves alone (tracked files only). */
    public const DISTRIBUTION_EXTRAS = ['.env.example', 'storage', 'bootstrap/cache', 'INSTALL.md', 'INSTALL.ru.md', 'README.md', 'LICENSE', 'THIRD-PARTY.md'];

    /**
     * `<zip>.sha256` for the download page ("sha256sum -c" reads it) and
     * `<zip>.sig`, the release key's Ed25519 signature over that sum — what
     * an installer that downloads the archive checks before unpacking it.
     */
    public static function checksum(string $zip, string $secretB64): void
    {
        $sum = hash_file('sha256', $zip);
        file_put_contents($zip.'.sha256', $sum.'  '.basename($zip)."\n");
        file_put_contents($zip.'.sig', Release::sign($sum, $secretB64)."\n");
    }

    protected function git(array $args): string
    {
        $result = Process::path(base_path())->run(['git', ...$args]);
        if (! $result->successful()) {
            throw new RuntimeException('git '.implode(' ', $args).' failed: '.trim($result->errorOutput()));
        }

        return $result->output();
    }
}
