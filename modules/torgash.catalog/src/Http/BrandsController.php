<?php

namespace Torgash\Catalog\Http;

use App\Core\Content\Markdown;
use App\Core\Seo\RedirectManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Listing\Listing;

class BrandsController extends Controller
{
    public function __construct(protected RedirectManager $redirects) {}

    public function index()
    {
        $brandFeatureIds = Feature::where('purpose', 'brand')->pluck('id');

        $brands = FeatureValue::whereIn('feature_id', $brandFeatureIds)
            ->whereNotNull('slug')
            ->with('feature')
            ->get()
            ->sortBy(fn (FeatureValue $v) => $v->display());

        page_meta()->set('title', __('Brands'));
        breadcrumbs()->add(__('Brands'));

        return view('torgash.catalog::brands.index', ['brands' => $brands]);
    }

    /**
     * A brand page is the catalogue's own listing over the brand's products
     * (Catalog\Listing, as a search and a sale are): the panel offers what
     * narrows THESE products — a Category block counting where they stand,
     * then that category's characteristics, the price, the yes/no flags — and
     * reads `?sort=` like every listing (PLAN §31.1).
     */
    public function show(string $slug, Request $request, Listing $listing)
    {
        $brand = FeatureValue::where('slug', $slug)->whereHas('feature', fn ($q) => $q->where('purpose', 'brand'))->first();

        if (! $brand) {
            if ($target = $this->redirects->resolve("/brand/{$slug}")) {
                return redirect($target, 301);
            }
            abort(404);
        }

        $scope = $brand->products()->listed();

        if ($request->hasHeader('X-Catalog-Count')) {
            return $listing->count($scope, $request);
        }

        page_meta()
            ->set([
                'title' => $brand->display(),
                'description' => Str::limit(Markdown::fromHtml((string) $brand->t('description')), 160),
                'image' => (string) $brand->logoUrl(),
            ])
            ->describe($brand);

        breadcrumbs()->add(__('Brands'), route('catalog.brands.index'))->add($brand->display());

        $listingPage = $listing->page($scope, $request);

        // Every product here IS this brand: a "Brand" box would offer one
        // value, ticked or not, and change nothing.
        $listingPage['filters']['features'] = $listingPage['filters']['features']
            ->reject(fn (Feature $feature) => $feature->purpose === 'brand')->values();

        return view('torgash.catalog::brands.show', [
            'brand' => $brand,
            'listingPage' => $listingPage,
        ]);
    }
}
