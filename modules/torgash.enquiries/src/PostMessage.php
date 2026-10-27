<?php

namespace Torgash\Enquiries;

use App\Models\User;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Models\Message;

/**
 * The one place a message is added to an enquiry — the same rule as Submit for
 * the enquiry itself, and PlaceOrder for an order.
 *
 * Three callers are coming (the admin screen, the customer's account, the API's
 * `reply` action for a shop that answers from its CRM), and each of them would
 * otherwise have to remember the same four things: stamp `answered_at`, move a
 * new enquiry into "in progress", give it an owner, announce the hook. One of
 * them would forget, and the inbox would quietly start lying about how long
 * people wait for an answer.
 */
class PostMessage
{
    /**
     * The shop answering.
     *
     * Three side effects, each of which is what a manager MEANS by replying:
     *
     * - `answered_at` is stamped, once. Until now it was a guess made from the
     *   status ("it left the open states, so presumably somebody replied");
     *   a reply is the event itself. Never moved afterwards — it answers "how
     *   long did this person wait", and a status corrected twice must not keep
     *   resetting that;
     * - a NEW enquiry becomes "in progress". Leaving it new after somebody has
     *   written into it means the badge keeps counting it as untouched, which
     *   is how a thread that is half-handled goes round the office twice;
     * - an ownerless enquiry gets the person who answered. Whoever replied is
     *   dealing with it; making them also pick themselves from a select is a
     *   step that gets skipped, and then nobody owns anything.
     *
     * A finished enquiry is NOT reopened by the shop writing into it: adding
     * "anything else we can do" is not a reason to put the row back in the
     * queue.
     */
    public function fromStaff(Enquiry $enquiry, User $staff, string $body, mixed $files = null): Message
    {
        $message = $this->write($enquiry, $body, true, $staff, $staff->name, $files);

        if (! $enquiry->answered_at) {
            $enquiry->answered_at = now();
        }

        if ($enquiry->status === Status::NEW) {
            $enquiry->status = Status::IN_PROGRESS;
        }

        $enquiry->manager_id ??= $staff->id;

        $enquiry->save();

        return $this->finish($message, $enquiry);
    }

    /**
     * The person who asked, writing again.
     *
     * An answered enquiry goes BACK into the queue, because a reply to an
     * answer is a question nobody has read yet — a thread that stays "done"
     * with an unread message in it is the same failure as an enquiry that
     * arrives in an inbox nobody opens.
     *
     * Only an answered one can: a CLOSED thread (locked, or rejected) never
     * gets this far, because write() refuses it. That is what "not worth
     * answering" and "question resolved" have to mean — a row that can re-enter
     * the inbox by being written into again says neither.
     */
    public function fromCustomer(Enquiry $enquiry, string $body, ?User $user = null, mixed $files = null): Message
    {
        $message = $this->write($enquiry, $body, false, $user, $user?->name ?: $enquiry->name, $files);

        if ($enquiry->status === Status::DONE) {
            $enquiry->status = Status::IN_PROGRESS;
            $enquiry->save();
        }

        return $this->finish($message, $enquiry);
    }

    /**
     * The SHOP writing, with no person behind it — today, "the thing you asked
     * to be told about is back in stock".
     *
     * It is a staff message, because that is what it is to the reader and it is
     * what sends them the email. What it deliberately does NOT do is the two
     * things a manager's reply does: it claims no `manager_id` and stamps no
     * `answered_at`. Nobody has dealt with this thread, and a robot posting
     * into it must not make the inbox report that somebody answered in four
     * seconds — that number is how the shop learns how long people really wait.
     * Whether this counts as the ANSWER is the caller's call: for a restock it
     * is, but only once the email has actually gone somewhere.
     */
    public function fromShop(Enquiry $enquiry, string $body): Message
    {
        $message = $this->write($enquiry, $body, true, null, self::shopName());

        if ($enquiry->status === Status::NEW) {
            $enquiry->status = Status::IN_PROGRESS;
            $enquiry->save();
        }

        return $this->finish($message, $enquiry);
    }

    /** Whose name is on a message nobody signed. */
    protected static function shopName(): string
    {
        return trim((string) (current_storefront()?->name ?? '')) ?: config('app.name');
    }

    protected function write(Enquiry $enquiry, string $body, bool $isStaff, ?User $user, ?string $name, mixed $files = null): Message
    {
        // The one rule that has to hold for every door into a thread — the two
        // screens, the API, and whatever answers enquiries next. A closed
        // conversation is closed for the shop too: a manager who wants one more
        // word reopens it, and that is a decision somebody made rather than a
        // side effect of typing into an old box.
        abort_if($enquiry->isLocked(), 403, __('This conversation is closed.'));

        $message = $enquiry->messages()->create([
            'is_staff' => $isStaff,
            'user_id' => $user?->id,
            'author_name' => trim((string) $name) ?: null,
            'body' => trim($body),
        ]);

        // Files go through the one place that writes them, and they go through
        // it HERE rather than in any caller: three doors already lead into a
        // thread and a fourth is coming, and whichever one forgot would drop a
        // photograph without saying so.
        //
        // A refused file never costs the message. What somebody typed is the
        // valuable half, and handing three paragraphs back as an error over one
        // oversized photo is how a person gives up and rings instead.
        if ($refused = app(Attachments::class)->store($message, $files)) {
            session()->flash('warning', implode(' ', array_unique($refused)));
        }

        return $message;
    }

    /**
     * Told, then announced — and both only once the enquiry itself has been
     * saved: a listener that reads the status must see the state the reply LEFT
     * it in, not the half of it that was true a line earlier.
     *
     * The notification is sent by the module rather than left to a rule, for
     * the reason Submit gives about its own: this is the module's promise that
     * neither side is talking into a void. The hook is announced whether or not
     * an address was configured, so everything beyond the one email is still a
     * rule. Order copied from Submit — mail queued first, so a rule that edits
     * the enquiry cannot race the mail that quotes it.
     *
     * The enquiry travels with the message because every listener needs both.
     */
    protected function finish(Message $message, Enquiry $enquiry): Message
    {
        Notify::about($message, $enquiry);

        hook_action('enquiries.message.created', [
            'message' => $message,
            'enquiry' => $enquiry,
        ]);

        // The customer writing again is its own event, because it is what a
        // rule wants ("they are back — tell the manager"), and "a message was
        // written" is also every reply the shop sends.
        if (! $message->is_staff) {
            hook_action('enquiries.enquiry.customer_replied', [
                'message' => $message,
                'enquiry' => $enquiry,
            ]);
        }

        return $message;
    }
}
