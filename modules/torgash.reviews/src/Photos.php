<?php

namespace Torgash\Reviews;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;
use Torgash\Media\ImageProcessor;

/**
 * Photographs sent with a review (PLAN §32.3) — the one place any of them is
 * written, as `Submit` is for the review itself.
 *
 * Nearly all of `Enquiries\Attachments`' reasoning applies unchanged, because
 * both are public uploads: no endpoint of its own (the photograph rides the
 * POST that was already going to happen, so there is no second door to guard
 * and the review form's own rate limit counts it); images only and through
 * `ImageProcessor`, which sniffs the mime from the BYTES so a `shell.php`
 * claiming to be a photo lands as `.jpg`, enforces the size limit and shrinks
 * a twelve-megapixel phone photo; and a refused file never costs the review,
 * because somebody who wrote four paragraphs and attached a 30 MB photo must
 * not be handed their words back as an error.
 *
 * **One thing is the opposite, and it is the disk.** An attachment is a
 * photograph of somebody's broken kettle on their own kitchen table and goes
 * to a private disk served by a controller that asks who is asking. A review
 * photograph is the point: it is published under the review, on the product
 * page, to everyone. So it goes to the `public` disk like a product's own
 * gallery — and it is the REVIEW's moderation that governs it, since a photo
 * is only ever drawn with the review it belongs to. A pending review shows
 * nobody its pictures without a single line of code saying so.
 *
 * It is also not a `MediaOwners` registration, deliberately: that registry is
 * for galleries an ADMIN edits through the media screens, and a moderator's
 * answer to an unwanted photograph is to reject the review, not to curate
 * somebody's pictures inside it.
 */
class Photos
{
    public const COLLECTION = 'photos';

    /**
     * @param  list<UploadedFile>|UploadedFile|null  $files
     * @return list<string> what could not be taken, in the writer's language
     */
    public function store(HasMedia $review, mixed $files): array
    {
        $files = array_values(array_filter(
            is_array($files) ? $files : [$files],
            fn ($file) => $file instanceof UploadedFile && $file->isValid(),
        ));

        if ($files === [] || ! self::enabled()) {
            return [];
        }

        $allowed = self::maxFiles();
        $refused = [];

        // Counted against what this review ALREADY has rather than against
        // this request: the same rule attachments follow, and it is what stops
        // a disk filling up one edit at a time.
        $room = max(0, $allowed - $review->getMedia(self::COLLECTION)->count());

        foreach ($files as $i => $file) {
            if ($i >= $room) {
                $refused[] = __('Only :n photos can be attached to a review.', ['n' => $allowed]);
                break;
            }

            try {
                app(ImageProcessor::class)->addUpload($review, $file, self::COLLECTION);
            } catch (RuntimeException $e) {
                // The image module's own words: it knows the size limit and
                // the formats it takes, and repeating them here would be a
                // second copy to keep in step.
                $refused[] = $e->getMessage();
            } catch (Throwable $e) {
                // Deliberately wide. Everything downstream of the mime check —
                // the resize, the thumbnails, the disk — can fail on a file a
                // stranger chose, and a public form that answers 500 has
                // thrown away whatever the person wrote.
                report($e);
                $refused[] = __('One of the photos could not be saved.');
            }
        }

        return $refused;
    }

    public static function enabled(): bool
    {
        return self::maxFiles() > 0;
    }

    public static function maxFiles(): int
    {
        return max(0, (int) setting('torgash.reviews', 'photos_max', 0));
    }

    /**
     * The photographs on a review, oldest first.
     *
     * @return Collection<int, Media>
     */
    public static function of(HasMedia $review): Collection
    {
        return $review->getMedia(self::COLLECTION);
    }
}
