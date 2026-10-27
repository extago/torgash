<?php

namespace Torgash\Reviews\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Models\Product;
use Torgash\Reviews\Criteria;
use Torgash\Reviews\Listing;
use Torgash\Reviews\Models\Review;
use Torgash\Reviews\PostReply;
use Torgash\Reviews\Submit;
use Torgash\Reviews\Votes;

class ReviewController extends Controller
{
    /**
     * One door in from the storefront; everything it does is `Submit`'s.
     *
     * The product is found by SLUG, which is what the address of the page the
     * form sits on already says — a hidden id field would be a second way of
     * naming the same thing and the two would disagree the day a product is
     * merged into another.
     *
     * A refusal comes back as the ordinary redirect-with-errors rather than a
     * 422, for the reason the guard module learned the hard way: what the
     * customer typed must survive being told no. `withInput()` is what puts
     * three paragraphs back in the box.
     */
    public function store(string $product, Request $request, Submit $submit)
    {
        $found = Product::where('slug', $product)->reachable()->firstOrFail();

        try {
            $review = $submit->handle($request, $found);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with('status', $review->status === $review::PUBLISHED
            ? __('Thank you! Your review is now on the page.')
            : __('Thank you! Your review will appear once it has been checked.'));
    }

    /**
     * Answering a review, from the product page.
     *
     * Anybody signed in may — including the review's own author, who is
     * usually the one the shop's answer was written for. Whether it appears at
     * once follows the same premoderation setting as a review: a thread that
     * skipped the queue would be the way round it.
     */
    public function reply(Review $review, Request $request, PostReply $replies)
    {
        try {
            $reply = $replies->fromCustomer($request, $review);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with('status', $reply->status === Review::PUBLISHED
            ? __('Your answer is on the page.')
            : __('Your answer will appear once it has been checked.'));
    }

    /**
     * "Was this review helpful?"
     *
     * Answers with the ROW, swapped in place, so the counts and which button
     * is lit come from the same render — a page that ticked the button in
     * JavaScript and left the number to the server would show a reader their
     * own vote twice on a retry.
     */
    public function helpful(Review $review, Request $request, Votes $votes)
    {
        $votes->cast($review, $request->user(), $request->boolean('helpful'));

        return view('torgash.reviews::storefront.helpful', [
            'review' => $review->fresh(),
            'mine' => $votes->mine($review, $request->user()),
        ]);
    }

    /**
     * The list on its own, in the order asked for.
     *
     * A GET returning a fragment, reached by an ordinary link: with no
     * JavaScript the link goes to the product page with `?reviews=` on it and
     * the section reads the same parameter, so the control works either way
     * and there is one piece of markup rather than two. Nothing had to be done
     * about the canonical — `canonicalParams` holds `page` alone, so a sort
     * falls out of it by itself.
     */
    public function list(string $product, Request $request, Listing $listing)
    {
        $found = Product::where('slug', $product)->reachable()->firstOrFail();
        $state = Listing::state($request->query());

        return view('torgash.reviews::storefront.list', [
            'product' => $found,
            'state' => $state,
            'order' => $state['order'],
            'reviews' => $listing->forProduct((int) $found->id, $state['order'], $state),
            'criteria' => app(Criteria::class)->forProduct($found),
        ]);
    }
}
