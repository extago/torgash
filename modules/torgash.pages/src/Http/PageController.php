<?php

namespace Torgash\Pages\Http;

use App\Core\Seo\AgentTraffic;
use App\Core\Seo\RedirectManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Torgash\Pages\Models\Page;
use Torgash\Pages\Seo\PageText;

class PageController extends Controller
{
    public function __construct(protected RedirectManager $redirects) {}

    /**
     * A page at its own address in the root — reached through a FALLBACK
     * route, which is what makes that safe: Laravel tries every other route
     * first and only then this one, so a page can never take an address away
     * from the catalogue, the cart, a sale or the admin. It gets what would
     * otherwise have been a 404.
     *
     * Three answers, in this order:
     *
     *  - a published page whose display rules pass here → the page;
     *  - an address this shop used to serve → 301 to where it went. Renaming a
     *    section rewrites its whole subtree, and this is where those land;
     *  - anything else → 404, exactly as before this module existed.
     */
    public function show(string $fallback, Request $request)
    {
        $path = trim($fallback, '/');

        // `<address>.md` is the same page as text — the convention the
        // catalogue's product pages follow. It is answered from HERE, inside
        // the fallback, rather than from a route of its own: a route matching
        // every `.md` address would have to be registered before this one and
        // would then swallow another module's text pages whenever the
        // providers happened to boot in the other order.
        $asText = str_ends_with($path, '.md');

        if ($asText) {
            $path = substr($path, 0, -3);
            AgentTraffic::enforce($request);
        }

        $page = Page::published()->where('path', $path)->first();

        if ($page && $page->isVisible()) {
            if ($asText) {
                return response(PageText::render($page), 200)->header('Content-Type', 'text/plain; charset=utf-8');
            }

            $this->describe($page);

            return view('torgash.pages::page', ['page' => $page]);
        }

        if ($target = $this->redirects->resolve('/'.$path)) {
            return redirect($target.($asText ? '.md' : ''), 301);
        }

        abort(404);
    }

    /**
     * What the page says about itself: its own meta fields, and the section it
     * sits in as a trail.
     *
     * The fields are the page's own columns rather than anything this module
     * computes — that is the whole point of their being columns (PLAN §30.3):
     * they outlive any SEO module and are edited where the rest of the page is.
     */
    protected function describe(Page $page): void
    {
        // The page's own title falls back to its heading, so the default goes
        // in first and describe() puts the record's own fields over it.
        page_meta()
            ->set([
                'title' => $page->t('title'),
                'h1' => $page->t('title'),
                'type' => 'article',
                // The page's opening words, unless it has a description of
                // its own (audit 2026-10-15: info pages had none).
                // Read before the placeholders are expanded: a `{callback_link}`
                // is a button on the page, not words of a sentence.
                'description' => Str::limit(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(
                    (string) preg_replace('/\{[^{}\s]+\}/', '', (string) $page->t('body'))
                ), ENT_QUOTES | ENT_HTML5))), 160),
            ])
            ->describe($page);

        foreach ($page->ancestors() as $ancestor) {
            breadcrumbs()->add((string) $ancestor->t('title'), $ancestor->url());
        }

        breadcrumbs()->add((string) $page->t('title'));
    }

    /**
     * The address pages had before they moved to the root. Answered with a
     * 301 rather than by rendering, so search engines and anyone's bookmarks
     * end up on the one canonical address.
     *
     * By slug, which is no longer unique across sections — the first match is
     * the honest best guess for a shape that only ever produced root pages.
     */
    public function legacy(string $slug)
    {
        $page = Page::published()->where('slug', $slug)->orderBy('id')->first();

        abort_if(! $page || ! $page->isVisible(), 404);

        return redirect($page->url(), 301);
    }
}
