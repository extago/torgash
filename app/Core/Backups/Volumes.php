<?php

namespace App\Core\Backups;

use App\Models\BackupRun;
use RuntimeException;

/**
 * A backup cut into volumes (PLAN §67.12): `<name>.001`, `<name>.002`… —
 * the bytes of one tar (or tar.gz, or its encrypted form) cut apart, so
 * `cat <name>.* > <name>` (Windows: `copy /b`) or 7-Zip opening the `.001`
 * gives the file back without the engine. Beside them `<name>-volumes.json`
 * says how many there are and what each weighs; it is written LAST, so a set
 * without it never finished. A hyphen, never a dot: `<name>.*` must take the
 * volumes alone — with a dot `cat` appended the list to the archive, gzip
 * called it trailing garbage and an encrypted set would not decrypt. The journal (`backup_runs.volumes`) holds the
 * same list and is what a restore trusts — the set file only says "complete".
 */
class Volumes
{
    /** The file beside the volumes that says how many there are — outside `<name>.*`. */
    public const SET = '-volumes.json';

    /** Only these forms are written as they go; a zip is finished only when closed. */
    public const FORMATS = ['tar', 'tar.gz'];

    /** The smallest volume the plan's form accepts, in MB. */
    public const MIN_MB = 10;

    /** The largest, in MB (100 GB): past it a volume is a whole file again. */
    public const MAX_MB = 102400;

    /** The sizes offered on the plan's form, in MB. */
    public const PRESETS = [512, 1024];

    /** Volume n of the backup at $path: `.001`, `.002`… */
    public static function file(string $path, int $number): string
    {
        return $path.'.'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * What `<name>-volumes.json` holds: the volumes' names, sizes and checksums.
     *
     * @param  list<array{bytes: int, sha256: string}>  $volumes
     */
    public static function set(string $name, string $format, bool $encrypted, int $bytes, ?string $sha256, array $volumes): string
    {
        return (string) json_encode([
            'name' => $name,
            'format' => $format,
            'encrypted' => $encrypted,
            'bytes' => $bytes,
            'sha256' => $sha256,
            'volumes' => array_map(fn (array $v, int $i) => ['file' => basename(self::file($name, $i + 1)), 'bytes' => $v['bytes'], 'sha256' => $v['sha256']], $volumes, array_keys($volumes)),
            'join' => 'cat '.$name.'.* > '.$name,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public static function setOf(BackupRun $run): string
    {
        return self::set((string) $run->name, (string) $run->format, (bool) $run->encrypted, (int) $run->bytes, $run->sha256, (array) $run->volumes);
    }

    /**
     * The volumes a set file lists.
     *
     * @return list<array{bytes: int, sha256: ?string}>
     */
    public static function parse(string $json): array
    {
        $set = json_decode($json, true);

        if (! is_array($set) || ! is_array($set['volumes'] ?? null) || $set['volumes'] === []) {
            throw new RuntimeException('The list of volumes beside the backup cannot be read.');
        }

        return array_values(array_map(fn ($v) => ['bytes' => (int) ($v['bytes'] ?? 0), 'sha256' => is_string($v['sha256'] ?? null) ? $v['sha256'] : null], $set['volumes']));
    }

    /**
     * A backup cut into volumes on this server's disk, named by any of its
     * files — the `-volumes.json`, a `.001`, or the name they share — for the
     * console and the installer on a new server. Null when it is not one.
     *
     * @return array{base: string, files: list<string>, hashes: list<?string>}|null
     */
    public static function onDisk(string $file): ?array
    {
        $base = preg_replace('/('.preg_quote(self::SET, '/').'|\.\d{3,})$/', '', $file);

        if (! is_file(self::file($base, 1))) {
            return null;
        }

        $hashes = [];
        if (is_file($base.self::SET)) {
            $listed = self::parse((string) file_get_contents($base.self::SET));
            $hashes = array_column($listed, 'sha256');
            $count = count($listed);
        } else {
            for ($count = 0; is_file(self::file($base, $count + 1)); $count++) {
            }
        }

        $files = [];
        for ($n = 1; $n <= $count; $n++) {
            if (! is_file(self::file($base, $n))) {
                throw new RuntimeException('Volume '.$n.' of '.$count.' is missing: '.self::file($base, $n));
            }
            $files[] = self::file($base, $n);
        }

        return ['base' => $base, 'files' => $files, 'hashes' => $hashes];
    }
}
