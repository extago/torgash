<?php

namespace App\Core\Backups\Archive;

use RuntimeException;

/**
 * The cipher as a stage of the chain: byte for byte the form
 * `Cipher::encryptFile()` writes — "Salted__", the salt, AES-256-CBC with
 * PKCS#7 padding — so volumes put together open with the same `openssl enc`.
 */
class CipherOutput implements Output
{
    protected const CHUNK = 1048576;

    protected string $buffer = '';

    protected string $key;

    protected string $iv;

    public function __construct(protected Output $next, string $password)
    {
        $salt = random_bytes(8);
        [$this->key, $this->iv] = Cipher::derive($password, $salt);
        $next->write('Salted__'.$salt);
    }

    public function write(string $data): void
    {
        $this->buffer .= $data;

        if (strlen($this->buffer) < self::CHUNK) {
            return;
        }

        // Whole blocks go now; the rest waits — the last piece carries the padding.
        $whole = strlen($this->buffer) - strlen($this->buffer) % 16;
        $sealed = openssl_encrypt(substr($this->buffer, 0, $whole), 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv);

        if ($sealed === false) {
            throw new RuntimeException('The backup could not be encrypted.');
        }

        $this->next->write($sealed);
        $this->iv = substr($sealed, -16);
        $this->buffer = substr($this->buffer, $whole);
    }

    public function close(): void
    {
        // A whole block of padding when nothing is left, as openssl writes it.
        $last = openssl_encrypt($this->buffer, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $this->iv);

        if ($last === false) {
            throw new RuntimeException('The backup could not be encrypted.');
        }

        $this->buffer = '';
        $this->next->write($last);
        $this->next->close();
    }
}
