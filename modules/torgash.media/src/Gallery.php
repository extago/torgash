<?php

namespace Torgash\Media;

use Illuminate\Database\Eloquent\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The seam other modules use. A model that owns a gallery calls
 * Gallery::conversions($this) from registerMediaConversions() and everything
 * else — sizes, formats, quality — follows the Media module's settings.
 */
class Gallery
{
    /**
     * Registers one conversion per configured thumbnail size. Non-queued on
     * purpose: an admin who uploads a photo expects to see its thumbnail on
     * the very next render, not after a worker picks the job up.
     *
     * `$media` is what media-library is asking about, and passing it is how a
     * model says "only my photographs, please": an image inside a description
     * (`Content::COLLECTION`) is not a preview of anything, so four thumbnails
     * of it are four files nothing will ever request. The exception lives here
     * rather than in each model's `registerMediaConversions()` — one rule, one
     * place, and a model that forgets to pass the argument merely gets the old
     * behaviour instead of a mystery.
     */
    public static function conversions(HasMedia $model, ?Media $media = null): void
    {
        if ($media && $media->collection_name === Content::COLLECTION) {
            return;
        }

        $webp = ImageSettings::thumbWebp();
        $quality = $webp ? ImageSettings::webpQuality() : ImageSettings::thumbQuality();
        $fit = ImageSettings::thumbFit();
        $padColor = ImageSettings::thumbPadColor();

        foreach (ImageSettings::thumbSizes() as $size) {
            $conversion = $model->addMediaConversion($size['name'])
                // The background colour is only read by Fit::Fill (the "pad"
                // mode); the other fits ignore it, so it is always passed.
                ->fit($fit, $size['w'], $size['h'], false, $padColor)
                ->quality($quality)
                ->nonQueued();

            // No optimiser pass on webp: media-library's cwebp step (`-m 6
            // -pass 10 -q 90`) re-encodes the thumbnail it was just given,
            // lossy again, at ITS quality instead of the shop's — measured on
            // the live shop (2026-10-23) it cost ~1 s per photo and made the
            // 800px file 8% BIGGER. A webp written by the encoder is final.
            $webp ? $conversion->format('webp')->nonOptimized() : $conversion->keepOriginalImageFormat();
        }
    }

    /**
     * URL of the smallest generated thumbnail at least $minWidth wide, falling
     * back to the original. Never returns a conversion that isn't on disk yet.
     */
    public static function thumbUrl(Media $media, int $minWidth = 0): string
    {
        $name = self::thumbName($media, $minWidth);

        return $name === null ? $media->getUrl() : $media->getUrl($name);
    }

    /**
     * The same choice, as a NAME — for a caller that cannot use the URL this
     * builds because its files are not on the public disk and are served
     * through a route of its own (an enquiry's attachments). Null means
     * "nothing suitable has been generated; use the original".
     *
     * Split out rather than copied, because "the smallest generated size at
     * least this wide" is one rule and a second implementation of it would
     * quietly stop agreeing when the shop changes its thumbnail sizes.
     */
    public static function thumbName(Media $media, int $minWidth = 0): ?string
    {
        $candidates = collect(ImageSettings::thumbSizes())
            ->filter(fn ($size) => $size['w'] >= $minWidth)
            ->sortBy('w');

        foreach ($candidates as $size) {
            if ($media->hasGeneratedConversion($size['name'])) {
                return $size['name'];
            }
        }

        return null;
    }

    /**
     * Pixel size of the original, as {width, height}.
     *
     * Recorded on upload, but images added before that — or through some other
     * path — have no such properties. Rather than making every caller cope with
     * nulls, read the file once and write the answer back, so the lightbox
     * always gets real dimensions and the disk read happens only the first time.
     *
     * @return array{width: ?int, height: ?int}
     */
    public static function dimensions(Media $media): array
    {
        $width = $media->getCustomProperty('width');
        $height = $media->getCustomProperty('height');

        if ($width && $height) {
            return ['width' => (int) $width, 'height' => (int) $height];
        }

        // Through a local copy: the file may be in a bucket. Once per image —
        // the answer is written back below.
        $files = app(\App\Core\Files\FileStorage::class);
        $path = null;

        try {
            $path = $files->mediaCopy($media);
            $size = @getimagesize($path);
        } catch (\Throwable) {
            $size = false;
        } finally {
            $files->release($path);
        }

        if (! $size) {
            return ['width' => null, 'height' => null];
        }

        $media->setCustomProperty('width', (int) $size[0]);
        $media->setCustomProperty('height', (int) $size[1]);
        $media->save();

        return ['width' => (int) $size[0], 'height' => (int) $size[1]];
    }

    public static function alt(Media $media): string
    {
        return (string) $media->getCustomProperty('alt', '');
    }

    public static function title(Media $media): string
    {
        return (string) $media->getCustomProperty('title', '');
    }

    /**
     * What to actually PRINT as a photograph's alt: the caption somebody typed,
     * or whatever a module can work out from the thing it belongs to.
     *
     * The filter is the whole point. An empty `alt` is the commonest accessibility
     * fault in a shop and the one a busy catalogue never gets round to fixing, so
     * something has to be able to answer for the pictures nobody captioned — and
     * this module must not be the thing that knows how (`torgash.seo` does, from
     * a template the shop wrote once). With no such module the answer is exactly
     * what it was: the stored caption, or ''.
     *
     * `$index` is the photograph's position in its gallery, which is the one
     * fact a template cannot get from the model.
     */
    public static function altFor(Media $media, ?object $model = null, int $index = 0): string
    {
        return (string) hook_filter('media.image.alt', self::alt($media), [
            'media' => $media,
            'model' => $model ?? $media->model,
            'index' => $index,
        ]);
    }

    public static function titleFor(Media $media, ?object $model = null, int $index = 0): string
    {
        return (string) hook_filter('media.image.title', self::title($media), [
            'media' => $media,
            'model' => $model ?? $media->model,
            'index' => $index,
        ]);
    }

    /**
     * Persists the per-image captions and the drag-sorted order submitted with
     * the owning entity's form. Ignores ids that don't belong to $model, so a
     * tampered payload can't retitle someone else's photo.
     *
     * @param  array<int|string, array{alt?: string, title?: string, sort?: int|string}>  $input
     */
    public static function applyFormInput(HasMedia $model, array $input, string $collection = 'gallery'): void
    {
        /** @var Collection<int, Media> $owned */
        $owned = $model->getMedia($collection)->keyBy('id');
        $ordered = [];

        foreach ($input as $id => $fields) {
            $media = $owned->get((int) $id);

            if (! $media) {
                continue;
            }

            $media->setCustomProperty('alt', trim((string) ($fields['alt'] ?? '')));
            $media->setCustomProperty('title', trim((string) ($fields['title'] ?? '')));
            $media->save();

            $ordered[(int) ($fields['sort'] ?? PHP_INT_MAX)][] = $media->id;
        }

        if ($ordered) {
            ksort($ordered);
            Media::setNewOrder(array_merge(...array_values($ordered)));
        }
    }
}
