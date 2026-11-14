<?php

namespace App\Core\Backups\Archive;

use RuntimeException;

class FileInput implements Input
{
    /** @var resource|null */
    protected $in;

    public function __construct(protected string $path)
    {
        $in = @fopen($path, 'rb');

        if (! $in) {
            throw new RuntimeException('Cannot open '.$path);
        }

        $this->in = $in;
    }

    public function read(int $bytes): string
    {
        if ($this->in === null || $bytes < 1) {
            return '';
        }

        $data = fread($this->in, $bytes);

        return $data === false ? '' : $data;
    }

    public function close(): void
    {
        if (is_resource($this->in)) {
            fclose($this->in);
        }
        $this->in = null;
    }
}
