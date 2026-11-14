<?php

namespace App\Core\Backups\Archive;

use Closure;
use RuntimeException;

/**
 * Reads a tar the engine made — plain or gzip, and since PLAN §67.12 also
 * encrypted or cut into volumes, as it comes: a file on this disk, or a
 * `Closure(): Input` that opens the bytes from the beginning every time
 * `each()` is called (volumes are fetched again for a second pass; the head
 * of a backup lies in the first of them).
 */
class TarReader extends Reader
{
    /** @param  string|Closure(): Input  $source */
    public function __construct(protected string|Closure $source) {}

    /**
     * The chain that undoes a writer: the cipher when the bytes begin with
     * "Salted__" (a password needed), gzip when they begin with its magic.
     */
    public static function unwrap(Input $raw, ?string $password = null): Input
    {
        [$head, $raw] = PrefixedInput::peek($raw, 8);

        if ($head === 'Salted__') {
            if ($password === null || $password === '') {
                $raw->close();

                throw new RuntimeException('The backup is encrypted: give its password (--password).');
            }

            $raw = new DecipherInput($raw, $password);
            $damaged = 'Wrong password for the backup (or the file is damaged).';
            [$head, $raw] = PrefixedInput::peek($raw, 263);

            // What a wrong key gives is noise: neither gzip nor a tar header.
            if (! str_starts_with($head, "\x1f\x8b") && substr($head, 257, 5) !== 'ustar') {
                $raw->close();

                throw new RuntimeException($damaged);
            }
        }

        return str_starts_with($head, "\x1f\x8b")
            ? new GunzipInput($raw, $damaged ?? 'The backup is damaged: its gzip cannot be read.')
            : $raw;
    }

    public function each(callable $entry): void
    {
        $in = is_string($this->source)
            ? self::unwrap(new FileInput($this->source))
            : ($this->source)();

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
                if (! $this->checksumFits($header)) {
                    throw new RuntimeException('The backup is not a tar the engine can read: damaged, or the password is wrong.');
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
            $in->close();
        }
    }

    /** The header's own sum, counted with its checksum field as spaces. */
    protected function checksumFits(string $header): bool
    {
        $field = trim(substr($header, 148, 8), "\0 ");

        if (preg_match('/^[0-7]+$/', $field) !== 1) {
            return false;
        }

        $blank = substr_replace($header, str_repeat(' ', 8), 148, 8);
        $sum = array_sum(array_map('ord', str_split($blank)));

        return $sum === (int) octdec($field);
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

    protected function read(Input $in, int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = $in->read($bytes - strlen($data));
            if ($chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    protected function skip(Input $in, int $size): void
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

    protected function skipPadding(Input $in, int $size): void
    {
        if ($size % 512 !== 0) {
            $this->read($in, 512 - $size % 512);
        }
    }
}
