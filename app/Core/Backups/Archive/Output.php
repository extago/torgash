<?php

namespace App\Core\Backups\Archive;

/**
 * Where a backup's bytes go, one piece after another (PLAN §67.12): a file,
 * or the next stage of a chain — gzip, the cipher, the volumes. Nothing is
 * ever held whole: a tar of fifty gigabytes passes through a megabyte at a
 * time, and with volumes no stage leaves more than one volume on the disk.
 */
interface Output
{
    public function write(string $data): void;

    /** Flushes what the stage still holds and closes the stages after it. */
    public function close(): void;
}
