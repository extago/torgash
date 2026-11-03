<?php

namespace App\Core\Backups\Archive;

use App\Core\Backups\Store;

/** A backup made "without an archive": a folder in a storage, or on this disk. */
class FolderReader extends Reader
{
    public function __construct(protected Store $store, protected string $folder) {}

    public function each(callable $entry): void
    {
        $files = $this->store->files($this->folder, true);
        $prefix = trim($this->folder, '/') === '' ? '' : trim($this->folder, '/').'/';
        $names = array_map(fn (string $path) => substr($path, strlen($prefix)), $files);

        // The same order the archives have: what a restore needs first.
        usort($names, fn (string $a, string $b) => [self::rank($a), $a] <=> [self::rank($b), $b]);

        foreach ($names as $name) {
            $stream = $this->store->read($prefix.$name);
            $go = $entry($name, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($go === false) {
                return;
            }
        }
    }

    protected static function rank(string $name): int
    {
        return match (true) {
            $name === 'manifest.json' => 0,
            str_starts_with($name, 'env/') => 1,
            str_starts_with($name, 'database.') => 2,
            default => 3,
        };
    }
}
