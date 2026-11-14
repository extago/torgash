<?php

namespace App\Core\Backups\Archive;

use RuntimeException;

/** The end of a chain: one file on this server's disk. */
class FileOutput implements Output
{
    /** @var resource */
    protected $out;

    public function __construct(protected string $path)
    {
        $out = @fopen($path, 'wb');

        if (! $out) {
            throw new RuntimeException('Could not create '.$path);
        }

        $this->out = $out;
    }

    public function write(string $data): void
    {
        if ($data !== '' && fwrite($this->out, $data) !== strlen($data)) {
            throw new RuntimeException('Could not write '.$this->path.' (is the disk full?)');
        }
    }

    public function close(): void
    {
        if (is_resource($this->out)) {
            fclose($this->out);
        }
    }
}
