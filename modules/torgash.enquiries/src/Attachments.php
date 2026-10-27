<?php

namespace Torgash\Enquiries;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;
use Torgash\Media\ImageProcessor;

/**
 * Files sent with an enquiry or with a message — the one place any of them is
 * written, exactly as Submit is for an enquiry and PostMessage for a message.
 *
 * Four decisions, and each of them is about the fact that this is a PUBLIC
 * upload. A form that writes rows is the most reliably abused thing a shop puts
 * online (which is what FormGuard exists for); a form that writes FILES is that
 * plus disk.
 *
 * 1. **No endpoint of its own.** Files ride on the POST that was already going
 *    to happen — the enquiry form, the reply box — so there is no second door
 *    to guard and the form's own rate limit already counts them. An
 *    asynchronous uploader would be a new public route accepting files before
 *    anybody has said anything, which is a hole with a progress bar.
 *
 * 2. **Images only, through `ImageProcessor`.** That is the project's one place
 *    an image is written, and it already sniffs the mime rather than trusting
 *    the name (a `shell.php` claiming to be a photo lands as `.jpg`), enforces
 *    the shop's size limit and shrinks a 12-megapixel phone photo to the
 *    configured maximum. Accepting arbitrary files would mean a SECOND upload
 *    path with its own rules, and the reason attachments exist at all is
 *    "фото брака" — a photograph of the fault. A customer with a PDF is asked
 *    for it by email; that is a worse answer than accepting one and a much
 *    better answer than a general-purpose file drop on a public form.
 *
 * 3. **A private disk.** A photo of somebody's broken kettle on their own
 *    kitchen table is not a catalogue picture. Media library's default disk here
 *    is `public`, served straight off `/storage/...` by sequential id — fine for
 *    a product, wrong for this. Attachments go to `private` and are served by
 *    `AttachmentController`, which asks the same question the thread does.
 *
 * 4. **A refused file does not lose the message.** Somebody who typed three
 *    paragraphs and attached a 30 MB photo must not be handed their text back as
 *    an error; the words are the valuable half. So a file that will not do is
 *    dropped with a `warning` and everything else is saved.
 */
class Attachments
{
    public const COLLECTION = 'attachments';

    /**
     * Not the public one: see the class docblock. `private` is a local folder
     * or a private bucket (config/filesystems.php) — either way nothing on it
     * has an address of its own.
     */
    public const DISK = 'private';

    /**
     * @param  list<UploadedFile>|UploadedFile|null  $files
     * @return list<string> what could not be taken, in the sender's language
     */
    public function store(HasMedia $owner, mixed $files): array
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

        // Counted against what this owner ALREADY has, not against this
        // request: a thread where every reply carries five photos is a disk
        // filling up one polite message at a time.
        $room = max(0, $allowed - $owner->getMedia(self::COLLECTION)->count());

        foreach ($files as $i => $file) {
            if ($i >= $room) {
                $refused[] = __('Only :n files can be attached.', ['n' => $allowed]);
                break;
            }

            try {
                app(ImageProcessor::class)->addUpload($owner, $file, self::COLLECTION, self::DISK);
            } catch (RuntimeException $e) {
                // The image module's own words — it knows the size limit and
                // the formats it takes, and repeating them here would be a
                // second copy to keep in step.
                $refused[] = $e->getMessage();
            } catch (Throwable $e) {
                // The backstop, and it is deliberately this wide. Everything
                // downstream of the mime check — the resize, the thumbnails, the
                // disk — can fail on a file a stranger chose, and a public form
                // that answers 500 has thrown away whatever the person wrote.
                // The words survive; the photograph is reported lost.
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
        return max(0, (int) setting('torgash.enquiries', 'attachments_max', 0));
    }

    /**
     * The files on a row, oldest first — for the three places that draw them.
     *
     * @return Collection<int, Media>
     */
    public static function of(HasMedia $owner): Collection
    {
        return $owner->getMedia(self::COLLECTION);
    }
}
