<?php

namespace App\Core\Backups\Archive;

use RuntimeException;

class TarReader extends Reader
{
    public function __construct(protected string $path) {}

    public function each(callable $entry): void
    {
        // gzopen reads a plain file as it is, so one way for tar and tar.gz.
        $in = gzopen($this->path, 'rb');
        if (! $in) {
            throw new RuntimeException('Cannot open '.$this->path);
        }

        try {
            $longName = null;

            while (true) {
                $header = $this->read($in, 512);

                if ($header === '' || trim($header, "\0") === '') {
                    return;
                }
                if (strlen($header) < 512) {
                    throw new RuntimeException('The backup is cut short (a damaged or unfinished tar).');
                }

                $type = $header[156];
                $size = $this->size(substr($header, 124, 12));
                $name = $longName ?? $this->name($header);
                $longName = null;

                if ($type === 'L') {
                    $longName = rtrim($this->read($in, $size), "\0");
                    $this->skipPadding($in, $size);

                    continue;
                }

                if ($type !== '0' && $type !== "\0") {
                    // Folders, links: nothing the engine wrote.
                    $this->skip($in, $size);

                    continue;
                }

                $stream = fopen('php://temp/maxmemory:'.(4 * 1048576), 'w+b');
                $left = $size;
                while ($left > 0) {
                    $chunk = $this->read($in, min($left, 1048576));
                    if ($chunk === '') {
                        throw new RuntimeException('The backup is cut short (a damaged or unfinished tar).');
                    }
                    fwrite($stream, $chunk);
                    $left -= strlen($chunk);
                }
                $this->skipPadding($in, $size);
                rewind($stream);

                $go = $entry($name, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if ($go === false) {
                    return;
                }
            }
        } finally {
            gzclose($in);
        }
    }

    protected function name(string $header): string
    {
        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = substr($header, 257, 6) === "ustar\0" || substr($header, 257, 5) === 'ustar'
            ? rtrim(substr($header, 345, 155), "\0")
            : '';

        return $prefix !== '' ? $prefix.'/'.$name : $name;
    }

    protected function size(string $field): int
    {
        if ((ord($field[0]) & 0x80) !== 0) {
            $size = ord($field[0]) & 0x7F;
            for ($i = 1; $i < 12; $i++) {
                $size = ($size << 8) | ord($field[$i]);
            }

            return $size;
        }

        return (int) octdec(trim($field, "\0 "));
    }

    /** @param  resource  $in */
    protected function read($in, int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = gzread($in, $bytes - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    /** @param  resource  $in */
    protected function skip($in, int $size): void
    {
        $left = $size;
        while ($left > 0) {
            $chunk = $this->read($in, min($left, 1048576));
            if ($chunk === '') {
                return;
            }
            $left -= strlen($chunk);
        }
        $this->skipPadding($in, $size);
    }

    /** @param  resource  $in */
    protected function skipPadding($in, int $size): void
    {
        if ($size % 512 !== 0) {
            $this->read($in, 512 - $size % 512);
        }
    }
}
