<?php

namespace App\Core\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * A .zip of CODE arriving through the admin — a module (ModuleArchive) or a
 * theme (ThemeArchive) — read and unpacked the careful way, because the
 * archive is somebody else's:
 *
 * - **Names are checked per entry, before anything is written.** An absolute
 *   path, a drive letter, a `..` segment, a NUL, a symlink — any one refuses
 *   the whole archive. Nothing is extracted by ZipArchive::extractTo(), which
 *   trusts the names; each file is written by hand to a path built here.
 * - **One thing per archive**: the marker file (`manifest.json`, `theme.json`)
 *   at the root or in one top-level folder, and nothing outside that folder.
 * - **Bounded**: a file count and an uncompressed total, read from the
 *   archive's own directory before a byte is inflated — a small zip that
 *   unpacks to gigabytes is the oldest trick there is — and counted again as
 *   written, since the sizes in the directory are whatever its author typed.
 *
 * What the subclasses do with the unpacked folder (where it goes, what may
 * be replaced) is theirs; so is who may upload at all (the controllers ask
 * for the built-in Administrator group, since uploading PHP is more than any
 * permission the shop can hand out).
 */
abstract class CodeArchive
{
    /** Files an archive may hold. */
    public const MAX_FILES = 5000;

    /** Bytes it may unpack to. */
    public const MAX_BYTES = 100 * 1024 * 1024;

    /** The file that marks the folder: `manifest.json` for a module, `theme.json` for a theme. */
    abstract protected function marker(): string;

    /**
     * Every entry's name checked, the folder found, the size added up — all
     * from the archive's directory, before anything is inflated.
     *
     * @return array{0: string, 1: list<array{index: int, path: string}>} the folder prefix inside the archive ('' at the root) and the files under it
     */
    protected function inspect(ZipArchive $zip): array
    {
        if ($zip->numFiles > static::MAX_FILES) {
            throw new RuntimeException(__('The archive holds more than :n files.', ['n' => static::MAX_FILES]));
        }

        $names = [];
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));

            $zip->getExternalAttributesIndex($i, $system, $attributes);
            $isLink = $system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000;

            if ($name === '' || $isLink || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('~^[a-zA-Z]:~', $name)
                || in_array('..', explode('/', $name), true)) {
                throw new RuntimeException(__('The archive holds a path that is not allowed: :path', ['path' => Str::limit($name, 80)]));
            }

            $total += (int) ($stat['size'] ?? 0);
            $names[$i] = $name;
        }

        if ($total > static::MAX_BYTES) {
            throw new RuntimeException(__('The archive unpacks to more than :mb MB.', ['mb' => static::MAX_BYTES / 1024 / 1024]));
        }

        $marker = $this->marker();

        // The marker at the root, or in exactly one top-level folder.
        $prefix = in_array($marker, $names, true) ? '' : null;

        if ($prefix === null) {
            $candidates = array_values(array_filter($names, fn ($n) => preg_match('~^[^/]+/'.preg_quote($marker, '~').'$~', $n)));

            if (count($candidates) !== 1) {
                throw new RuntimeException(__('The archive has no :file — at its root or in one folder.', ['file' => $marker]));
            }

            $prefix = substr($candidates[0], 0, -strlen($marker));
        }

        $files = [];

        foreach ($names as $index => $name) {
            if (! str_starts_with($name, $prefix)) {
                throw new RuntimeException(__('The archive holds more than one folder beside :file.', ['file' => $marker]));
            }

            if (! str_ends_with($name, '/')) {
                $files[] = ['index' => $index, 'path' => substr($name, strlen($prefix))];
            }
        }

        return [$prefix, $files];
    }

    /** @param list<array{index: int, path: string}> $files */
    protected function extract(ZipArchive $zip, string $prefix, array $files, string $staging): void
    {
        File::ensureDirectoryExists($staging);

        // Counted as written, not as declared: the sizes in an archive's
        // directory are whatever its author typed there.
        $left = static::MAX_BYTES;

        foreach ($files as $file) {
            $target = $staging.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['path']);
            File::ensureDirectoryExists(dirname($target));

            $in = $zip->getStream($zip->getNameIndex($file['index']));
            $out = fopen($target, 'wb');

            if (! $in || ! $out) {
                throw new RuntimeException(__('Could not unpack :path.', ['path' => $file['path']]));
            }

            $left -= (int) stream_copy_to_stream($in, $out, $left + 1);
            fclose($in);
            fclose($out);

            if ($left < 0) {
                throw new RuntimeException(__('The archive unpacks to more than :mb MB.', ['mb' => static::MAX_BYTES / 1024 / 1024]));
            }
        }
    }
}
