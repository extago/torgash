<?php

namespace App\Core\Backups\Archive;

use RuntimeException;
use ZipArchive;

/** A zip backup, encrypted with AES-256 or not (PLAN §67.3). */
class ZipReader extends Reader
{
    protected ZipArchive $zip;

    protected bool $hasPassword;

    public function __construct(string $path, ?string $password = null)
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($path) !== true) {
            throw new RuntimeException('The backup is not a readable zip.');
        }

        $this->hasPassword = $password !== null && $password !== '';

        if ($this->hasPassword) {
            $this->zip->setPassword($password);
        }
    }

    public function each(callable $entry): void
    {
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = (string) $this->zip->getNameIndex($i);

            if (str_ends_with($name, '/')) {
                continue;
            }

            $stat = $this->zip->statIndex($i, ZipArchive::FL_ENC_RAW);
            if (($stat['encryption_method'] ?? 0) !== ZipArchive::EM_NONE && ! $this->hasPassword) {
                throw new RuntimeException('The backup is encrypted: give its password.');
            }

            // A wrong AES password is caught by libzip's check bytes before
            // anything is read: no stream at all.
            $stream = $this->zip->getStream($name);
            if ($stream === false) {
                throw new RuntimeException($this->hasPassword
                    ? 'Wrong password for the backup (or the file is damaged).'
                    : 'Cannot read '.$name.' from the backup.');
            }

            $go = $entry($name, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($go === false) {
                return;
            }
        }
    }

    public function close(): void
    {
        $this->zip->close();
    }
}
