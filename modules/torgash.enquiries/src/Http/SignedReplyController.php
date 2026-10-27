<?php

namespace Torgash\Enquiries\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\PostMessage;

/**
 * Writing into a thread with no account — the signature IS the authorisation.
 *
 * §29.3/1 said conversations live in the account, and the reason was sound: a
 * guest has no profile, and there was no honest way to show one person another
 * person's thread. A signed URL is that way, and the shop already trusts it for
 * the same class of thing (pay later, and now the order page itself): it
 * survives into tomorrow's email, cannot be derived from an id, and grants
 * exactly one thing.
 *
 * So this route is reached only from a page somebody opened with a key of their
 * own, and it carries a fresh key of its own. The account keeps its own route:
 * a logged-in customer is authorised by being who they are, and should not need
 * a link with a signature in it.
 */
class SignedReplyController extends Controller
{
    public function __invoke(Request $request, Enquiry $enquiry, PostMessage $messages)
    {
        // No 403 for a locked thread here: PostMessage refuses it, and this is
        // the one door where the form is not necessarily drawn by us.
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        // The signature says which thread, not who: a guest signs nothing, and
        // the enquiry's own name is what the message is signed with.
        $messages->fromCustomer($enquiry, $validated['body'], $request->user(), $request->file('attachments'));

        return back()->with('status', __('Sent — we will reply here.'));
    }
}
