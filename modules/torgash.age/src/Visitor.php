<?php

namespace Torgash\Age;

use App\Core\Storefronts\ClosedStorefront;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\Assortment;

/**
 * Who is looking, as far as age goes (PLAN §71).
 *
 * Whether the limits apply to this request at all: only a storefront PAGE
 * (its route carries ClosedStorefront — the API, the sitemap, a feed and a
 * plate do not) seen by somebody who is not staff. The admin, a letter or an
 * export built by a job, a search engine reading the sitemap: every picture
 * as it is. Staff see everything, as with a closed assortment — they have to
 * be able to check the shop; "Sign in as the customer" shows it as a buyer.
 *
 * What the visitor said they are: the age the "I am 18 or older" button
 * wrote into a cookie. Nobody checks it, and nothing here pretends to — it
 * is the courtesy of not showing the goods to whoever did not ask for them.
 *
 * And the limit of this page's own product or category — for the strip
 * that offers to show it and the window that asks first. A plate in a row
 * of other goods (the viewed products, "you may also like") asks nothing on
 * that page: it says 18+ itself and leads to the product, whose page asks.
 */
class Visitor
{
    public const COOKIE = 'torgash_age';

    /** How long the answer is kept. */
    public const DAYS = 30;

    protected ?bool $applies = null;

    protected ?Limit $page = null;

    /** @var array<int, string> age => the plate's address, built once per page */
    protected array $plates = [];

    /** The age a request's cookie says, 0 for none — cheap: the page cache asks it of every visitor. */
    public static function cookieAge(Request $request): int
    {
        return Rules::clampAge($request->cookies->get(self::COOKIE));
    }

    public function applies(): bool
    {
        if ($this->applies !== null) {
            return $this->applies;
        }

        $route = request()->route();

        if (! $route instanceof Route || ! in_array(ClosedStorefront::class, $route->gatherMiddleware(), true)) {
            return $this->applies = false;
        }

        return $this->applies = app(Assortment::class)->tokens() !== null;
    }

    public function age(): int
    {
        return self::cookieAge(request());
    }

    /** The plate to show instead of this product's pictures, or null. */
    public function cover(Product $product): ?string
    {
        if (! $this->applies()) {
            return null;
        }

        $limit = app(Rules::class)->forProduct($product);

        if ($limit === null || $limit->treatment($this->age()) === Limit::SHOW) {
            return null;
        }

        $age = $limit->liftAge();

        return $this->plates[$age] ??= route('age.plate', ['age' => $age, 'v' => 1]);
    }

    /** A listing without what this visitor may not be shown yet. */
    public function narrow(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $this->applies() ? app(Rules::class)->narrow($query, $this->age()) : $query;
    }

    /** This page is the page of a product or category with this limit. */
    public function onPageOf(?Limit $limit): void
    {
        $this->page = Limit::merge($this->page, $limit);
    }

    /**
     * The age the strip above the page asks for — 0, no strip: on the page
     * of a product or category whose photos are covered or goods left out
     * for this visitor (the category page of hidden goods is otherwise just
     * empty), unless the window asks already.
     */
    public function stripAge(): int
    {
        if (! $this->applies() || $this->page === null || $this->windowAge() > 0) {
            return 0;
        }

        if ($this->page->treatment($this->age()) === Limit::SHOW) {
            return 0;
        }

        return $this->page->liftAge();
    }

    /** The age the window over the page asks for — 0, no window (setting `window`). */
    public function windowAge(): int
    {
        if (! $this->applies()) {
            return 0;
        }

        $age = match ((string) setting('torgash.age', 'window', 'off')) {
            'everywhere' => app(Rules::class)->oldestAge(),
            'restricted' => $this->page?->age() ?? 0,
            default => 0,
        };

        return $age > $this->age() ? $age : 0;
    }
}
