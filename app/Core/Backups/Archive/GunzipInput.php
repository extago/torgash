<?php

namespace App\Core\Backups\Archive;

use RuntimeException;

/** gzip undone as a stage of the chain. */
class GunzipInput implements Input
{
    protected const CHUNK = 1048576;

    protected \InflateContext $inflate;

    protected string $plain = '';

    protected bool $ended = false;

    public function __construct(protected Input $in, protected string $damaged = 'The backup is damaged: its gzip cannot be read.')
    {
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);

        if ($inflate === false) {
            throw new RuntimeException('zlib cannot decompress here.');
        }

        $this->inflate = $inflate;
    }

    public function read(int $bytes): string
    {
        while (strlen($this->plain) < $bytes && ! $this->ended) {
            $chunk = $this->in->read(self::CHUNK);
            $this->ended = $chunk === '';

            $open = @inflate_add($this->inflate, $chunk, $this->ended ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($open === false) {
                throw new RuntimeException($this->damaged);
            }
            $this->plain .= $open;

            // One gzip member is what the engine writes; what follows it is not read.
            if (inflate_get_status($this->inflate) === ZLIB_STREAM_END) {
                $this->ended = true;
            }
        }

        $out = substr($this->plain, 0, $bytes);
        $this->plain = (string) substr($this->plain, strlen($out));

        return $out;
    }

    public function close(): void
    {
        $this->in->close();
    }
}
