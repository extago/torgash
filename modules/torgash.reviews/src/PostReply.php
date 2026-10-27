<?php

namespace Torgash\Reviews;

use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Torgash\Reviews\Models\Review;

/**
 * The one place a reply is added under a review — `PostMessage`'s shape in the
 * enquiries module, and for the same reason: three doors lead in already (the
 * storefront, the moderation screen, and the API when it arrives), and each of
 * them would otherwise repeat the moderation decision, the staff mark and the
 * notification. One of them would forget.
 *
 * **A review is a conversation, not a verdict posted into a void.** Anybody
 * signed in may answer one — including its own author, who is usually the
 * person with something to add ("update after three months") or the one the
 * shop's answer was addressed to. That is the whole point of the thread: a
 * shop that answers a complaint in public and cannot be answered back is
 * having a monologue.
 *
 * Replies are ONE level deep. A reply to a reply is the same conversation, so
 * it hangs off the review as well — which is what keeps "show me this review
 * with everything said about it" a single ordered query rather than a tree
 * walk, and keeps the page readable.
 */
class PostReply
{
    /** Counted apart from the review form: answering is cheap and writing a review is not. */
    public const FORM = 'review_reply';

    public function __construct(protected FormGuard $guard) {}

    /**
     * From a visitor, on the product page.
     *
     * @throws ValidationException
     */
    public function fromCustomer(Request $request, Review $review): Review
    {
        $user = $request->user();

        if (! $user) {
            throw ValidationException::withMessages(['reply' => __('Please sign in to reply.')]);
        }

        // The rejected and the unpublished have no public thread: answering
        // something nobody can read is how a page ends up carrying half a
        // conversation.
        if ($review->status !== Review::PUBLISHED) {
            throw ValidationException::withMessages(['reply' => __('This review is not published.')]);
        }

        if ($stop = $this->guard->check($request, \App\Core\Access\AccountContact::label($user), self::FORM)) {
            throw ValidationException::withMessages(['reply' => $stop['message'] ?? __('Please try again in a moment.')]);
        }

        $data = Validator::make($request->all(), ['body' => 'required|string|max:5000'])->validate();

        // "Has at least one role", never "has the admin role" — the project's
        // one answer to staff-vs-customer (CLAUDE.md). A manager who replies
        // from the shop floor is badged as the shop, which is what the reader
        // needs to know.
        $reply = $this->write($review, $user, $data['body'], staff: $user->roles()->exists());

        $this->guard->record($request, \App\Core\Access\AccountContact::label($user), self::FORM);

        return $reply;
    }

    /** From the shop, on the moderation screen. Published at once — the person publishing it IS the moderator. */
    public function fromShop(Review $review, User $staff, string $body): Review
    {
        return $this->write($review, $staff, $body, staff: true, published: true);
    }

    protected function write(Review $review, User $author, string $body, bool $staff, ?bool $published = null): Review
    {
        // Always under the REVIEW, never under another reply: one level, one
        // ordered query, one readable page.
        $root = $review->parent_id ?: $review->id;

        $published ??= ! setting('torgash.reviews', 'premoderate', true);

        $reply = Review::create([
            'product_id' => $review->product_id,
            'parent_id' => $root,
            'user_id' => $author->id,
            'author_name' => $author->name ?: ($staff ? __('Shop') : __('Customer')),
            'body' => trim($body),
            'status' => $published ? Review::PUBLISHED : Review::PENDING,
            'is_staff' => $staff,
            'storefront_id' => $review->storefront_id,
            'locale' => app()->getLocale(),
        ]);

        // Told, then announced, and only for a reply somebody can actually
        // read: mailing "you have an answer" about a row still in the queue
        // sends a person to a page that does not show it.
        if ($reply->status === Review::PUBLISHED) {
            Notify::replied($reply, $review->parent_id ? $review->parent : $review);
        }

        Notify::about($reply);

        hook_action('reviews.reply.created', ['reply' => $reply, 'review' => $review]);

        return $reply;
    }
}
