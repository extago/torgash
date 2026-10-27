<?php

namespace Tests\Feature\Storefront;

use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\ListingView;

/**
 * Skeletons only where a part arrives after the page: "Show N more" names a
 * hidden grid of card shapes as its hx-indicator, as many as are coming; a
 * last page has no button and no skeleton.
 */
class LoadingSkeletonTest extends TestCase
{
    public function test_show_more_points_at_a_skeleton_of_what_is_coming(): void
    {
        $perPage = app(ListingView::class)->defaultPerPage();
        Product::query()->update(['status' => 'disabled']);
        foreach (range(1, $perPage + 2) as $i) {
            Product::create(['sku' => "SK-{$i}", 'name' => ['en' => "Shape {$i}"], 'slug' => "sk-shape-{$i}", 'price' => 1_00, 'stock' => 1, 'status' => 'active']);
        }

        $first = $this->get('http://localhost/catalog')->assertOk()->getContent();
        $this->assertStringContainsString('hx-indicator="#listing-skeleton"', $first);
        $this->assertStringContainsString('id="listing-skeleton" class="torgash-skeleton-when-busy', $first);
        $this->assertSame(2, substr_count(substr($first, strpos($first, 'id="listing-skeleton"'), 4000), 'skeleton aspect-square'), 'two more are coming, two shapes');

        $last = $this->get('http://localhost/catalog?page=2')->assertOk()->getContent();
        $this->assertStringNotContainsString('listing-skeleton', $last);
    }
}
