<?php

namespace App\Core\Backups;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

/**
 * A destination opened (PLAN §67.1): a filesystem and the folder inside it
 * that is the destination's root. Paths given to it are relative to that
 * root; nothing above it is reachable.
 */
class Store
{
    public function __construct(public readonly Filesystem $filesystem, public readonly string $root = '') {}

    public function path(string $relative): string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');

        if (in_array('..', explode('/', $relative), true)) {
            throw new RuntimeException('A path inside a backup storage cannot go up.');
        }

        return trim(trim($this->root, '/').'/'.$relative, '/');
    }

    /** @param  resource  $stream */
    public function put(string $relative, $stream): void
    {
        if ($this->filesystem->writeStream($this->path($relative), $stream) === false) {
            throw new RuntimeException('The storage did not take the file.');
        }
    }

    /** @return resource */
    public function read(string $relative)
    {
        $stream = $this->filesystem->readStream($this->path($relative));

        if (! is_resource($stream)) {
            throw new RuntimeException('The storage cannot hand the file back.');
        }

        return $stream;
    }

    public function exists(string $relative): bool
    {
        return $this->filesystem->exists($this->path($relative));
    }

    public function size(string $relative): int
    {
        return (int) $this->filesystem->size($this->path($relative));
    }

    public function delete(string $relative): void
    {
        $this->filesystem->delete($this->path($relative));
    }

    /** A backup made "without an archive": the folder and all in it. */
    public function deleteFolder(string $relative): void
    {
        if (trim($relative, '/') === '') {
            throw new RuntimeException('The storage\'s own root is never deleted.');
        }

        if ($this->filesystem->deleteDirectory($this->path($relative)) === false) {
            throw new RuntimeException('The storage did not delete the folder.');
        }
    }

    /** @return list<string> paths relative to the root */
    public function files(string $folder = '', bool $deep = false): array
    {
        $root = trim($this->root, '/');
        $files = $deep ? $this->filesystem->allFiles($this->path($folder)) : $this->filesystem->files($this->path($folder));

        return array_values(array_map(
            fn (string $path) => $root === '' ? $path : ltrim(substr($path, strlen($root)), '/'),
            $files,
        ));
    }
}
