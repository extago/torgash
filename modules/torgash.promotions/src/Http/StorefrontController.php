<?php

namespace Torgash\Promotions\Http;

use App\Core\Content\Markdown;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Torgash\Catalog\Listing\Listing;
use Torgash\Promotions\Models\Promotion;

/**
 * The sale's page, and the list of them.
 *
 * Reachability and applicability are two different questions here, and both
 * are answered with rules that already exist:
 *
 *   - `published` + a slug says there IS a page. It stays reachable after the
 *     sale ends — a link in last week's newsletter must land on "this ended"
 *     rather than a 404, the same rule catalog draws with reachable() vs
 *     listed();
 *   - `appliesIn()` says the sale belongs on THIS storefront, in this
 *     language, to this visitor. A sale scoped to the wholesale storefront is
 *     not merely unlisted on the retail one — its page 404s there, because a
 *     page describing a discount this visitor cannot get is worse than no
 *     page.
 */
class StorefrontController extends Controller
{
    /** Every sale a visitor can currently read about, running ones first. */
    public function index()
    {
        $promotions = Promotion::published()
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->with('tiers')
            ->get()
            ->filter(fn (Promotion $promotion) => $promotion->appliesIn())
            // Running first, then the ones that have not opened yet, then
            // what is over: the order a visitor reads them in is the order of
            // how much they can act on them.
            ->sortBy(fn (Promotion $promotion) => match ($promotion->status()) {
                'running' => 0, 'paused' => 1, 'scheduled' => 2, default => 3,
            })
            ->values();

        page_meta()->set(['title' => __('Promotions'),
            'description' => __('Current promotions and discounts at :shop.', ['shop' => current_storefront()?->name])]);
        breadcrumbs()->add(__('Promotions'));

        return view('torgash.promotions::storefront.index', ['promotions' => $promotions]);
    }

    public function show(string $slug, Request $request)
    {
        $promotion = Promotion::published()->where('slug', $slug)->with('tiers')->first();

        abort_if(! $promotion || ! $promotion->appliesIn(), 404);

        // Through the page's one head, like every other page — the view used
        // to print its own description tag, and none at all when the field
        // was empty.
        page_meta()->set([
            'title' => $promotion->pageTitle(),
            'description' => strip_tags((string) $promotion->t('meta_description'))
                ?: Str::limit(Markdown::fromHtml((string) $promotion->t('description')), 160),
        ]);

        breadcrumbs()->add(__('Promotions'), route('promotions.index'))->add($promotion->t('name'));

        $listing = null;

        if ($promotion->zone === Promotion::ZONE_CATALOG) {
            // The catalogue's own listing over the sale's products — its
            // panel offers only what can narrow THIS set, its sorts and its
            // chips — rather than a bare grid of everything mixed together.
            $scope = $promotion->productQuery($request->user());

            if ($request->hasHeader('X-Catalog-Count')) {
                return app(Listing::class)->count($scope, $request);
            }

            $listing = app(Listing::class)->page($scope, $request);
        }

        return view('torgash.promotions::storefront.show', [
            'promotion' => $promotion,
            'listing' => $listing,
        ]);
    }
}
