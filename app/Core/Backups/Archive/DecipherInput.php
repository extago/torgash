<?php

namespace App\Core\Backups\Archive;

use RuntimeException;

/**
 * `Cipher::decryptFile()` as a stage: an encrypted tar is read as it comes,
 * never decrypted whole into a second file first — on a server short of
 * disk that second file is what does not fit.
 */
class DecipherInput implements Input
{
    protected const CHUNK = 1048576;

    protected string $key;

    protected string $iv;

    protected string $pending = '';

    protected string $plain = '';

    protected bool $ended = false;

    public function __construct(protected Input $in, string $password)
    {
        $head = '';
        while (strlen($head) < 16 && ($chunk = $in->read(16 - strlen($head))) !== '') {
            $head .= $chunk;
        }

        if (strlen($head) !== 16 || ! str_starts_with($head, 'Salted__')) {
            throw new RuntimeException('The backup is not an encrypted file the engine made.');
        }

        [$this->key, $this->iv] = Cipher::derive($password, substr($head, 8));
    }

    public function read(int $bytes): string
    {
        while (strlen($this->plain) < $bytes && ! $this->ended) {
            $chunk = $this->in->read(self::CHUNK);

            if ($chunk === '') {
                $this->ended = true;
                if ($this->pending === '') {
                    throw new RuntimeException('The backup is cut short (a damaged or unfinished file).');
                }

                // The last block carries the padding: a wrong password shows here.
                $open = openssl_decrypt($this->pending, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $this->iv);
                if ($open === false) {
                    throw new RuntimeException('Wrong password for the backup (or the file is damaged).');
                }
                $this->plain .= $open;
                $this->pending = '';

                break;
            }

            $this->pending .= $chunk;

            // Keep at least one whole block back for the end.
            $whole = intdiv(strlen($this->pending) - 1, 16) * 16;
            if ($whole > 0) {
                $cipher = substr($this->pending, 0, $whole);
                $open = openssl_decrypt($cipher, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv);
                if ($open === false) {
                    throw new RuntimeException('Wrong password for the backup (or the file is damaged).');
                }
                $this->plain .= $open;
                $this->iv = substr($cipher, -16);
                $this->pending = substr($this->pending, $whole);
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
