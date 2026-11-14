<?php

namespace App\Core\Backups\Archive;

use Closure;

/**
 * The volumes of a backup read as the one file they are (PLAN §67.12):
 * `$open(n)` hands a local path to volume n — fetched from a storage and
 * checked, or lying on this disk — and `$done(n, path)` is told when it has
 * been read through, so a fetched volume is deleted before the next is
 * fetched. A server short of disk holds one volume at a time.
 */
class VolumeInput implements Input
{
    protected int $number = 0;

    protected ?FileInput $current = null;

    protected ?string $path = null;

    /**
     * @param  Closure(int): string  $open
     * @param  Closure(int, string): void  $done
     */
    public function __construct(protected int $count, protected Closure $open, protected Closure $done) {}

    public function read(int $bytes): string
    {
        while (true) {
            if ($this->current === null) {
                if ($this->number >= $this->count) {
                    return '';
                }

                $this->number++;
                $this->path = ($this->open)($this->number);
                $this->current = new FileInput($this->path);
            }

            $data = $this->current->read($bytes);
            if ($data !== '') {
                return $data;
            }

            $this->finish();
        }
    }

    public function close(): void
    {
        $this->finish();
        $this->number = $this->count;
    }

    protected function finish(): void
    {
        if ($this->current !== null) {
            $this->current->close();
            ($this->done)($this->number, (string) $this->path);
        }

        $this->current = null;
        $this->path = null;
    }
}
