<?php

namespace Torgash\Options\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Torgash\Options\Models\OptionFile;

/**
 * Handing a customer's own file back — to them, and to the shop.
 *
 * Two ways in, and deliberately no third. **Staff** with `orders.manage` pass
 * on their credentials: the file is part of an order somebody has to make. A
 * **signature** passes anybody, which is how the customer who uploaded it
 * reaches it from their own cart and from their order page — there is no
 * account behind a guest basket, and the cart's token is not something to
 * spend on this. Everyone else gets 404, never 403: a shop must not confirm
 * that a token it was asked about exists.
 *
 * Served as an ATTACHMENT, always, with the stored mime. Nothing here is ever
 * rendered in the page's own origin — that is the second half of why `Files`
 * refuses SVG and HTML outright, and the belt to its braces.
 */
class FileController extends Controller
{
    public function __invoke(Request $request, string $file)
    {
        $row = OptionFile::where('token', $file)->first();

        abort_unless($row && $row->exists(), 404);

        // A signature is about THIS address and nothing else; a signed-in
        // manager needs none. Checked in that order, so a manager following a
        // link out of an old email still gets the file when the signature has
        // been invalidated by an app-key rotation.
        $allowed = $request->user()?->can('orders.manage')
            || $request->hasValidSignature();

        abort_unless($allowed, 404);

        return Storage::disk(OptionFile::DISK)->download(
            $row->path,
            $row->original_name,
            ['Content-Type' => $row->mime],
        );
    }
}
