<?php

namespace App\Core\Backups\Archive;

use RuntimeException;

/**
 * A tar written by the engine itself (PLAN §67.3) — no `tar` on the server
 * needed, gzip by zlib when asked. ustar headers; a name longer than ustar
 * holds goes as a GNU long-name entry, a file over 8 GB gets its size in
 * base-256 — both read by GNU tar, bsdtar (Windows' own tar) and 7-Zip.
 */
class TarWriter
{
    /** @var resource */
    protected $out;

    protected bool $gzip;

    /** @param  int|null  $level  gzip level 1–9; null for a plain tar */
    public function __construct(protected string $path, ?int $level = null)
    {
        $this->gzip = $level !== null;
        $out = $this->gzip ? gzopen($path, 'wb'.max(1, min(9, $level))) : fopen($path, 'wb');

        if (! $out) {
            throw new RuntimeException('Could not create '.$path);
        }

        $this->out = $out;
    }

    public function addFile(string $name, string $source): void
    {
        $size = filesize($source);
        $in = fopen($source, 'rb');

        if ($in === false || $size === false) {
            throw new RuntimeException('Cannot read '.$source);
        }

        $this->header($name, $size, (int) filemtime($source));

        while (! feof($in)) {
            $this->write((string) fread($in, 1048576));
        }
        fclose($in);

        $this->pad($size);
    }

    public function addString(string $name, string $contents): void
    {
        $this->header($name, strlen($contents), time());
        $this->write($contents);
        $this->pad(strlen($contents));
    }

    public function close(): void
    {
        // Two empty blocks end the archive.
        $this->write(str_repeat("\0", 1024));
        $this->gzip ? gzclose($this->out) : fclose($this->out);
    }

    protected function header(string $name, int $size, int $mtime): void
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');

        if (strlen($name) > 100) {
            // GNU: an entry "././@LongLink" of type L whose data is the name.
            $this->write($this->block('././@LongLink', strlen($name) + 1, 0, 'L'));
            $this->write($name."\0");
            $this->pad(strlen($name) + 1);
            $name = substr($name, 0, 100);
        }

        $this->write($this->block($name, $size, $mtime, '0'));
    }

    protected function block(string $name, int $size, int $mtime, string $type): string
    {
        $header = str_pad($name, 100, "\0")
            .$this->octal(0644, 8)
            .$this->octal(0, 8)
            .$this->octal(0, 8)
            .$this->size($size)
            .$this->octal($mtime, 12)
            .str_repeat(' ', 8)          // the checksum, counted as spaces
            .$type
            .str_repeat("\0", 100)
            ."ustar\0"."00"
            .str_pad('torgash', 32, "\0")
            .str_pad('torgash', 32, "\0")
            .str_repeat("\0", 16 + 155 + 12);

        $sum = array_sum(array_map('ord', str_split($header)));

        return substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
    }

    protected function octal(int $value, int $width): string
    {
        return str_pad(decoct($value), $width - 1, '0', STR_PAD_LEFT)."\0";
    }

    protected function size(int $size): string
    {
        if ($size < 0o77777777777) {
            return $this->octal($size, 12);
        }

        // Base-256: the high bit of the first byte set, the number big-endian.
        $bytes = '';
        for ($i = 0; $i < 11; $i++) {
            $bytes = chr($size & 0xFF).$bytes;
            $size >>= 8;
        }

        return "\x80".$bytes;
    }

    protected function pad(int $size): void
    {
        $rest = $size % 512;

        if ($rest !== 0) {
            $this->write(str_repeat("\0", 512 - $rest));
        }
    }

    protected function write(string $data): void
    {
        if ($data === '') {
            return;
        }

        $written = $this->gzip ? gzwrite($this->out, $data) : fwrite($this->out, $data);

        if ($written !== strlen($data)) {
            throw new RuntimeException('Could not write '.$this->path.' (is the disk full?)');
        }
    }
}
