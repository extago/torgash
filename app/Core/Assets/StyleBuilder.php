<?php

namespace App\Core\Assets;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Builds public/assets/css/app.css from resources/css/app.css on the shop's
 * own server (PLAN §45.2).
 *
 * The stylesheet holds only the classes some template uses — core's, every
 * theme's and every module's (the `@source` lines) — so a module put on the
 * server, or a theme template overridden there, with a class nobody else has
 * is drawn WITHOUT it, silently, until the file is built again. That used to
 * need a developer's machine.
 *
 * The new file is written beside the old one and moved over it: a page never
 * loads half a stylesheet, and a build that fails leaves the last good one
 * where it was. The move also changes the file's version (AssetVersions), so
 * browsers fetch it at once.
 *
 * The builder is a single binary, pinned by release and sha256
 * (`torgash.styles`). One downloaded here is checked against that sum before
 * it is ever made executable, and deleted when it does not match.
 */
class StyleBuilder
{
    public const SOURCE = 'resources/css/app.css';

    public const TARGET = 'assets/css/app.css';

    /** Seconds a build may take; it takes about three. */
    public const TIMEOUT = 180;

    /** Core setting holding the last build: when, how big, or why it failed. */
    public const SETTING = 'style_build';

    /** This server's file name in the release: `linux-x64`, `windows-x64.exe`, … */
    public function platform(): string
    {
        $machine = strtolower(php_uname('m'));
        $arch = in_array($machine, ['aarch64', 'arm64'], true) ? 'arm64' : 'x64';

        return match (PHP_OS_FAMILY) {
            'Windows' => 'windows-x64.exe',
            'Darwin' => 'macos-'.$arch,
            // Alpine and other musl systems cannot run the glibc build.
            default => 'linux-'.$arch.(glob('/lib/ld-musl-*') ? '-musl' : ''),
        };
    }

    /** Where a builder may already be, in the order they are tried. */
    public function candidates(): array
    {
        $name = 'tailwindcss-extra'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');

        return array_values(array_filter([
            (string) config('torgash.styles.builder'),
            base_path('tools/'.$name),
            $this->downloadPath(),
        ]));
    }

    public function binary(): ?string
    {
        foreach ($this->candidates() as $path) {
            if (is_file($path) && (PHP_OS_FAMILY === 'Windows' || is_executable($path))) {
                return $path;
            }
        }

        return null;
    }

    public function downloadPath(): string
    {
        return storage_path('app/tools/tailwindcss-extra-'.config('torgash.styles.release').'-'.$this->platform());
    }

    /**
     * Downloads this platform's builder and checks it against the pinned sum.
     * Only a file that matches is kept and made executable.
     */
    public function fetch(): string
    {
        $platform = $this->platform();
        $expected = config('torgash.styles.sha256')[$platform] ?? null;
        if (! $expected) {
            throw new RuntimeException("No builder is published for this server ($platform).");
        }

        $url = strtr((string) config('torgash.styles.url'), ['{release}' => config('torgash.styles.release'), '{platform}' => $platform]);
        $target = $this->downloadPath();
        @mkdir(dirname($target), 0755, true);
        $partial = $target.'.part';

        $response = Http::timeout(300)->withOptions(['sink' => $partial])->get($url);
        if (! $response->successful()) {
            @unlink($partial);
            throw new RuntimeException("The builder could not be downloaded ($url answered {$response->status()}).");
        }

        if (! hash_equals($expected, (string) hash_file('sha256', $partial))) {
            @unlink($partial);
            throw new RuntimeException('The downloaded builder does not match its checksum and was deleted.');
        }

        rename($partial, $target);
        @chmod($target, 0755);

        return $target;
    }

    /**
     * Builds the stylesheet and puts it in place.
     *
     * @return array{bytes: int, seconds: float, builder: string}
     */
    public function build(): array
    {
        try {
            $built = $this->buildNow();
        } catch (RuntimeException $e) {
            $this->remember(['error' => $e->getMessage()]);

            throw $e;
        }

        $this->remember(['bytes' => $built['bytes'], 'seconds' => $built['seconds']]);

        return $built;
    }

    /** The last build, as remembered: `at`, and `bytes` or `error`. */
    public function last(): ?array
    {
        $last = setting(\App\Core\Settings\CoreSettings::ID, self::SETTING);

        return is_array($last) ? $last : null;
    }

    protected function remember(array $outcome): void
    {
        app(\App\Core\Settings\SettingsManager::class)->set(\App\Core\Settings\CoreSettings::ID, self::SETTING, $outcome + ['at' => now()->toIso8601String()]);
    }

    /** @return array{bytes: int, seconds: float, builder: string} */
    protected function buildNow(): array
    {
        $builder = $this->binary()
            ?? throw new RuntimeException('No style builder on this server. Run: php artisan torgash:styles --fetch');

        $target = public_path(self::TARGET);
        $fresh = $target.'.'.bin2hex(random_bytes(4)).'.tmp';
        $started = microtime(true);

        $result = Process::path(base_path())->timeout(self::TIMEOUT)
            ->run([$builder, '-i', base_path(self::SOURCE), '-o', $fresh, '--minify']);

        // A build that "succeeded" into an empty file would blank the shop.
        if (! $result->successful() || ! is_file($fresh) || filesize($fresh) < 1024) {
            @unlink($fresh);
            $said = trim($result->errorOutput() ?: $result->output());
            throw new RuntimeException('The stylesheet could not be built'.($said !== '' ? ': '.mb_substr($said, 0, 500) : '.'));
        }

        $bytes = (int) filesize($fresh);
        if (! @rename($fresh, $target)) {
            @unlink($fresh);
            throw new RuntimeException('The new stylesheet could not replace the old one: '.$target);
        }

        app(AssetVersions::class)->forget();

        return ['bytes' => $bytes, 'seconds' => round(microtime(true) - $started, 1), 'builder' => $builder];
    }
}
