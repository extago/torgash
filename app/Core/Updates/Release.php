<?php

namespace App\Core\Updates;

use RuntimeException;
use ZipArchive;

/**
 * The shape of a release package and its signature (PLAN §45.7).
 *
 *     release.json   {format, product, version, requires: {php}, paths: [...], files: {path: sha256}}
 *     release.sig    Ed25519 signature of release.json's exact bytes, base64
 *     files/<path>   every file listed
 *
 * The signature covers the list of files and their sums, and every file is
 * checked against its sum when it is unpacked — so one signature covers every
 * byte. The secret key never leaves the machine releases are built on; the
 * shop holds only public keys (`torgash.updates.public_keys`, a list, so a key
 * can be changed by publishing one release signed under the old one that
 * ships the new one).
 */
class Release
{
    public const FORMAT = 1;

    public const PRODUCT = 'torgash';

    /** @return array{secret: string, public: string} base64 */
    public static function generateKeys(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }

    public static function sign(string $bytes, string $secretB64): string
    {
        $secret = base64_decode(trim($secretB64), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The release key is not an Ed25519 secret key.');
        }

        return base64_encode(sodium_crypto_sign_detached($bytes, $secret));
    }

    /** @param  list<string>  $publicKeys  base64 */
    public static function verify(string $bytes, string $signatureB64, array $publicKeys): bool
    {
        $signature = base64_decode(trim($signatureB64), true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        foreach ($publicKeys as $key) {
            $public = base64_decode(trim((string) $key), true);
            if ($public !== false && strlen($public) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && sodium_crypto_sign_verify_detached($signature, $bytes, $public)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Writes a package.
     *
     * @param  array<string, string>  $files  relative path => absolute source path
     * @param  list<string>  $paths  the owned paths this release replaces
     */
    public static function pack(string $zipPath, string $version, array $paths, array $files, string $secretB64, string $php = '>=8.5'): void
    {
        ksort($files);
        $manifest = [
            'format' => self::FORMAT,
            'product' => self::PRODUCT,
            'version' => $version,
            'requires' => ['php' => $php],
            'built_at' => gmdate('c'),
            'paths' => array_values($paths),
            'files' => array_map(fn (string $source) => hash_file('sha256', $source), $files),
        ];
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        @mkdir(dirname($zipPath), 0755, true);
        @unlink($zipPath);
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write $zipPath.");
        }
        $zip->addFromString('release.json', $json);
        $zip->addFromString('release.sig', self::sign($json, $secretB64));
        foreach ($files as $relative => $source) {
            $zip->addFile($source, 'files/'.$relative);
        }
        $zip->close();
    }

    /** @return list<string> */
    public static function publicKeys(): array
    {
        return array_values(array_filter((array) config('torgash.updates.public_keys', [])));
    }
}
