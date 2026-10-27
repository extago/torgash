<?php

namespace Torgash\Collections\Http;

use App\Core\Content\Markdown;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Torgash\Catalog\Listing\Listing;
use Torgash\Collections\Models\Collection;

/**
 * The list of collections and a collection's page — the catalogue's own
 * listing over its products (Catalog\Listing), panel, sorts and all, under
 * its cover and words. A collection switched off or outside its dates has no
 * page (404); one left out of the list (`listed` off) still has its page —
 * for a banner or a menu to point at.
 */
class StorefrontController extends Controller
{
    public function index()
    {
        $collections = Collection::shown()->where('listed', true)->orderBy('sort')->orderByDesc('id')->get();

        page_meta()->set(['title' => __('Collections'), 'description' => __('Collections of products at :shop.', ['shop' => current_storefront()?->name])]);
        breadcrumbs()->add(__('Collections'));

        return view('torgash.collections::storefront.index', ['collections' => $collections]);
    }

    public function show(string $slug, Request $request, Listing $listing)
    {
        $collection = Collection::shown()->where('slug', $slug)->first();
        abort_unless($collection, 404);

        $scope = $collection->productQuery();

        if ($request->hasHeader('X-Catalog-Count')) {
            return $listing->count($scope, $request);
        }

        page_meta()->set([
            'title' => $collection->t('meta_title') ?: $collection->t('name'),
            'description' => $collection->t('meta_description')
                ?: Str::limit(Markdown::fromHtml((string) $collection->t('description')), 160),
            'image' => $collection->coverUrl(),
        ]);
        breadcrumbs()->add(__('Collections'), route('collections.index'))->add($collection->t('name'));

        return view('torgash.collections::storefront.show', [
            'collection' => $collection,
            'listing' => $listing->page($scope, $request),
        ]);
    }
}
