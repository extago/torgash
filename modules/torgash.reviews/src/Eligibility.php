<?php

namespace Torgash\Reviews;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Reviews\Models\Review;

/**
 * Who may write a review, and who gets the mark that makes one believable.
 *
 * Three answers, decided with the owner:
 *
 * **Never a guest.** An account is the cheapest honest barrier there is — it
 * costs a real customer one minute and costs a review farm the only thing it
 * has, which is volume — and it is what lets the shop answer "who wrote this"
 * a year later. There is no form for a visitor to abuse, so the anti-spam
 * question is mostly answered before FormGuard is even asked.
 *
 * **Bought is a MARK, not a gate, unless the shop says otherwise.** A shop
 * that only accepts reviews from buyers gets fewer reviews than it has
 * customers: people buy by phone, as a present, or in a shop down the road.
 * The mark is what a reader actually believes, and it takes nobody's voice
 * away. `buyers_only` is there for the shop that wants the stricter rule.
 *
 * **One review per person per product** (per group where variations share
 * their reviews), enforced by a partial unique index as well as here: a second opinion is an edit of the first, not a second row.
 */
class Eligibility
{
    /**
     * Why this person cannot write about this product, or null if they can.
     *
     * A sentence rather than a boolean, because every one of these is shown to
     * somebody who is about to type three paragraphs.
     */
    public function refusal(?User $user, Product $product): ?string
    {
        if (! $user) {
            return __('Please sign in to leave a review.');
        }

        if ($this->hasReviewed($user, $product)) {
            return __('You have already reviewed this product.');
        }

        if (setting('torgash.reviews', 'buyers_only') && ! $this->hasBought($user, $product)) {
            return __('Only customers who bought this product can review it.');
        }

        return null;
    }

    public function may(?User $user, Product $product): bool
    {
        return $this->refusal($user, $product) === null;
    }

    public function hasReviewed(User $user, Product $product): bool
    {
        // Across the group where variations share their reviews: one person's
        // two opinions of the same phone in two colours would be printed one
        // under the other.
        return Review::reviews()
            ->whereIn('product_id', app(Group::class)->ids((int) $product->id))
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Did this account buy this product.
     *
     * Read straight from the orders table rather than through a hook: this
     * module names `torgash.checkout` in its manifest, because "a review from
     * somebody who bought it" is the whole idea and there is no version of it
     * that works without orders.
     *
     * A CANCELLED order still counts. Somebody who paid, received the goods
     * and then returned them has more to say about the product than most, and
     * an order's status is the shop's workflow — a review's honesty must not
     * depend on which of eleven statuses a manager happened to leave it in.
     * The line is the JSONB the order stores its items in, which is where
     * checkout keeps them.
     */
    public function hasBought(User $user, Product $product): bool
    {
        return DB::table('m_checkout_orders')
            ->where('user_id', $user->id)
            ->whereRaw('items @> ?::jsonb', [json_encode([['product_id' => (int) $product->id]])])
            ->exists();
    }
}
