<?php

namespace Torgash\Files;

use App\Core\Files\FileStorage;
use App\Core\Support\SafeSvg;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Throwable;
use Torgash\Files\Models\Folder;
use Torgash\Files\Models\StoredFile;

/**
 * The one place a file enters the storage.
 *
 * The same rules as the documentation module's (`Torgash\Attachments\Documents`),
 * on a PUBLIC disk this time — which is why they matter more: the type comes
 * from what the BYTES are and must be on the shop's list; `NEVER` refuses
 * programs and anything a browser would run in the shop's own origin, whatever
 * the list says; an SVG is rebuilt from what draws (`SafeSvg`), never stored
 * as sent; and the stored name is ours, never the uploader's.
 */
class Library
{
    public const NEVER = [
        'php', 'phar', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht',
        'htaccess', 'html', 'htm', 'xhtml', 'shtml', 'svgz', 'xml', 'js', 'mjs',
        'exe', 'msi', 'dll', 'com', 'scr', 'sh', 'bat', 'cmd', 'ps1', 'vbs', 'jar',
    ];

    /** Longer side of the grid's thumbnail. */
    public const THUMB = 360;

    /** @return list<string> */
    public function types(): array
    {
        $listed = preg_split('/[\s,;]+/', strtolower((string) setting('torgash.files', 'types')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_diff(array_unique(array_map(fn ($t) => ltrim($t, '.'), $listed)), self::NEVER));
    }

    public function maxBytes(): int
    {
        return max(1, (int) setting('torgash.files', 'max_mb', 20)) * 1048576;
    }

    public function upload(UploadedFile $file, ?Folder $folder, ?int $userId = null): StoredFile
    {
        $name = Str::limit(basename(str_replace('\\', '/', (string) $file->getClientOriginalName())), 200, '') ?: 'file';

        if (! $file->isValid()) {
            throw new RuntimeException(__(':name: the file did not arrive. It may be bigger than the server accepts.', ['name' => $name]));
        }

        if ($file->getSize() > $this->maxBytes()) {
            throw new RuntimeException(__(':name: the shop takes files up to :max MB.', ['name' => $name, 'max' => (int) ($this->maxBytes() / 1048576)]));
        }

        $extension = $this->extension($file);

        if ($extension === null) {
            throw new RuntimeException(__(':name: the shop takes :types.', ['name' => $name, 'types' => strtoupper(implode(', ', $this->types()))]));
        }

        $storage = app(FileStorage::class);
        $source = (string) $file->getRealPath();
        $scratch = [];

        try {
            if ($extension === 'svg') {
                $svg = SafeSvg::clean((string) file_get_contents($source));

                if ($svg === null) {
                    throw new RuntimeException(__(':name: this SVG could not be read as a drawing.', ['name' => $name]));
                }

                $source = $scratch[] = $storage->tempPath('svg');
                file_put_contents($source, $svg);
            } elseif ($shrunk = $this->shrink($source, $extension)) {
                $source = $scratch[] = $shrunk;
            }

            [$width, $height] = $this->dimensions($source, $extension);
            $path = $this->place($name, $extension);
            $this->write($path, $source);

            $thumb = null;

            if (in_array($extension, StoredFile::RASTER, true) && $extension !== 'gif' && max($width ?? 0, $height ?? 0) > self::THUMB) {
                $thumb = $this->thumbnail($source, $path, $scratch);
            }

            return StoredFile::create([
                'folder_id' => $folder?->id,
                'name' => $name,
                'path' => $path,
                'thumb_path' => $thumb,
                'extension' => $extension,
                'mime' => $extension === 'svg' ? 'image/svg+xml' : (string) ($file->getMimeType() ?: 'application/octet-stream'),
                'size' => (int) (filesize($source) ?: $file->getSize()),
                'width' => $width,
                'height' => $height,
                'uploaded_by' => $userId,
            ]);
        } finally {
            foreach ($scratch as $temp) {
                $storage->release($temp);
            }
        }
    }

    /** Folder names are free text, but never empty and never a path. */
    public function folderName(string $typed): string
    {
        $name = trim(str_replace(['/', '\\'], '-', $typed));

        if ($name === '') {
            throw new RuntimeException(__('A folder needs a name.'));
        }

        return Str::limit($name, 120, '');
    }

    /**
     * Where the bytes go: `files/<name>.<ext>` — the uploaded name
     * transliterated, made unique with -2, -3…, or a short code
     * (the "Names of stored files" setting).
     */
    protected function place(string $name, string $extension): string
    {
        if (setting('torgash.files', 'file_names', 'original') === 'code') {
            do {
                $path = StoredFile::DIRECTORY.'/'.FileStorage::shortName().'.'.$extension;
            } while ($this->taken($path));

            return $path;
        }

        $base = Str::limit(Str::slug(pathinfo($name, PATHINFO_FILENAME)), 80, '') ?: 'file';
        $path = StoredFile::DIRECTORY.'/'.$base.'.'.$extension;

        for ($n = 2; $this->taken($path); $n++) {
            $path = StoredFile::DIRECTORY.'/'.$base.'-'.$n.'.'.$extension;
        }

        return $path;
    }

    protected function taken(string $path): bool
    {
        return StoredFile::query()->where('path', $path)->exists()
            || Storage::disk(StoredFile::DISK)->exists($path);
    }

    protected function write(string $path, string $local): void
    {
        $in = fopen($local, 'rb');

        try {
            if (! Storage::disk(StoredFile::DISK)->writeStream($path, $in)) {
                throw new RuntimeException(__('The file could not be saved.'));
            }
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }
    }

    /**
     * A smaller copy for the grid, as WebP; a picture that will not decode
     * simply has none and the grid shows the file itself.
     *
     * @param  list<string>  $scratch
     */
    protected function thumbnail(string $source, string $path, array &$scratch): ?string
    {
        try {
            $target = $scratch[] = app(FileStorage::class)->tempPath('webp');
            Image::load($source)->fit(Fit::Max, self::THUMB, self::THUMB)->quality(80)->save($target);
            // Not `.thumbs`: a web server refuses a folder whose name starts
            // with a dot (Caddy answered 403). The whole name, extension
            // included, so a.png and a.jpg never share one thumbnail.
            $thumb = StoredFile::DIRECTORY.'/thumbs/'.basename($path).'.webp';
            $this->write($thumb, $target);

            return $thumb;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** The "Shrink photos larger than" setting: a scratch copy brought down to it, or null. */
    protected function shrink(string $source, string $extension): ?string
    {
        $max = (int) setting('torgash.files', 'max_dimension_px', 0);

        if ($max <= 0 || ! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return null;
        }

        [$width, $height] = $this->dimensions($source, $extension);

        if (max($width ?? 0, $height ?? 0) <= $max) {
            return null;
        }

        $target = app(FileStorage::class)->tempPath($extension);
        Image::load($source)->fit(Fit::Max, $max, $max)->quality(88)->save($target);

        return $target;
    }

    /** @return array{0: ?int, 1: ?int} */
    protected function dimensions(string $local, string $extension): array
    {
        if (! in_array($extension, StoredFile::RASTER, true)) {
            return [null, null];
        }

        $size = @getimagesize($local);

        return $size ? [(int) $size[0], (int) $size[1]] : [null, null];
    }

    /**
     * The extension we store it under: what the bytes say, if the shop takes
     * it. A name ending in .jpg whose bytes are a script is refused; an SVG is
     * recognised by its name or markup (the sniffer may call it text or XML)
     * and is then rebuilt, so what it claims to be does not matter.
     */
    protected function extension(UploadedFile $file): ?string
    {
        $allowed = $this->types();
        $sniffed = strtolower((string) ($file->guessExtension() ?: ''));
        $named = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());

        if ($named === 'svg' || $sniffed === 'svg' || $mime === 'image/svg+xml') {
            return in_array('svg', $allowed, true) ? 'svg' : null;
        }

        if (in_array($named, self::NEVER, true) || in_array($sniffed, self::NEVER, true)) {
            return null;
        }

        // The sniffer says "jpeg" where the shop's list usually says "jpg".
        if ($sniffed === 'jpeg' && in_array('jpg', $allowed, true)) {
            $sniffed = 'jpg';
        }

        if ($sniffed !== '' && in_array($sniffed, $allowed, true)) {
            return $sniffed;
        }

        // An .ico is "image/vnd.microsoft.icon" or "image/x-icon" by its bytes.
        if ($named === 'ico' && in_array('ico', $allowed, true) && str_contains($mime, 'icon')) {
            return 'ico';
        }

        return null;
    }
}
