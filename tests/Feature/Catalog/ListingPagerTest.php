<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;

/**
 * Under a listing: "Show N more" AND the pages (product-grid's pager). The
 * button asks for the next page and keeps only its cards; the pages it has
 * added are lit in the pager, told by a header so the address never says so.
 */
class ListingPagerTest extends TestCase
{
    protected function category(int $products): Category
    {
        // Two to a page, so five products make three pages.
        app(SettingsManager::class)->set('torgash.catalog', 'per_page', 2);
        app(SettingsManager::class)->set('torgash.catalog', 'per_page_choices', '2');

        $category = Category::create(['name' => ['en' => 'Pager'], 'slug' => 'lp-pager', 'enabled' => true]);
        app(CategoryTree::class)->flush();

        foreach (range(1, $products) as $i) {
            Product::create([
                'sku' => "LP-{$i}", 'name' => ['en' => "Pager product {$i}"], 'slug' => "lp-{$i}",
                'price' => 100_00, 'stock' => 5, 'status' => 'active',
            ])->categories()->attach($category->id);
        }

        return $category;
    }

    public function test_the_button_fetches_the_next_page_and_says_how_many_are_left(): void
    {
        $this->category(5);

        $html = $this->get('http://localhost/catalog/lp-pager')->assertOk()->getContent();

        $this->assertStringContainsString('id="listing-pager"', $html);
        $this->assertStringContainsString('Show 2 more', $html);
        $this->assertMatchesRegularExpression('~hx-get="[^"]*page=2[^"]*" hx-select="\[data-grid-cards\] > \*"~', $html);
        $this->assertStringContainsString('Shown 1–2 of 5', $html);
        $this->assertStringContainsString('5 products', $html, 'the count beside the heading');
    }

    public function test_the_pages_already_added_are_lit_and_the_last_page_has_no_button(): void
    {
        $this->category(5);

        $html = $this->withHeader('X-Listing-From', '1')
            ->get('http://localhost/catalog/lp-pager?page=3')->assertOk()->getContent();

        preg_match('~<div id="listing-pager".*?</nav>~s', $html, $pager);
        // A wide screen lights each of pages 1–3; a phone's copy is one "1–3".
        $this->assertSame(4, substr_count($pager[0] ?? '', 'aria-current="page"'), 'pages 1–3 are all on screen');
        $this->assertStringContainsString('btn-primary">1–3</span>', $pager[0] ?? '');
        $this->assertStringNotContainsString('hx-get=', $pager[0] ?? '');
        $this->assertStringContainsString('Shown 1–5 of 5', $html);
    }
}
