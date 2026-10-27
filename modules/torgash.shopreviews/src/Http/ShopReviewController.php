<?php

namespace Torgash\ShopReviews\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\ShopReviews\Models\Platform;
use Torgash\ShopReviews\Models\ShopReview;
use Torgash\ShopReviews\Stats;
use Torgash\ShopReviews\Submit;

class ShopReviewController extends Controller
{
    public function index(Request $request, Stats $stats)
    {
        $summary = $stats->summary();
        $shop = current_storefront()?->name;

        page_meta()->set([
            'title' => __('Shop reviews'),
            'description' => $summary['count'] > 0
                ? __(':shop: :rating out of 5 from :count reviews of customers.', ['shop' => $shop, 'rating' => number_format($summary['rating'], 1), 'count' => $summary['count']])
                : __('What customers say about :shop.', ['shop' => $shop]),
        ]);
        breadcrumbs()->add(__('Shop reviews'));

        return view('torgash.shopreviews::storefront.index', [
            'summary' => $summary,
            'reviews' => ShopReview::query()->shown()->with('ratings.criterion')
                ->latest('created_at')->latest('id')
                ->paginate(max(1, min(100, (int) setting('torgash.shopreviews', 'per_page', 20))))
                ->withQueryString(),
            'platforms' => Platform::where('enabled', true)->orderBy('sort')->orderBy('id')->get(),
            'criteria' => Submit::criteria(),
            'refusal' => Submit::refusal($request->user()),
        ]);
    }

    public function store(Request $request, Submit $submit)
    {
        $review = $submit->handle($request);

        return redirect()->route('shopreviews.index')->with('status', $review->status === ShopReview::PUBLISHED
            ? __('Thank you! Your review is on the page.')
            : __('Thank you! Your review will appear once the shop has read it.'));
    }
}
