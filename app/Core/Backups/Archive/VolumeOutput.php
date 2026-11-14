<?php

namespace App\Core\Backups\Archive;

use App\Core\Backups\Volumes;
use Closure;
use RuntimeException;

/**
 * The last stage of a chain, cut into volumes of `$size` bytes (PLAN
 * §67.12): `<name>.001`, `<name>.002`… Each volume, once full, is handed to
 * `$sealed` — which stores it and deletes it — before the next is begun, so
 * the disk holds one volume at a time whatever the backup weighs. The
 * volumes are one file's bytes cut apart: put together they are that file.
 */
class VolumeOutput implements Output
{
    /** @var resource|null */
    protected $out = null;

    protected int $number = 0;

    protected int $written = 0;

    protected ?\HashContext $volumeHash = null;

    protected \HashContext $wholeHash;

    protected int $total = 0;

    protected ?string $sha256 = null;

    /** @param  Closure(int, string, int, string): void  $sealed  number, file, bytes, sha256 */
    public function __construct(protected string $base, protected int $size, protected Closure $sealed)
    {
        if ($size < 1) {
            throw new RuntimeException('A volume must hold at least one byte.');
        }

        $this->wholeHash = hash_init('sha256');
    }

    public function write(string $data): void
    {
        while ($data !== '') {
            if ($this->out === null) {
                $this->begin();
            }

            $piece = substr($data, 0, $this->size - $this->written);
            $data = (string) substr($data, strlen($piece));

            if (fwrite($this->out, $piece) !== strlen($piece)) {
                throw new RuntimeException('Could not write '.$this->file().' (is the disk full?)');
            }

            hash_update($this->volumeHash, $piece);
            hash_update($this->wholeHash, $piece);
            $this->written += strlen($piece);
            $this->total += strlen($piece);

            if ($this->written >= $this->size) {
                $this->seal();
            }
        }
    }

    public function close(): void
    {
        if ($this->out === null && $this->number === 0) {
            $this->begin();
        }

        if ($this->out !== null) {
            $this->seal();
        }

        $this->sha256 = hash_final($this->wholeHash);
    }

    public function volumes(): int
    {
        return $this->number;
    }

    /** The bytes of all the volumes together. */
    public function bytes(): int
    {
        return $this->total;
    }

    /** The checksum of the volumes put together — of the one file they are. */
    public function sha256(): ?string
    {
        return $this->sha256;
    }

    protected function begin(): void
    {
        $this->number++;
        $out = @fopen($this->file(), 'wb');

        if (! $out) {
            throw new RuntimeException('Could not create '.$this->file());
        }

        $this->out = $out;
        $this->written = 0;
        $this->volumeHash = hash_init('sha256');
    }

    protected function seal(): void
    {
        fclose($this->out);
        $this->out = null;
        ($this->sealed)($this->number, $this->file(), $this->written, hash_final($this->volumeHash));
    }

    protected function file(): string
    {
        return Volumes::file($this->base, $this->number);
    }
}
