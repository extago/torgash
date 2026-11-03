<?php

namespace App\Core\Backups\Archive;

use RuntimeException;

/**
 * A whole file encrypted the way `openssl enc` does it (PLAN §67.3), so an
 * encrypted tar opens without the engine:
 *
 *     openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in x.tar.gz.enc -out x.tar.gz
 *
 * "Salted__", an 8-byte salt, then AES-256-CBC with PKCS#7 padding; key and
 * IV are 48 bytes of PBKDF2-HMAC-SHA256 over the password and the salt.
 * Streamed a megabyte at a time: a backup is gigabytes, never held whole.
 */
class Cipher
{
    public const ITERATIONS = 200000;

    protected const CHUNK = 1048576; // a multiple of the 16-byte block

    public static function command(string $file = 'backup.tar.gz.enc'): string
    {
        return 'openssl enc -d -aes-256-cbc -pbkdf2 -iter '.self::ITERATIONS.' -in '.$file.' -out '.preg_replace('/\.enc$/', '', $file);
    }

    public static function encryptFile(string $from, string $to, string $password): void
    {
        $in = fopen($from, 'rb');
        $out = fopen($to, 'wb');

        try {
            $salt = random_bytes(8);
            [$key, $iv] = self::derive($password, $salt);
            fwrite($out, 'Salted__'.$salt);

            $chunk = (string) fread($in, self::CHUNK);
            while (true) {
                $next = feof($in) ? '' : (string) fread($in, self::CHUNK);

                if ($next === '' && feof($in)) {
                    // The last piece carries the padding (a whole block when
                    // the file ends on a block's edge, as openssl writes it).
                    fwrite($out, (string) openssl_encrypt($chunk, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv));

                    break;
                }

                $sealed = openssl_encrypt($chunk, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
                if ($sealed === false) {
                    throw new RuntimeException('The backup could not be encrypted.');
                }
                fwrite($out, $sealed);
                $iv = substr($sealed, -16);
                $chunk = $next;
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public static function decryptFile(string $from, string $to, string $password): void
    {
        $in = fopen($from, 'rb');
        $out = fopen($to, 'wb');

        try {
            $head = (string) fread($in, 16);
            if (strlen($head) !== 16 || ! str_starts_with($head, 'Salted__')) {
                throw new RuntimeException('The backup is not an encrypted file the engine made.');
            }
            [$key, $iv] = self::derive($password, substr($head, 8));

            $chunk = (string) fread($in, self::CHUNK);
            while (true) {
                $next = feof($in) ? '' : (string) fread($in, self::CHUNK);
                $last = $next === '' && feof($in);

                $open = openssl_decrypt($chunk, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | ($last ? 0 : OPENSSL_ZERO_PADDING), $iv);
                if ($open === false) {
                    throw new RuntimeException('Wrong password for the backup (or the file is damaged).');
                }
                fwrite($out, $open);

                if ($last) {
                    break;
                }
                $iv = substr($chunk, -16);
                $chunk = $next;
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** @return array{0: string, 1: string} key and IV */
    protected static function derive(string $password, string $salt): array
    {
        $bytes = hash_pbkdf2('sha256', $password, $salt, self::ITERATIONS, 48, true);

        return [substr($bytes, 0, 32), substr($bytes, 32, 16)];
    }
}
