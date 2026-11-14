<?php

namespace App\Core\Backups\Archive;

use RuntimeException;

/** gzip as a stage of the chain: what `tar.gz` is, written as it goes. */
class GzipOutput implements Output
{
    protected \DeflateContext $deflate;

    public function __construct(protected Output $next, int $level = 6)
    {
        $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => max(1, min(9, $level))]);

        if ($deflate === false) {
            throw new RuntimeException('zlib cannot compress here.');
        }

        $this->deflate = $deflate;
    }

    public function write(string $data): void
    {
        if ($data === '') {
            return;
        }

        $this->next->write((string) deflate_add($this->deflate, $data, ZLIB_NO_FLUSH));
    }

    public function close(): void
    {
        $this->next->write((string) deflate_add($this->deflate, '', ZLIB_FINISH));
        $this->next->close();
    }
}
