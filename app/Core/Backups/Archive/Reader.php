<?php

namespace App\Core\Backups\Archive;

use App\Core\Backups\Store;
use RuntimeException;

/**
 * A backup read the same way whatever form it was made in (PLAN §67.3): a
 * zip (encrypted or not), a tar or tar.gz on this server's disk, or a folder
 * — in a storage or on this disk. `each()` hands every entry in the order
 * it was written and stops when the callback says `false`: the manifest,
 * the database and `.env` come first, so reading them alone does not mean
 * decompressing gigabytes of pictures.
 */
abstract class Reader
{
    /** @param  callable(string, resource): (bool|null)  $entry  false stops */
    abstract public function each(callable $entry): void;

    public function close(): void {}

    /** Opens a file made by the engine: zip, tar or tar.gz (decrypted already). */
    public static function file(string $path, ?string $password = null): self
    {
        $head = (string) file_get_contents($path, false, null, 0, 4);

        if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06")) {
            return new ZipReader($path, $password);
        }

        if (str_starts_with($head, "\x1f\x8b") || self::isTar($path)) {
            return new TarReader($path);
        }

        if (str_starts_with($head, 'Salt')) {
            throw new RuntimeException('The backup is encrypted: give its password.');
        }

        throw new RuntimeException('The file is not a backup the engine can read (zip, tar or tar.gz).');
    }

    protected static function isTar(string $path): bool
    {
        return substr((string) file_get_contents($path, false, null, 257, 5), 0, 5) === 'ustar';
    }
}
