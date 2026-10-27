<?php

namespace App\Core\Updates;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

/**
 * Update packages handed in by hand (PLAN §45.8): for a shop that cannot
 * download from the update server — a blocked address, a firewall, a host
 * without outgoing connections. Uploaded on the System page, or put into
 * `storage/app/update/incoming/` by FTP when the file is larger than PHP's
 * upload limit; either way it is listed with what its own signature says,
 * and installed by the same InstallUpdate job, so nothing about how a
 * package is checked depends on how it arrived.
 */
class IncomingPackages
{
    public function dir(): string
    {
        return storage_path(InstallUpdate::INCOMING);
    }

    /**
     * Every zip waiting there, newest version first, each read through
     * UpdatePackage::open() — a file not signed by the makers is listed as
     * such, never as an update.
     *
     * @return list<array{name: string, size: int, version: ?string, error: ?string, newer: bool}>
     */
    public function all(): array
    {
        $current = (string) config('torgash.version');
        $out = [];

        foreach (glob($this->dir().'/*.zip') ?: [] as $file) {
            try {
                $version = UpdatePackage::open($file)->version();
                $error = null;
            } catch (Throwable $e) {
                $version = null;
                $error = $e->getMessage();
            }

            $out[] = [
                'name' => basename($file),
                'size' => (int) filesize($file),
                'version' => $version,
                'error' => $error,
                'newer' => $version !== null && version_compare($version, $current, '>'),
            ];
        }

        usort($out, fn ($a, $b) => version_compare((string) $b['version'], (string) $a['version']));

        return $out;
    }

    /**
     * An uploaded file, checked before it is kept: a package this code
     * cannot verify, or one that is not newer, is refused and removed.
     *
     * @return string the version it carries
     */
    public function store(UploadedFile $file): string
    {
        @mkdir($this->dir(), 0755, true);
        $temporary = $this->dir().'/upload-'.bin2hex(random_bytes(6)).'.zip';
        $file->move($this->dir(), basename($temporary));

        try {
            $version = UpdatePackage::open($temporary)->version();
        } catch (Throwable $e) {
            @unlink($temporary);
            throw new RuntimeException($e->getMessage());
        }

        if (! version_compare($version, (string) config('torgash.version'), '>')) {
            @unlink($temporary);
            throw new RuntimeException(__('This package is Torgash :version, and the shop already runs :current.', ['version' => $version, 'current' => config('torgash.version')]));
        }

        $target = $this->dir().'/torgash-'.$version.'.zip';
        @unlink($target);
        rename($temporary, $target);

        return $version;
    }

    /** The absolute path of a waiting package by its file name, or null — only plain names inside the folder. */
    public function path(string $name): ?string
    {
        if (! preg_match('/^[A-Za-z0-9._-]+\.zip$/', $name)) {
            return null;
        }

        $path = $this->dir().'/'.$name;

        return is_file($path) ? $path : null;
    }

    public function delete(string $name): void
    {
        if ($path = $this->path($name)) {
            @unlink($path);
        }
    }
}
