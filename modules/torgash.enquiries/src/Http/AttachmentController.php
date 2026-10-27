<?php

namespace Torgash\Enquiries\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Torgash\Enquiries\Attachments;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Models\Message;

/**
 * Handing back a file somebody attached.
 *
 * It exists because these files are NOT on the public disk. A product photo is
 * meant to be seen by anybody and is served straight off `/storage/...`; a
 * photograph of somebody's broken kettle on their own kitchen table was sent to
 * the shop and to nobody else, and that disk is addressed by sequential id.
 *
 * Who may see one is the same question as who may read the thread, asked once:
 * the staff who work the inbox, the account that owns it, or somebody holding a
 * signature for this exact file — which is how a guest reading their order by
 * its key sees the photo they themselves sent. Anything else is 404 rather than
 * 403, for the reason the account screen already gives: somebody else's should
 * not read differently from nothing at all.
 */
class AttachmentController extends Controller
{
    public function __invoke(Request $request, Media $media): StreamedResponse
    {
        $enquiry = $this->enquiryFor($media);

        abort_unless($enquiry && $this->mayRead($request, $enquiry, $media), 404);

        // A thumbnail where one was asked for and has actually been made —
        // the admin's list of four photos must not send four originals down
        // the wire. Streamed through the disk, never a URL: nothing here is
        // web-reachable, and the disk may be a private bucket.
        $conversion = (string) $request->query('size', '');
        $conversion = $conversion !== '' && $media->hasGeneratedConversion($conversion) ? $conversion : '';
        $disk = Storage::disk($conversion === '' ? $media->disk : ($media->conversions_disk ?: $media->disk));
        $path = $media->getPathRelativeToRoot($conversion);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $media->file_name, [
            'Content-Type' => $conversion === '' ? $media->mime_type : ($disk->mimeType($path) ?: $media->mime_type),
            // Never inline HTML or SVG whatever the file claims to be: this
            // route hands back something a stranger uploaded, and the browser
            // must not be invited to run it in the shop's own origin.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The thread this file hangs on, whichever half of it holds the file. */
    protected function enquiryFor(Media $media): ?Enquiry
    {
        if ($media->collection_name !== Attachments::COLLECTION) {
            return null;
        }

        return match ($media->model_type) {
            (new Enquiry)->getMorphClass() => Enquiry::find($media->model_id),
            (new Message)->getMorphClass() => Message::find($media->model_id)?->enquiry,
            default => null,
        };
    }

    protected function mayRead(Request $request, Enquiry $enquiry, Media $media): bool
    {
        if ($request->user()?->can('enquiries.manage')) {
            return true;
        }

        if ($request->user() && $enquiry->user_id === $request->user()->id) {
            return true;
        }

        // Signed for this one address, exactly as the reply route is: the
        // signature says WHICH file, not who is asking. Registered outside
        // StorefrontRoutes for the same reason — a signature is computed over
        // one exact URL, and a per-locale copy of it would only be a second URL
        // that fails (see docs/LESSONS.md).
        return $request->hasValidSignature();
    }
}
