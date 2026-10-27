<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Reviews\Models\Review;

/**
 * The admin's frame: a page is headed ONCE (by its own heading, or by its
 * title when it has none — never both, and never again in the top bar), and
 * the bell in the top bar counts what modules say is waiting.
 */
class AdminChromeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    public function test_a_page_without_a_heading_of_its_own_is_headed_by_its_title(): void
    {
        $html = $this->get('http://localhost/admin/catalog/products')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_a_page_with_its_own_heading_gets_no_second_one(): void
    {
        $product = Product::create(['sku' => 'CHROME-1', 'name' => ['en' => 'Chrome'], 'slug' => 'chrome-1', 'price' => 100, 'stock' => 1, 'status' => 'active']);

        // The product form names itself in its sticky bar (own_heading).
        $this->assertSame(0, substr_count($this->get('http://localhost/admin/catalog/products/'.$product->id.'/edit')->assertOk()->getContent(), '<h1'));

        // A page drawing its own <h1> keeps exactly that one.
        $this->assertSame(1, substr_count($this->get('http://localhost/admin/reviews')->assertOk()->getContent(), '<h1'));
    }

    public function test_the_bell_counts_what_is_waiting(): void
    {
        $product = Product::create(['sku' => 'CHROME-2', 'name' => ['en' => 'Chrome'], 'slug' => 'chrome-2', 'price' => 100, 'stock' => 1, 'status' => 'active']);
        Review::create(['product_id' => $product->id, 'user_id' => auth()->id(), 'author_name' => 'A', 'rating' => 5, 'body' => 'Fine', 'status' => Review::PENDING]);

        $this->get('http://localhost/admin/notifications')
            ->assertOk()
            ->assertSee(__('Reviews to moderate'));
    }
}
