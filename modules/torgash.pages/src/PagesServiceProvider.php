<?php

namespace Torgash\Pages;

use App\Core\Storefronts\LocaleResolver;
use App\Core\Storefronts\StorefrontRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Comments\CommentTargets;
use Torgash\Pages\Http\PageController;
use Torgash\Pages\Models\Page;

class PagesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Its part of the demo shop (PLAN §55.3).
        hook_listen('core.demo.parts', fn (array $parts) => $parts + [
            'pages' => ['label' => 'Pages', 'after' => [], 'install' => fn (\App\Core\Demo\DemoPack $pack) => app(Demo\PagesDemo::class)->install($pack)],
        ]);

        // Pictures inside a page's text, when the media module is there to
        // keep them — registered through its hook, so this module never names
        // its classes and works unchanged without it.
        hook_listen('media.owners', function (array $context) {
            $context['owners']->register('page', Models\Page::class, 'pages.manage', content: true);
        });

        // Videos on a page, when torgash.video is on: its list on the form,
        // `{video:ID}` in the text. Only this module's hook names go over.
        hook_listen('video.owners', fn (array $context) => $context['owners']->register('page', Models\Page::class, 'pages.manage', [
            'form' => 'pages.page.form_sections',
            'saved' => 'pages.page.saved',
            'context' => 'page',
        ]));

        // "Where is the delivery page" typed into the shop's search box: pages
        // whose title holds every word, offered in the live window. Answered
        // for any search module that asks — this module names none.
        hook_listen('search.suggest.sections', function (array $sections, array $context) {
            $words = array_values(array_filter((array) ($context['words'] ?? []), fn ($w) => mb_strlen($w) >= 2));

            if ($words === []) {
                return $sections;
            }

            $query = Models\Page::published();

            foreach ($words as $word) {
                $query->whereRaw(
                    'exists (select 1 from jsonb_each_text(title) t(k, v) where lower(t.v) like ?)',
                    ['%'.addcslashes(mb_strtolower($word), '\\%_').'%'],
                );
            }

            $items = $query->orderBy('id')->limit(5)->get()
                ->filter(fn (Models\Page $page) => $page->isVisible())
                ->map(fn (Models\Page $page) => ['label' => $page->t('title'), 'url' => $page->url()])
                ->values()->all();

            return $items === [] ? $sections : [...$sections, ['title' => __('Pages'), 'items' => $items]];
        });

        $this->displayRule();

        Route::middleware(['web', 'auth', 'can:pages.manage'])
            ->prefix('admin/pages')
            ->name('admin.pages.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // Once per locale shape, never from the current request. This file
        // carries the LEGACY /page/{slug} redirect; the real address is the
        // fallback registered below.
        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        $this->rootAddresses();

        // Every published page is something a menu item can point at
        // (torgash.menus, PLAN §39) — by id, so moving or renaming the page
        // moves the link with it. Asked only when a menu is being drawn.
        hook_listen('menus.pages', function (array $pages) {
            foreach (Models\Page::published()->orderBy('path')->get() as $page) {
                $pages['page:'.$page->id] = [
                    'label' => $page->title,
                    'url' => fn () => $page->isVisible() ? $page->url() : null,
                    'group' => 'Pages',
                    'icon' => null,
                ];
            }

            return $pages;
        });

        // A page can be commented on — answered on torgash.comments' registry
        // hook, which nothing fires when that module is absent. This module
        // therefore gains comments without depending on them, and the comments
        // module never learns what a page is.
        hook_listen('comments.targets', fn () => CommentTargets::register('page', [
            'label' => 'Pages',
            // Which zone-context key carries a page, so the comments block
            // knows what it is standing under.
            'context' => 'page',
            'resolve' => fn (int $id) => Page::find($id),
            'url' => fn (Page $page) => $page->url(),
            'title' => fn (Page $page) => $page->t('title') ?: $page->path,
        ]));

        // Answered lazily, and a harmless no-op when torgash.api is not
        // installed: the closure only runs if that module ever asks.
        hook_listen('api.resources', fn () => (new Api\PageResources)->register());

        $this->sitemap();
        $this->llms();
    }

    /**
     * The shop's own pages in core's map for machine readers.
     *
     * Worth its ten lines: what an agent is actually asked about a shop —
     * delivery, returns, who to call — is written here and nowhere in the API,
     * and a catalogue readable by machine beside terms of sale that are not is
     * half an answer. Only the section roots are listed; each of those ends in
     * links to its own children, so the map stays a page rather than becoming
     * a second sitemap.
     */
    /**
     * "Which page is OPEN" — the question a block in a page's zone asks
     * (pages.after_content / pages.sidebar hand over `page`). Without it a
     * block placed under pages was on every page or on none: the comments
     * block could not be kept off the privacy policy. The pages named, not
     * their sub-pages — a page tree is a menu's shape, not a family the way a
     * category's subtree is. Anywhere that is not a page the rule fails
     * closed, as every rule that cannot be answered does.
     */
    protected function displayRule(): void
    {
        \App\Core\Display\RuleKinds::register('page_viewed', [
            'label' => 'Page being viewed',
            'group' => 'where',
            'value' => 'options',
            'options' => fn () => Page::query()->orderBy('path')->get()
                ->mapWithKeys(fn (Page $page) => [$page->id => ($page->t('title') ?: $page->slug).' — /'.ltrim((string) $page->path, '/')])
                ->all(),
            'operators' => [
                'in' => 'is one of',
                'not_in' => 'is not one of',
            ],
            'matches' => function (array $rule, array $context) {
                $page = $context['page'] ?? null;

                if (! $page instanceof Page) {
                    return false;
                }

                $has = in_array((int) $page->id, array_map('intval', (array) ($rule['value'] ?? [])), true);

                return ($rule['operator'] ?? 'in') === 'not_in' ? ! $has : $has;
            },
        ]);
    }

    protected function llms(): void
    {
        // The one kind of page this module owns, for the SEO audit screen —
        // through a hook, so neither module names the other.
        hook_listen('seo.subjects', fn (array $subjects) => $subjects + [
            'page' => [
                'label' => 'Pages',
                'query' => fn () => Page::query()->orderBy('path'),
                'title' => fn (Page $page) => $page->t('title') ?: $page->slug,
                'url' => fn (Page $page) => $page->url(),
                'edit_url' => fn (Page $page) => route('admin.pages.edit', $page),
                // `page_title` predates all of this — see the migration.
                'columns' => ['title' => 'page_title', 'description' => 'meta_description', 'h1' => 'h1'],
            ],
        ]);

        hook_listen('core.llms.sections', function (array $sections) {
            $pages = Page::published()->whereNull('parent_id')->orderBy('position')->get()
                ->filter(fn (Page $page) => $page->isVisible());

            if ($pages->isEmpty()) {
                return $sections;
            }

            $sections[] = [
                'title' => __('What the shop says about itself'),
                'position' => 30,
                'text' => __('Each of these is also available as text at the same address plus .md.'),
                'items' => $pages->map(fn (Page $page) => [
                    'label' => $page->t('title') ?: $page->path,
                    'url' => $page->url().'.md',
                ])->values()->all(),
            ];

            return $sections;
        });
    }

    /**
     * A page's real address is in the ROOT — /info/delivery, not
     * /page/info/delivery — and it is served by a fallback route.
     *
     * A fallback is tried only after every other route has failed to match, so
     * a page cannot take an address away from the catalogue, the cart, a sale
     * or the admin: it gets what would otherwise have been a 404. That is the
     * whole reason this is safe, and it is why the module does not try to
     * register a catch-all of its own with a blacklist of reserved prefixes.
     *
     * Two things this cannot delegate to StorefrontRoutes::group(), which is
     * why the registration is written out here:
     *
     *  - ORDER. Laravel moves every fallback to the end of the table, keeps
     *    their relative order and takes the FIRST that matches — and a bare
     *    {fallback} matches /ru/delivery too. So the locale-prefixed shapes
     *    must be registered BEFORE the bare one, which is the opposite of the
     *    order that group() uses;
     *  - NAMES. LocaleResolver reads the request's locale off the route NAME
     *    ("ru.pages.page"), and route() needs a name to build a link at all.
     */
    protected function rootAddresses(): void
    {
        // `page.cache`: kept ready for guests (PLAN §61) — an address that is
        // no page answers 404 and is simply not kept.
        foreach (LocaleResolver::routePrefixes() as $locale) {
            Route::middleware('web')->prefix($locale)->name($locale.'.')
                ->group(fn () => Route::fallback([PageController::class, 'show'])->name('pages.page')->middleware('page.cache:page'));
        }

        Route::middleware('web')
            ->group(fn () => Route::fallback([PageController::class, 'show'])->name('pages.page')->middleware('page.cache:page'));

        page_cache()->kind('page', 'Pages');
    }

    /**
     * Catalog fires this filter while building /sitemap.xml. Listening to a
     * hook nobody fires costs nothing, so this module still needs no
     * dependency on catalog — it simply has nothing to add in a shop with no
     * catalogue module installed.
     */
    protected function sitemap(): void
    {
        hook_listen('catalog.sitemap.urls', function (array $urls) {
            $pages = Page::published()->where('in_sitemap', true)->orderBy('position')->get()
                // A page scoped to another storefront 404s here (see the
                // controller), and a sitemap listing a 404 is worse than one
                // listing nothing.
                ->filter(fn (Page $page) => $page->isVisible());

            foreach ($pages as $page) {
                $urls[] = [
                    'loc' => $page->url(),
                    'lastmod' => $page->updated_at?->toAtomString(),
                    'changefreq' => 'monthly',
                    'priority' => '0.5',
                ];
            }

            return $urls;
        });
    }
}
