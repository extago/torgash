<?php

namespace App\Core\Updates;

use Composer\Semver\Semver;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * A release package, opened and checked before a byte of it is used
 * (PLAN §45.7): the signature against the shop's public keys, the shape, that
 * every entry is a listed file under a path the release may own and every
 * listed file is there. Unpacking checks each file against its sum.
 */
class UpdatePackage
{
    /** @param  array{format: int, product: string, version: string, requires: array, paths: list<string>, files: array<string, string>}  $manifest */
    protected function __construct(protected string $zipPath, protected array $manifest) {}

    /** @param  list<string>|null  $publicKeys  null = the shop's own (`torgash.updates.public_keys`) */
    public static function open(string $zipPath, ?array $publicKeys = null): self
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException(__('This is not an update package.'));
        }

        try {
            $json = $zip->getFromName('release.json');
            $signature = $zip->getFromName('release.sig');
            if ($json === false || $signature === false) {
                throw new RuntimeException(__('This is not an update package.'));
            }

            // First, before the contents are believed in any way.
            if (! Release::verify($json, $signature, $publicKeys ?? Release::publicKeys())) {
                throw new RuntimeException(__('The package is not signed by the makers of Torgash. It was not installed.'));
            }

            $manifest = json_decode($json, true);
            if (! is_array($manifest) || ($manifest['format'] ?? null) !== Release::FORMAT || ($manifest['product'] ?? null) !== Release::PRODUCT
                || ! is_string($manifest['version'] ?? null) || ! is_array($manifest['paths'] ?? null) || ! is_array($manifest['files'] ?? null)) {
                throw new RuntimeException(__('The package describes itself in a way this version does not read.'));
            }

            foreach ($manifest['paths'] as $path) {
                if (! is_string($path) || ! Distribution::ownable($path)) {
                    throw new RuntimeException(__('The package claims a path it may not replace: :path', ['path' => (string) $path]));
                }
            }

            $listed = [];
            foreach ($manifest['files'] as $path => $sum) {
                if (! Distribution::safePath((string) $path, $manifest['paths']) || ! is_string($sum) || ! preg_match('/^[0-9a-f]{64}$/', $sum)) {
                    throw new RuntimeException(__('The package names a file it may not write: :path', ['path' => (string) $path]));
                }
                $listed['files/'.$path] = true;
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === 'release.json' || $name === 'release.sig' || str_ends_with($name, '/')) {
                    continue;
                }
                if (! isset($listed[$name])) {
                    throw new RuntimeException(__('The package holds a file its list does not name: :path', ['path' => $name]));
                }
                unset($listed[$name]);
            }
            if ($listed !== []) {
                throw new RuntimeException(__('The package is missing :count file(s) it lists.', ['count' => count($listed)]));
            }
        } finally {
            $zip->close();
        }

        return new self($zipPath, $manifest);
    }

    public function version(): string
    {
        return $this->manifest['version'];
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->manifest['paths'];
    }

    /** @return array<string, string> path => sha256 */
    public function files(): array
    {
        return $this->manifest['files'];
    }

    public function requiresPhp(): string
    {
        return (string) ($this->manifest['requires']['php'] ?? '*');
    }

    /** Only forwards, and only onto a PHP it says it runs on. */
    public function assertApplicable(string $current): void
    {
        if (version_compare($this->version(), $current, '<=')) {
            throw new RuntimeException(__('Torgash :current is installed; the package holds :version. Only a newer version is installed.', ['current' => $current, 'version' => $this->version()]));
        }

        try {
            $fits = Semver::satisfies(PHP_VERSION, $this->requiresPhp());
        } catch (Throwable) {
            $fits = false;
        }
        if (! $fits) {
            throw new RuntimeException(__('Torgash :version needs PHP :wanted; this server has :php.', ['version' => $this->version(), 'wanted' => $this->requiresPhp(), 'php' => PHP_VERSION]));
        }
    }

    /** Unpacks every file into $dir, checking each against its sum. */
    public function extractTo(string $dir): void
    {
        $zip = new ZipArchive;
        if ($zip->open($this->zipPath) !== true) {
            throw new RuntimeException(__('This is not an update package.'));
        }

        try {
            foreach ($this->files() as $path => $sum) {
                $target = $dir.'/'.$path;
                @mkdir(dirname($target), 0755, true);

                $in = $zip->getStream('files/'.$path);
                $out = fopen($target, 'wb');
                if ($in === false || $out === false) {
                    throw new RuntimeException(__('Could not unpack :path.', ['path' => $path]));
                }
                $hash = hash_init('sha256');
                while (! feof($in)) {
                    $chunk = (string) fread($in, 1 << 16);
                    hash_update($hash, $chunk);
                    fwrite($out, $chunk);
                }
                fclose($in);
                fclose($out);

                if (! hash_equals($sum, hash_final($hash))) {
                    throw new RuntimeException(__('A file in the package is not what was signed: :path', ['path' => $path]));
                }
            }
        } finally {
            $zip->close();
        }
    }
}
