<?php

namespace Torgash\Media;

use App\Core\Files\FileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\Orientation;
use Spatie\Image\Image;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Everything that writes an image file. Uploads and URL imports both land
 * here so the admin's limits (max MB, max px, recompression) are enforced in
 * exactly one place.
 */
class ImageProcessor
{
    public const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    /**
     * @param  string|null  $disk  where the file lands. Null is the media
     *                             library's own default, which in this project is the PUBLIC disk —
     *                             right for a catalogue photo, which is meant to be seen by anybody.
     *                             A collection that is not (an enquiry's attachments: somebody's
     *                             kitchen table, sent to the shop and to nobody else) names a private
     *                             disk here and serves the file through a route of its own.
     */
    public function addUpload(HasMedia $model, UploadedFile $file, string $collection = 'gallery', ?string $disk = null): Media
    {
        $this->assertSize($file->getSize());
        // The SNIFFED mime, never the name the browser sent: this is what makes
        // a `shell.php` claiming to be a photo land on disk as `.jpg`.
        $mime = (string) ($file->getMimeType() ?: '');
        $this->assertImage($mime);

        $path = $this->stageCopy($file->getRealPath(), $mime, $collection);
        $name = $file->getClientOriginalName() ?: basename($path);

        return $this->store($model, $path, $name, $collection, $disk);
    }

    /**
     * Downloads a remote image into the gallery. Only http(s) is accepted and
     * the response is validated as an image before anything is written.
     */
    public function addFromUrl(HasMedia $model, string $url, string $collection = 'gallery'): Media
    {
        if (! ImageSettings::allowRemote()) {
            throw new RuntimeException(__('Adding images by URL is disabled in the Media settings.'));
        }

        if (! preg_match('#^https?://#i', $url)) {
            throw new RuntimeException(__('Only http:// and https:// image links are supported.'));
        }

        // Only the public internet: a pasted link must not make the shop's
        // server knock on 127.0.0.1 or the office network (audit 2026-10-15).
        $response = app(\App\Core\Http\OutboundUrl::class)->get($url, ImageSettings::remoteTimeout(), ['Accept' => 'image/*']);

        if (! $response->successful()) {
            throw new RuntimeException(__('Could not download :url (HTTP :status).', [
                'url' => $url,
                'status' => $response->status(),
            ]));
        }

        $body = $response->body();
        $this->assertSize(strlen($body));

        $temp = $this->tempPath('bin');
        file_put_contents($temp, $body);

        $mime = (string) (mime_content_type($temp) ?: '');

        try {
            $this->assertImage($mime);
            $path = $this->stageCopy($temp, $mime, $collection);
        } finally {
            @unlink($temp);
        }

        $name = $this->remoteFileName($url, self::ALLOWED_MIME[$mime]);

        return $this->store($model, $path, $name, $collection);
    }

    /**
     * A picture another module has already fetched itself — a video's poster
     * from the platform's own thumbnail address. Not `addFromUrl()`: that is
     * a PERSON pasting an address, which the shop may switch off
     * (`allow_remote`); a poster is the engine reading a known platform, and
     * a shop that forbids the first still wants the second. The checks are
     * the same ones every other way in passes: size, sniffed type, limits.
     */
    public function addFile(HasMedia $model, string $path, string $name, string $collection = 'gallery'): Media
    {
        $this->assertSize(@filesize($path));
        $mime = (string) (mime_content_type($path) ?: '');
        $this->assertImage($mime);

        $staged = $this->stageCopy($path, $mime, $collection);

        return $this->store($model, $staged, pathinfo($name, PATHINFO_FILENAME).'.'.self::ALLOWED_MIME[$mime], $collection);
    }

    /**
     * Rotates the stored original and rebuilds every conversion from it.
     *
     * Worked on a LOCAL COPY and written back (App\Core\Files\FileStorage):
     * the original may be in a bucket, where it has no path to open.
     */
    public function rotate(Media $media, int $degrees): void
    {
        // Orientation only knows quarter turns (0, ±90, 180, 270) — the editor
        // never sends anything else.
        $orientation = Orientation::tryFrom($degrees)
            ?? throw new RuntimeException(__('Rotation must be a quarter turn.'));

        $files = app(FileStorage::class);
        $path = $files->mediaCopy($media);

        try {
            $image = Image::load($path)->orientation($orientation);

            if (! ImageSettings::originalUntouched()) {
                $image->quality(ImageSettings::originalQuality());
            }

            $image->save($path);
            $files->putBack($media->disk, $media->getPathRelativeToRoot(), $path);

            $media->size = (int) filesize($path);
            [$width, $height] = $this->dimensions($path);
        } finally {
            $files->release($path);
        }

        $media->setCustomProperty('width', $width);
        $media->setCustomProperty('height', $height);
        $media->save();

        app(FileManipulator::class)->createDerivedFiles($media->fresh());
    }

    /**
     * Copies the source to a scratch file, downscaling/recompressing per
     * settings.
     *
     * The collection is passed in for exactly one decision: an image going
     * into a TEXT may be re-encoded as WebP, if the shop asked for that
     * (`ImageSettings::contentWebp()`). It is the only place a collection
     * changes what is written, and it is opt-in — see that setting for why the
     * answer is not simply "always".
     */
    protected function stageCopy(string $source, string $mime, string $collection = 'gallery'): string
    {
        $extension = self::ALLOWED_MIME[$mime];

        // GIF is left alone whatever the setting says: a re-encode keeps the
        // first frame and silently throws the animation away, and somebody
        // pasting an animated diagram into a description would find that out
        // by looking at the live page.
        if ($collection === Content::COLLECTION
            && ImageSettings::contentWebp()
            && ! in_array($extension, ['webp', 'gif'], true)) {
            $extension = 'webp';
        }

        $target = $this->tempPath($extension);

        $max = ImageSettings::maxDimension();
        [$width, $height] = $this->dimensions($source);

        // The mime said "image" and the bytes disagree — a truncated upload, a
        // half-downloaded file, or something wearing an image's first few bytes
        // and nothing else. Refused HERE, with a sentence, because the
        // alternative is what it used to do: store it happily and then throw
        // out of the conversion, which is a 500 on the form somebody was
        // standing in front of. `getimagesize` is the same call the dimensions
        // above already make, so this costs nothing.
        if ($width === null) {
            throw new RuntimeException(__('That file says it is an image but cannot be read as one.'));
        }

        // `copy()` only when the bytes are staying as they are. A target whose
        // extension no longer matches the source is a conversion, and spatie's
        // Image picks the format from that extension.
        if ($extension !== self::ALLOWED_MIME[$mime]) {
            Image::load($source)
                ->fit(Fit::Max, $max ?? max($width, $height), $max ?? max($width, $height))
                ->quality(ImageSettings::webpQuality())
                ->save($target);
        } elseif ($max === null || max($width, $height) <= $max) {
            copy($source, $target);
        } else {
            Image::load($source)
                ->fit(Fit::Max, $max, $max)
                ->quality(ImageSettings::originalQuality())
                ->save($target);
        }

        // Trimming happens on the STORED ORIGINAL, before media-library derives
        // anything from it, so every conversion inherits the crop and a later
        // "rebuild thumbnails" keeps it. The cost is that it cannot be undone by
        // rebuilding — the setting's help text says so. `original_untouched`
        // means what it says and skips this too.
        if (ImageSettings::trimBorders() && ! ImageSettings::originalUntouched()) {
            app(BorderTrimmer::class)->trim($target, ImageSettings::trimTolerance());
        }

        return $target;
    }

    protected function store(HasMedia $model, string $path, string $name, string $collection, ?string $disk = null): Media
    {
        [$width, $height] = $this->dimensions($path);

        $adding = $model->addMedia($path)
            ->usingFileName(Str::slug(pathinfo($name, PATHINFO_FILENAME)).'.'.pathinfo($path, PATHINFO_EXTENSION))
            ->withCustomProperties(array_filter([
                'width' => $width,
                'height' => $height,
            ], fn ($v) => $v !== null));

        return $disk === null
            ? $adding->toMediaCollection($collection)
            : $adding->toMediaCollection($collection, $disk);
    }

    protected function assertSize(int|false $bytes): void
    {
        $max = ImageSettings::uploadMaxBytes();

        if ($max !== null && $bytes !== false && $bytes > $max) {
            throw new RuntimeException(__('Image is larger than the :mb MB limit set in the Media settings.', [
                'mb' => rtrim(rtrim(number_format($max / 1024 / 1024, 2, '.', ''), '0'), '.'),
            ]));
        }
    }

    protected function assertImage(string $mime): void
    {
        if (! isset(self::ALLOWED_MIME[$mime])) {
            throw new RuntimeException(__('Unsupported image type ":mime". Allowed: JPEG, PNG, GIF, WebP, AVIF.', [
                'mime' => $mime ?: 'unknown',
            ]));
        }
    }

    /** @return array{0: ?int, 1: ?int} */
    protected function dimensions(string $path): array
    {
        $size = @getimagesize($path);

        return $size ? [(int) $size[0], (int) $size[1]] : [null, null];
    }

    protected function remoteFileName(string $url, string $extension): string
    {
        $base = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);

        return ($base !== '' ? $base : 'image').'.'.$extension;
    }

    protected function tempPath(string $extension): string
    {
        $dir = storage_path('app/media-tmp');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir.DIRECTORY_SEPARATOR.Str::uuid()->toString().'.'.$extension;
    }
}
