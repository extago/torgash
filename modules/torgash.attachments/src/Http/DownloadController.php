<?php

namespace Torgash\Attachments\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Torgash\Attachments\Models\Attachment;
use Torgash\Catalog\Models\Product;

/**
 * Handing a document over.
 *
 * The files are on the PRIVATE disk, so this is the only door: a document of
 * a product nobody can reach is nobody's, one for signed-in customers asks a
 * guest to sign in, and the staff who manage the catalogue see everything.
 * The `{name}` in the address is for the reader (and the saved file), never
 * used to find anything.
 */
class DownloadController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment, string $name): Response
    {
        $staff = (bool) $request->user()?->can('catalog.manage');

        abort_unless($attachment->path && $attachment->owner_type === 'product', 404);
        abort_unless($staff || Product::query()->reachable()->whereKey($attachment->owner_id)->exists(), 404);

        if ($attachment->forAccountsOnly() && ! $request->user()) {
            return redirect()->guest(route('account.login'));
        }

        $disk = Storage::disk(Attachment::DISK);

        abort_unless($disk->exists($attachment->path), 404);

        if (! $staff) {
            $attachment->newQuery()->whereKey($attachment->id)->increment('downloads');
        }

        $inline = $attachment->opensInline() && ! $request->boolean('download');

        return $disk->response($attachment->path, $attachment->downloadName(), [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
            // Whatever a file claims to be, nothing it carries runs in the
            // shop's origin. A PDF is the exception in form, not in effect:
            // the browser draws it with its own viewer, a plugin embedded in
            // the page, which `object-src 'none'` or a sandbox turns into a
            // blank page (Chrome) — and that viewer runs no
            // script of the file's in our origin anyway.
            'Content-Security-Policy' => $attachment->extension === 'pdf'
                ? "default-src 'none'; object-src 'self'"
                : "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $attachment->forAccountsOnly() ? 'private, max-age=0' : 'public, max-age=3600',
        ], $inline ? 'inline' : 'attachment');
    }
}
