<?php

namespace Torgash\Catalog\Http;

use App\Core\Seo\Alternates;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Media\Gallery;

/**
 * The list of addresses the shop publishes.
 *
 * Three things it now says that it did not:
 *
 * **The other languages.** A two-language shop publishes every page twice, and
 * a sitemap that names only one of them is telling a search engine that the
 * other is a separate, near-duplicate page. The `alternate` links come from
 * App\Core\Seo\Alternates — the same class the page's own `hreflang` uses, so
 * the two cannot disagree.
 *
 * **The pictures.** A product's photograph is content a search engine indexes
 * on its own, and there is no other way for it to learn which image belongs to
 * which page.
 *
 * **An index when there are too many.** The format allows 50 000 addresses per
 * file; past that a crawler reads the first 50 000 and the rest of the
 * catalogue is invisible. Beyond the limit `/sitemap.xml` becomes an index
 * naming `/sitemap-1.xml`, `/sitemap-2.xml` and so on.
 *
 * The honest limit, stated because somebody will meet it: the list is built in
 * memory before it is cut into pages, so a catalogue of several hundred
 * thousand needs a written file rather than a page that answers. That is a
 * later job, and the index is what makes it possible to do without changing
 * any address.
 */
class SitemapController extends Controller
{
    /** What the format allows in one file. */
    public const PER_FILE = 50000;

    public function index(?int $page = null): Response
    {
        $urls = $this->urls();
        $pages = (int) ceil(count($urls) / self::PER_FILE) ?: 1;

        // One file is the ordinary answer: no shop below fifty thousand
        // addresses should be handed an index it has to follow.
        if ($page === null && $pages > 1) {
            return $this->xml('torgash.catalog::sitemap-index', ['pages' => $pages]);
        }

        $slice = $page === null
            ? $urls
            : array_slice($urls, (max($page, 1) - 1) * self::PER_FILE, self::PER_FILE);

        abort_if($slice === [] && $page !== null, 404);

        return $this->xml('torgash.catalog::sitemap', ['urls' => $slice]);
    }

    /** @return list<array<string, mixed>> */
    protected function urls(): array
    {
        // The home page first: it is the address every other one is reached
        // from, and it was missing (audit 2026-10-15).
        $urls = [
            // Spelled as the page's canonical spells it: with the slash.
            $this->url(rtrim(route('catalog.home'), '/').'/', ['changefreq' => 'daily', 'priority' => '1.0']),
            $this->url(route('catalog.index'), ['changefreq' => 'daily', 'priority' => '0.9']),
        ];

        // The map is a GUEST's, whoever opens it (PLAN §46).
        $guest = \Torgash\Catalog\Support\Assortment::asGuest();

        // Brands that have something to show: a brand page with no products
        // is a thin page a crawler should not be invited to.
        $brandIds = ProductFeature::query()
            ->whereIn('product_id', Product::visible()->openToGuests()->select('id'))
            ->whereIn('feature_value_id', FeatureValue::whereNotNull('slug')->whereIn('feature_id', Feature::where('purpose', 'brand')->select('id'))->select('id'))
            ->distinct()->pluck('feature_value_id');
        if ($brandIds->isNotEmpty()) {
            $urls[] = $this->url(route('catalog.brands.index'), ['changefreq' => 'weekly', 'priority' => '0.6']);
            foreach (FeatureValue::whereIn('id', $brandIds)->orderBy('id')->pluck('slug') as $slug) {
                $urls[] = $this->url(route('catalog.brands.show', $slug), ['changefreq' => 'weekly', 'priority' => '0.6']);
            }
        }

        foreach (Category::where('status', Category::STATUS_ACTIVE)->whereNotIn('id', $guest->closedCategoryIds() ?: [0])->get() as $category) {
            $urls[] = $this->url(route('catalog.category', $category->slug), [
                'changefreq' => 'daily',
                'priority' => '0.8',
            ], $category);
        }

        // The filter combinations the shop has decided are pages. Out of the
        // sitemap when the shop said so, and out of it when the row is marked
        // noindex — inviting a crawler to a page that then tells it to go away
        // is the one combination that is simply a contradiction.
        foreach (FilterPage::enabled()->where('in_sitemap', true)->where('noindex', false)->with('category')->get() as $page) {
            if (! $page->category || $page->category->status !== Category::STATUS_ACTIVE || ! $guest->categoryOpen($page->category)) {
                continue;
            }

            $urls[] = $this->url($page->url(), ['changefreq' => 'weekly', 'priority' => '0.7']);
        }

        // visible(), not listed(): a sitemap has no visitor, so it cannot ask
        // "what may THIS person be shown" — and `with('media')` because every
        // row below asks for its photograph.
        foreach (Product::visible()->openToGuests()->with('media')->get() as $product) {
            $urls[] = $this->url(route('catalog.product', $product->slug), [
                'lastmod' => $product->updated_at->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.6',
                'images' => $this->images($product),
            ], $product);
        }

        // A module with public pages of its own (a sale's page, a landing) has
        // no other way into the sitemap, and copying this controller would be
        // the alternative. Append-only: a listener adds rows, it does not get
        // to remove catalog's.
        $urls = hook_filter('catalog.sitemap.urls', $urls, []);

        // A listener knows its own addresses but not the shape of their
        // translations, so the languages are filled in here for every row that
        // did not state them.
        return array_map(
            fn (array $url) => $url + ['alternates' => Alternates::forUrl($url['loc'])],
            $urls,
        );
    }

    /**
     * One address, with its translations — only the languages the RECORD has
     * words in, the same answer its page gives (PageMeta). A record with no
     * words in the default language is listed at an address that has them.
     *
     * @return array<string, mixed>
     */
    protected function url(string $loc, array $extra = [], ?\Illuminate\Database\Eloquent\Model $record = null): array
    {
        $alternates = Alternates::forUrl($loc);
        $has = $record && method_exists($record, 'pageLanguages') ? $record->pageLanguages() : null;

        if ($has !== null && $has !== [] && $alternates !== []) {
            $alternates = array_intersect_key($alternates, array_flip($has));
            if (! in_array($loc, $alternates, true) && $alternates !== []) {
                $loc = reset($alternates);
            }
            $alternates = count($alternates) > 1 ? $alternates : [];
        }

        return ['loc' => $loc, 'alternates' => $alternates] + $extra;
    }

    /**
     * The product's photographs, at the size a search engine is worth showing —
     * never the archival original, which is measured in megabytes.
     *
     * @return list<array{loc: string, title: string}>
     */
    protected function images(Product $product): array
    {
        $images = [];

        foreach ($product->galleryImages()->values() as $index => $media) {
            $images[] = [
                'loc' => Gallery::thumbUrl($media, 1200),
                'title' => Gallery::altFor($media, $product, $index) ?: (string) $product->t('name'),
            ];
        }

        return $images;
    }

    protected function xml(string $view, array $data): Response
    {
        return response(view($view, $data)->render(), 200)
            ->header('Content-Type', 'application/xml');
    }
}
