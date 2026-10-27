<?php

namespace Torgash\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Images that live INSIDE a text, as opposed to photographs OF a thing.
 *
 * A product's gallery is the product: ordered, captioned, shown in the slider,
 * thumbnailed to four sizes. An image in its description is a different animal
 * — a diagram, a screenshot, a size chart — and the differences are not
 * cosmetic:
 *
 * - **It is not a preview, so it gets no conversions.** Four thumbnails of a
 *   size chart is four files nothing will ever request. `Gallery::conversions()`
 *   skips this collection outright, which is why the rule lives in one named
 *   place instead of in each model's `registerMediaConversions()`.
 * - **It has no order and no alt of its own.** Where it appears and what it
 *   says are properties of the HTML, not of the file: the same diagram can be
 *   used twice in one text with two different captions. So this collection
 *   stores the FILE and nothing else, and the `<img>` tag carries alt, size and
 *   position.
 * - **It is owned by the record all the same.** Delete the product and the
 *   images in its description go with it — which is the whole reason they are a
 *   media collection on the product rather than a shop-wide file manager. A
 *   shop-wide library is a different feature with a different problem (nobody
 *   ever knows what is safe to delete), and this is deliberately not it.
 *
 * Everything else — the size limit, the maximum dimension, recompression, the
 * mime sniffing that makes `shell.php` land on disk as `.jpg` — is
 * `ImageProcessor`'s, unchanged, because there is one place images are written.
 */
class Content
{
    public const COLLECTION = 'content';

    /**
     * What the editor needs to draw its image panel for this record, or null
     * when there is nothing to draw it for: no record (an unsaved product has
     * no id to hang a file on), a model nobody registered, or a person without
     * the ability to edit it.
     *
     * The list of images is NOT in here — the panel fetches it from `endpoint`
     * the first time it is opened. A product form with three rich fields would
     * otherwise pay for three copies of the same list on every render, and most
     * of the time nobody opens the panel at all.
     */
    public static function context(?Model $record): ?array
    {
        if (! $record instanceof Model || ! $record->exists || ! $record instanceof HasMedia) {
            return null;
        }

        $owners = app(MediaOwners::class);
        $key = $owners->contentKeyFor($record);

        if ($key === null || ! Gate::allows($owners->definition($key)['ability'])) {
            return null;
        }

        return [
            'endpoint' => url("/admin/media/{$key}/{$record->getKey()}/content"),
            'allow_remote' => ImageSettings::allowRemote(),
        ];
    }

    /**
     * One image, as the panel sees it: the file, what it weighs, how big it is.
     *
     * The file NAME is the caption — there is nothing else honest to show. A
     * caption stored on the file would be a second answer to a question the
     * `<img alt>` already answers, and the two would disagree the first time
     * somebody edited one of them.
     *
     * @return array<string, mixed>
     */
    public static function describe(Media $media): array
    {
        return [
            'id' => $media->id,
            'url' => $media->getUrl(),
            'name' => $media->file_name,
            'size' => $media->size,
        ] + Gallery::dimensions($media);
    }
}
