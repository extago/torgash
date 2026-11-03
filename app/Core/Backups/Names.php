<?php

namespace App\Core\Backups;

use App\Models\BackupPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A backup's name and folder from the shop's templates (PLAN §67.2).
 *
 * A name must hold `{date}` and `{time}` (to the second), so that two
 * backups never share one and the newest is the last by name. What the engine
 * deletes it knows from the journal, never by guessing from a name — a file of
 * someone else's in the same FTP folder is never touched.
 */
class Names
{
    public const TOKENS = ['shop', 'plan', 'date', 'time', 'Y', 'm', 'd', 'H', 'i', 's'];

    public static function shop(): string
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return Str::slug(str_replace('.', '-', $host ?: (string) config('app.name', 'torgash'))) ?: 'torgash';
    }

    /** The file name, extension included. */
    public static function file(string $template, ?BackupPlan $plan, Carbon $at, string $extension): string
    {
        $name = self::segment(self::fill($template, $plan, $at));

        // A folder ("without an archive") has no extension.
        return $extension === '' ? $name : $name.'.'.$extension;
    }

    /** The folder inside a destination, '' for its root. */
    public static function folder(string $template, ?BackupPlan $plan, Carbon $at): string
    {
        return collect(explode('/', str_replace('\\', '/', self::fill($template, $plan, $at))))
            ->map(fn (string $part) => self::segment($part))
            ->filter(fn (string $part) => $part !== '' && trim($part, '.') !== '')
            ->implode('/');
    }

    /** What is wrong with a name template (an English sentence for __()); null when nothing. */
    public static function nameError(string $template): ?string
    {
        if (! str_contains($template, '{date}') || ! str_contains($template, '{time}')) {
            return 'The name must contain {date} and {time}: two backups never get one name.';
        }

        if (str_contains($template, '/') || str_contains($template, '\\')) {
            return 'The name cannot contain a folder: folders are the storage\'s.';
        }

        return self::unknownToken($template);
    }

    public static function folderError(string $template): ?string
    {
        return self::unknownToken($template);
    }

    /** An example for the screen. */
    public static function example(string $template, ?BackupPlan $plan = null, string $extension = 'zip'): string
    {
        return self::file($template, $plan ?? new BackupPlan(['code' => 'main']), now(), $extension);
    }

    protected static function fill(string $template, ?BackupPlan $plan, Carbon $at): string
    {
        return strtr($template, [
            '{shop}' => self::shop(),
            '{plan}' => (string) ($plan?->code ?: 'backup'),
            '{date}' => $at->format('Y-m-d'),
            '{time}' => $at->format('His'),
            '{Y}' => $at->format('Y'), '{m}' => $at->format('m'), '{d}' => $at->format('d'),
            '{H}' => $at->format('H'), '{i}' => $at->format('i'), '{s}' => $at->format('s'),
        ]);
    }

    /** Only what every FTP server, bucket and Windows folder accepts. */
    protected static function segment(string $part): string
    {
        return trim((string) preg_replace(['/[^A-Za-z0-9._-]+/', '/-{2,}/'], ['-', '-'], $part), '-');
    }

    protected static function unknownToken(string $template): ?string
    {
        preg_match_all('/\{([^}]*)\}/', $template, $m);

        foreach ($m[1] as $token) {
            if (! in_array($token, self::TOKENS, true)) {
                return 'Unknown placeholder in the template.';
            }
        }

        return null;
    }
}
