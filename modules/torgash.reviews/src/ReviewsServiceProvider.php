<?php

namespace Torgash\Reviews;

use App\Core\Storefronts\StorefrontRoutes;
use App\Core\Support\PerRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Product;
use Torgash\Reviews\Models\Review;

class ReviewsServiceProvider extends ServiceProvider
{
    /**
     * Per-request memos, the rule every small hot lookup in this engine
     * follows — and the reset that makes them true.
     *
     * `Aggregates` remembers a product's mark for the page it is drawing; kept
     * across requests it would hand the next visitor the previous page's
     * average, and a review published in between would never show up. The
     * QueryBudget tests catch it from the other end: a memo that outlives a
     * request makes the second one look cheaper than it is.
     */
    public function register(): void
    {
        $this->app->singleton(Criteria::class);
        $this->app->singleton(Aggregates::class);
        $this->app->singleton(Group::class);
        $this->app->singleton(Eligibility::class);

        PerRequest::forget($this->app, Criteria::class, Aggregates::class, Group::class);
    }

    public function boot(): void
    {
        // Its part of the demo shop (PLAN §55.3), after the catalogue's products.
        hook_listen('core.demo.parts', fn (array $parts) => $parts + [
            'reviews' => ['label' => 'Reviews', 'after' => ['catalog'], 'install' => fn (\App\Core\Demo\DemoPack $pack) => app(Demo\ReviewsDemo::class)->install($pack)],
        ]);

        // What it keeps about a person, for core's personal-data registry (PLAN §49).
        hook_listen('core.personal_data', fn (array $context) => ReviewPersonalData::register($context['registry']));

        // Its card on core's admin dashboard.
        app(Admin\DashboardWidgets::class)->register();

        // The admin's bell: reviews nobody has read yet.
        hook_listen('core.admin.notifications', function (array $rows, array $context) {
            if (! ($context['user'] ?? null)?->can('reviews.moderate')) {
                return $rows;
            }

            return [...$rows, [
                'key' => 'reviews',
                'label' => __('Reviews to moderate'),
                'count' => Review::query()->reviews()->where('status', Review::PENDING)->count(),
                'url' => route('admin.reviews.index'),
                'icon' => 'star',
            ]];
        });

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        // An account going. `user_id` here has no foreign key (an imported
        // review has no account at all), so the constraint cannot do this: the
        // review stays, signed with `author_name` — the snapshot kept for
        // exactly this — and stops pointing at a row that is gone. A reply
        // written by a manager is the same case. Votes keep their bare id: they
        // are counted, never shown, and the counter must not move because
        // somebody else's account was closed.
        hook_listen('core.user.deleting', function (array $context) {
            if ($user = $context['user'] ?? null) {
                Review::where('user_id', $user->id)->update(['user_id' => null]);
            }
        });

        hook_listen('core.admin.customer.delete_notes', function (array $notes, array $context) {
            $count = ($customer = $context['customer'] ?? null) ? Review::query()->reviews()->where('user_id', $customer->id)->count() : 0;

            return $count === 0 ? $notes : [...$notes, ['key' => 'reviews', 'deleted' => false,
                'label' => trans_choice(':count review stays on the site, signed with the name it was written under.|:count reviews stay on the site, signed with the name they were written under.', $count, ['count' => $count])]];
        });

        // What must be true about the marks, for whoever is watching. Nobody
        // fires this filter when `torgash.guard` is absent, so it is a
        // statement about our own data rather than a dependency on it.
        hook_listen('guard.invariants', fn (array $all) => $all
            + (new Invariants\ReviewInvariants)->register());

        // The two public forms this module puts online, NAMED — that is what
        // lets the shop ask a captcha on one and not the other, and what makes
        // the rate limiter count them apart. Writing a review is a rare, slow
        // act; answering one is quick and frequent, so they must not share a
        // budget.
        // Its letters in core's registry (PLAN §41).
        hook_listen('core.mail.events', fn (array $events) => $events + \Torgash\Reviews\Mail\ReviewLetters::events());

        hook_listen('core.forms', fn (array $forms) => $forms + [
            Submit::FORM => 'Product reviews',
            PostReply::FORM => 'Answers to reviews',
        ]);

        Route::middleware(['web', 'auth', 'can:reviews.moderate'])
            ->prefix('admin/reviews')
            ->name('admin.reviews.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // Answered lazily, the rule provider boot order forces: nothing fires
        // unless the API (or exim) asks.
        hook_listen('api.resources', fn () => (new Api\ReviewResources)->register());
        (new Exim\ReviewSchema)->register();

        $this->registerSection();
        $this->registerRatingMarks();
        $this->registerSort();
        $this->registerStructuredData();
        $this->registerGroupUpkeep();
    }

    /**
     * Where variations share their reviews, a product's mark is its GROUP's —
     * so the rows move whenever a group does, though no review moved: a
     * product typed into a group (or taken out, or deleted) changes what its
     * old and new groups count, and switching the setting changes everybody.
     * Listened to on the model, so an import or the API moves them exactly as
     * the product form does.
     */
    protected function registerGroupUpkeep(): void
    {
        $regroup = function (Product $product, ?string $before): void {
            $group = app(Group::class);
            $aggregates = app(Aggregates::class);
            $group->flush();
            $aggregates->recalculate((int) $product->id);

            $left = trim((string) $before) === '' ? null : Product::withTrashed()
                ->where('variation_group', $before)->whereKeyNot($product->id)->value('id');

            if ($left) {
                $aggregates->recalculate((int) $left);
            }
        };

        // Two events, not `saved`: `wasRecentlyCreated` stays true on the
        // instance for good, so a later edit of it would read as its creation.
        Product::created(function (Product $product) use ($regroup) {
            if (Group::shared() && trim((string) $product->variation_group) !== '') {
                $regroup($product, null);
            }
        });

        Product::updated(function (Product $product) use ($regroup) {
            if (Group::shared() && $product->wasChanged('variation_group')) {
                $regroup($product, $product->getOriginal('variation_group'));
            }
        });

        // Only a FORCED delete: a product in the bin is still a member (see
        // Group::members()), and its reviews still the model's.
        Product::forceDeleted(function (Product $product) {
            // Its own reviews and replies go with it — through the model, so a
            // review's photos leave the disk — and so does its average: no
            // foreign key ties either to the catalogue's table, and both used
            // to stay behind.
            Review::where('product_id', $product->id)->get()->each->delete();
            DB::table('m_reviews_aggregates')->where('product_id', $product->id)->delete();

            if (Group::shared() && trim((string) $product->variation_group) !== '') {
                app(Group::class)->flush();
                $left = Product::withTrashed()->where('variation_group', $product->variation_group)->value('id');

                if ($left) {
                    app(Aggregates::class)->recalculate((int) $left);
                }
            }
        });

        hook_listen('core.setting.changed', function (array $context) {
            if ($context['module'] === 'torgash.reviews' && $context['key'] === 'share_variations' && ($context['storefront_id'] ?? null) === null) {
                app(Aggregates::class)->recalculateAll();
            }
        });
    }

    /**
     * The reviews section of the product page.
     *
     * Registered through `catalog.product.tabs`, which is what makes it a
     * section of the PAGE rather than a block bolted under it: it takes its
     * turn in the same strip as Description and Characteristics, the shop can
     * move it by sort, and it renders identically whether the shop lays the
     * page out as tabs or as sections. This module is the first thing to use
     * that seam from outside the catalogue — which is what it was for.
     *
     * Sort 30: after description (10) and characteristics (20), which is the
     * order a person reads a product page in.
     */
    protected function registerSection(): void
    {
        // The same section, told to the admin's list of product tabs, which
        // has no product to ask about and only wants to know it is there.
        hook_listen('catalog.product.tabs.admin', fn (array $rows) => [...$rows, [
            'key' => 'reviews',
            'label' => __('Reviews'),
            'sort' => 30,
            'module' => __('Reviews'),
            'note' => __('Shown on every product.'),
            'url' => url('/admin/reviews'),
        ]]);

        hook_listen('catalog.product.tabs', function (array $sections, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $sections;
            }

            $aggregates = app(Aggregates::class);
            $count = (int) ($aggregates->for((int) $product->id)->reviews_count ?? 0);

            $sections[] = [
                'key' => 'reviews',
                // The number apart from the word: the shop may rename the
                // section (Product tabs), and the count must survive that.
                'label' => __('Reviews'),
                'count' => $count,
                'heading' => __('Reviews of :product', ['product' => $product->t('name')]),
                'sort' => 30,
                'html' => view('torgash.reviews::storefront.section', [
                    'product' => $product,
                    // The order the reader asked for, read at RENDER time.
                    // Registration may not depend on the current request; this
                    // listener runs inside one, which is a different thing. A
                    // word nobody knows falls back to the default rather than
                    // failing — this is a link somebody may have kept.
                    'state' => $state = Listing::state(request()->query()),
                    'order' => $state['order'],
                    'reviews' => app(Listing::class)->forProduct((int) $product->id, $state['order'], $state),
                ])->render(),
                'section' => null,
            ];

            return $sections;
        });
    }

    /**
     * The mark itself: stars under the product's title and on every card in a
     * listing.
     *
     * The listing half is primed in ONE query off `catalog.products.listed` —
     * the rule `withCardData()` exists for, applied to another module's data:
     * a card that asked for its own average would turn a grid of twelve into
     * twelve more queries that nothing in the view makes visible.
     */
    protected function registerRatingMarks(): void
    {
        hook_listen('catalog.products.listed', function (array $context) {
            // Resolved inside, never captured at boot: a listener outlives the
            // request, and a captured memo is a static wearing a hat.
            app(Aggregates::class)->prime(collect($context['products'] ?? []));
        });

        hook_listen('catalog.product.card_meta', function (string $value, array $context) {
            return $value.$this->mark($context['product'] ?? null, compact: true);
        });

        hook_listen('catalog.product.title_meta', function (string $value, array $context) {
            return $value.$this->mark($context['product'] ?? null);
        });
    }

    protected function mark(mixed $product, bool $compact = false): string
    {
        if (! $product instanceof Product) {
            return '';
        }

        $row = app(Aggregates::class)->for((int) $product->id);

        // Nothing is printed for a product nobody has reviewed. "0.0 out of 5"
        // is a worse answer than silence: it reads as a bad product rather
        // than a new one.
        // On its own page it gets an invitation instead, which is how the
        // first review is ever written.
        if (! $row || (int) $row->reviews_count === 0) {
            return $compact ? '' : view('torgash.reviews::storefront.mark', [
                'rating' => 0.0, 'count' => 0, 'product' => $product, 'compact' => false,
            ])->render();
        }

        return view('torgash.reviews::storefront.mark', [
            'rating' => (float) $row->rating,
            'count' => (int) $row->reviews_count,
            'product' => $product,
            'compact' => $compact,
        ])->render();
    }

    /**
     * "Best rated first", through the catalogue's own sort registry.
     *
     * A LEFT join, because a product with no reviews is not a bad product —
     * it simply has no mark, and an inner join would hide half a young shop's
     * catalogue the moment somebody chose this order. Unreviewed rows sort
     * last by the same rule ("nulls last") rather than by pretending to be
     * zero.
     */
    protected function registerSort(): void
    {
        hook_listen('catalog.sorts', fn (array $sorts) => $sorts + [
            'rating' => [
                'label' => 'Best rated first',
                'icon' => 'star',
                'apply' => fn (Builder $query) => $query
                    ->leftJoin('m_reviews_aggregates as ra', 'ra.product_id', '=', 'm_catalog_products.id')
                    ->select('m_catalog_products.*')
                    ->orderByRaw('ra.rating desc nulls last')
                    ->orderByRaw('ra.reviews_count desc nulls last'),
            ],
        ]);
    }

    /**
     * What a search engine is told about the mark.
     *
     * Added to the product's node in the page's ONE graph
     * (`catalog.product.structured_data`), never as a script of its own — three
     * scripts are three graphs nothing connects. Printed only when there is
     * something to print: an `aggregateRating` with no ratings behind it is
     * the kind of claim that gets a shop's rich results switched off.
     */
    protected function registerStructuredData(): void
    {
        hook_listen('catalog.product.structured_data', function (array $node, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $node;
            }

            $row = app(Aggregates::class)->for((int) $product->id);

            if (! $row || (int) $row->reviews_count === 0 || (float) $row->rating <= 0) {
                return $node;
            }

            // Built by concatenation: a literal '@type' written in a Blade file
            // is read by the directive scanner, and the habit of never typing
            // one is cheaper than remembering which files are safe.
            $at = '@';

            $node['aggregateRating'] = [
                $at.'type' => 'AggregateRating',
                'ratingValue' => (float) $row->rating,
                'reviewCount' => (int) $row->reviews_count,
                'bestRating' => 5,
                'worstRating' => 1,
            ];

            return $node;
        });
    }
}
