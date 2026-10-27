<?php

namespace Torgash\Enquiries\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\PostMessage;

/**
 * The customer's side of the conversation: their own enquiries, and a way to
 * write into one.
 *
 * Only for somebody with an ACCOUNT, which is the owner's decision (§29.3/1) —
 * a guest has no profile to log into, so a guest enquiry keeps working the way
 * it already does: a form, and an answer by email. That also settles the
 * question this screen would otherwise have to answer badly ("how do we show a
 * stranger somebody else's thread"): there is no signed link to a thread, so
 * there is nothing to guess.
 *
 * The list is filtered by `user_id` and NEVER by email. A guest enquiry left
 * under the same address as an account created afterwards stays a guest
 * enquiry: an email address is not proof of identity, and stitching the two
 * together would hand one person's conversation to whoever registers with
 * their address.
 */
class AccountEnquiriesController extends Controller
{
    public function index()
    {
        return view('torgash.enquiries::account.index', [
            // lastMessage eagerly, because every row asks who spoke last —
            // that is the state the customer is shown and the unread marker.
            'enquiries' => Enquiry::where('user_id', auth()->id())
                ->with('lastMessage')
                ->latest('id')
                ->paginate(20),
        ]);
    }

    public function show(Enquiry $enquiry)
    {
        $this->theirs($enquiry);

        $enquiry->load(['messages', 'lastMessage']);

        // Reading it is what marks it read. A GET that writes, deliberately:
        // the alternative is a "mark as read" button, which is a chore invented
        // to keep a column honest.
        //
        // Down to the BASE query — not the model, and not Eloquent's own
        // update() either, which helpfully stamps `updated_at`. A customer
        // opening their own page is not the shop editing the enquiry: an
        // exporter pulling everything changed since yesterday would re-export
        // it, and a rule watching for edits would fire on a page view.
        Enquiry::whereKey($enquiry->id)->toBase()->update(['customer_seen_at' => now()]);

        return view('torgash.enquiries::account.show', ['enquiry' => $enquiry]);
    }

    /**
     * The customer writing again — which may put a finished enquiry back into
     * the shop's queue; PostMessage owns that rule, not this controller.
     *
     * A CLOSED thread is refused here rather than silently swallowed — the
     * screen draws no form for one, so this answers a POST made by hand, and
     * saying no out loud beats accepting a message nobody will read. PostMessage
     * refuses it too; this is the same answer given one layer earlier, where it
     * can be a page rather than an exception.
     *
     * No FormGuard: it exists for PUBLIC posts that write rows — the honeypot,
     * the form-age stamp and the per-identity limit are all about somebody with
     * no account. This one is behind `auth` and can only ever append to the
     * writer's own thread.
     */
    public function reply(Request $request, Enquiry $enquiry, PostMessage $messages)
    {
        $this->theirs($enquiry);

        abort_if($enquiry->isLocked(), 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $messages->fromCustomer($enquiry, $validated['body'], $request->user(), $request->file('attachments'));

        return back()->with('status', __('Sent — we will reply here.'));
    }

    /**
     * 404, not 403: a thread that is not theirs must not be distinguishable
     * from one that does not exist.
     */
    protected function theirs(Enquiry $enquiry): void
    {
        abort_unless($enquiry->user_id && $enquiry->user_id === auth()->id(), 404);
    }
}
