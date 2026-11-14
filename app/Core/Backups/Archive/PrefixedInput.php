<?php

namespace App\Core\Backups\Archive;

/** Bytes already read to tell what follows (a cipher's head, gzip's magic), handed out again first. */
class PrefixedInput implements Input
{
    public function __construct(protected string $head, protected Input $rest) {}

    public function read(int $bytes): string
    {
        if ($this->head !== '') {
            $out = substr($this->head, 0, $bytes);
            $this->head = (string) substr($this->head, strlen($out));

            return $out;
        }

        return $this->rest->read($bytes);
    }

    public function close(): void
    {
        $this->rest->close();
    }

    /** Reads up to $bytes from the start of $input without losing them. */
    public static function peek(Input $input, int $bytes): array
    {
        $head = '';
        while (strlen($head) < $bytes) {
            $chunk = $input->read($bytes - strlen($head));
            if ($chunk === '') {
                break;
            }
            $head .= $chunk;
        }

        return [$head, new self($head, $input)];
    }
}
