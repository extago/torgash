<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\ListingView;

/**
 * The bar over a listing: page size, cards or rows, and the floating
 * button's count (Support\ListingView, StorefrontController::countAnswer()).
 */
class CatalogListingViewTest extends TestCase
{
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => ['en' => 'Viewed'], 'slug' => 'viewed', 'enabled' => true,
        ]);

        app(SettingsManager::class)->set('torgash.catalog', 'per_page', 2);
        app(SettingsManager::class)->set('torgash.catalog', 'per_page_choices', '4 8');

        foreach (range(1, 5) as $n) {
            $this->product('LV-'.$n, 'Item '.$n, price: $n * 100_00);
        }
    }

    /** The default size is always on offer, so the switch can get back. */
    public function test_the_default_size_is_among_the_choices(): void
    {
        $this->assertSame([2, 4, 8], app(ListingView::class)->choices());
    }

    public function test_an_offered_size_applies_and_any_other_is_the_default(): void
    {
        $this->assertSame(2, $this->get('http://localhost/catalog/viewed')->viewData('products')->perPage());
        $this->assertSame(4, $this->get('http://localhost/catalog/viewed?per_page=4')->viewData('products')->perPage());
        // A whole catalogue in one page is not something an address can ask for.
        $this->assertSame(2, $this->get('http://localhost/catalog/viewed?per_page=100000')->viewData('products')->perPage());
    }

    /** The page's script writes the choice in plain text; the server reads it. */
    public function test_the_remembered_choice_is_read_from_a_plain_cookie(): void
    {
        $response = $this->withUnencryptedCookies([
            ListingView::PER_PAGE_COOKIE => '8',
            ListingView::LAYOUT_COOKIE => 'list',
        ])->get('http://localhost/catalog/viewed');

        $this->assertSame(8, $response->viewData('products')->perPage());
        $response->assertSee('torgash-row', false)->assertDontSee('torgash-card-grid', false);
    }

    public function test_the_list_view_draws_rows_with_the_quantity_open(): void
    {
        $this->get('http://localhost/catalog/viewed?view=list')->assertOk()
            ->assertSee('torgash-row', false)
            ->assertSee('Item 5');
    }

    /**
     * The no-script links keep the address as it was typed: never
     * http_build_query's numbered lists.
     */
    public function test_the_links_keep_the_address_spelling(): void
    {
        $body = $this->get('http://localhost/catalog/viewed?feature[8][]=1&page=1')->getContent();

        $this->assertStringContainsString('viewed?feature[8][]=1&amp;page=1&amp;view=list"', $body);
        // A new page size starts at page 1.
        $this->assertStringContainsString('viewed?feature[8][]=1&amp;per_page=4"', $body);
        $this->assertStringNotContainsString('%5B0%5D=', $body);
    }

    /**
     * The grid lives OUTSIDE the filter form: a card's own buy form inside it
     * was dropped by the parser, and the first card's basket button submitted
     * the filters.
     */
    public function test_the_grid_is_not_inside_the_filter_form(): void
    {
        $body = $this->get('http://localhost/catalog/viewed')->getContent();

        $form = strpos($body, 'id="catalog-filters"');
        $formEnd = strpos($body, '</form>', $form);

        $this->assertNotFalse($form);
        $this->assertGreaterThan($formEnd, strpos($body, 'torgash-card-grid'));
    }

    /** The floating button's number is the listing's own query. */
    public function test_the_count_answers_for_the_boxes_as_they_stand(): void
    {
        $this->get('http://localhost/catalog/viewed', ['X-Catalog-Count' => '1'])
            ->assertOk()->assertSeeText('Show 5 products');

        $this->get('http://localhost/catalog/viewed?price_max=200', ['X-Catalog-Count' => '1'])
            ->assertSeeText('Show 2 products');

        $this->get('http://localhost/catalog/viewed?price_min=100000', ['X-Catalog-Count' => '1'])
            ->assertSeeText('Nothing matches');
    }

    /**
     * A price typed in the visitor's currency is converted to the books, and
     * the bounds never shut out the product whose price was read off a card.
     */
    public function test_a_price_typed_in_another_currency_is_read_in_the_books(): void
    {
        \App\Models\Currency::updateOrCreate(['code' => 'UAH'], ['symbol' => '₴', 'rate' => 40, 'decimals' => 0, 'enabled' => true]);
        app(\App\Core\Support\Currencies::class)->flush();

        // 200.00 in the books is 8 000 ₴; up to 8 000 ₴ keeps Item 2, drops Item 3.
        $this->get('http://localhost/catalog/viewed?price_max=8000&price_currency=UAH', ['X-Catalog-Count' => '1'])
            ->assertSeeText('Show 2 products');

        // Without the word the same number is dollars.
        $this->get('http://localhost/catalog/viewed?price_max=8000', ['X-Catalog-Count' => '1'])
            ->assertSeeText('Show 5 products');

        // The chip says which currency it was typed in.
        $this->get('http://localhost/catalog/viewed?price_max=8000&price_currency=UAH')
            ->assertSee('— 8000 ₴');
    }

    protected function product(string $sku, string $name, int $price = 100_00): Product
    {
        $product = Product::create([
            'sku' => $sku,
            'name' => ['en' => $name],
            'slug' => strtolower($sku),
            'price' => $price,
            'stock' => 4,
            'status' => 'active',
            'availability_status' => 'in_stock',
        ]);

        $product->categories()->attach($this->category->id);

        return $product;
    }
}
