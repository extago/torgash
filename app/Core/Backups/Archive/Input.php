<?php

namespace App\Core\Backups\Archive;

/**
 * Where a backup's bytes come from, a piece at a time (PLAN §67.12): a file,
 * a chain of volumes fetched one after another, and the stages that undo
 * what the writer did — the cipher, gzip. `read()` returns '' only at the end.
 */
interface Input
{
    public function read(int $bytes): string;

    public function close(): void;
}
