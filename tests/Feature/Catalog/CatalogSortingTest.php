<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Illuminate\Database\Eloquent\Builder;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Sorting\Sorts;

/**
 * Sorting a listing (PLAN §31.1).
 *
 * The storefront did not read `?sort=` at all — all three listings called
 * `->latest()` in their own hand — so what is pinned here is both halves of
 * what replaced it: the registry answers, and the rules it shares with the
 * filter flags hold (only an offered sort applies, a module can add one, an
 * address nobody understands is not an error).
 */
class CatalogSortingTest extends TestCase
{
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => ['en' => 'Sorted'], 'slug' => 'sorted', 'enabled' => true,
        ]);
    }

    /** The plain case, and the one the shop asked for first. */
    public function test_the_visitor_can_ask_for_cheapest_first(): void
    {
        $this->product('SRT-1', 'Dear one', price: 300_00);
        $this->product('SRT-2', 'Cheap one', price: 100_00);

        $this->get('http://localhost/catalog/sorted?sort=price_asc')->assertOk()
            ->assertSeeInOrder(['Cheap one', 'Dear one']);

        $this->get('http://localhost/catalog/sorted?sort=price_desc')->assertOk()
            ->assertSeeInOrder(['Dear one', 'Cheap one']);
    }

    /** With nothing asked for, the listing is in the order the shop chose. */
    public function test_the_shops_default_applies_when_nothing_is_asked_for(): void
    {
        $this->product('SRT-3', 'Dear one', price: 300_00);
        $this->product('SRT-4', 'Cheap one', price: 100_00);

        $this->settings(['default_sort' => 'price_asc']);

        $this->get('http://localhost/catalog/sorted')->assertOk()
            ->assertSeeInOrder(['Cheap one', 'Dear one']);
    }

    /**
     * A sort the shop has unticked does not apply — Flags' own rule, and for
     * the same reason: the select no longer offers a way back to any other
     * order, so an address in force that nothing on screen explains reads as a
     * broken page.
     */
    public function test_a_sort_the_shop_does_not_offer_falls_back_to_the_default(): void
    {
        $this->product('SRT-5', 'Dear one', price: 300_00);
        $this->product('SRT-6', 'Cheap one', price: 100_00);

        $this->settings(['catalog_sorts' => ['price_desc'], 'default_sort' => 'price_desc']);

        $this->get('http://localhost/catalog/sorted?sort=price_asc')->assertOk()
            ->assertSeeInOrder(['Dear one', 'Cheap one']);
    }

    /**
     * ...and a word nobody registered is not an error: it is a link from an
     * older version of the shop, or a typo, and the honest answer is the
     * listing in its usual order.
     */
    public function test_an_unknown_sort_is_answered_in_the_default_order(): void
    {
        $this->product('SRT-7', 'Dear one', price: 300_00);
        $this->product('SRT-8', 'Cheap one', price: 100_00);

        $this->settings(['default_sort' => 'price_asc']);

        $this->get('http://localhost/catalog/sorted?sort=by-the-phase-of-the-moon')->assertOk()
            ->assertSeeInOrder(['Cheap one', 'Dear one']);
    }

    /**
     * The select is a choice or it is nothing: one offered order is a shop
     * stating how its catalogue reads, not a control saying so.
     */
    public function test_the_select_is_drawn_only_where_there_is_something_to_choose(): void
    {
        $this->product('SRT-9', 'Only one');

        $this->get('http://localhost/catalog/sorted')->assertOk()
            ->assertSee('data-catalog-sort', false);

        $this->settings(['catalog_sorts' => ['newest']]);

        $this->get('http://localhost/catalog/sorted')->assertOk()
            ->assertDontSee('data-catalog-sort', false);
    }

    /**
     * "Out of stock last" is measured by whether the product can be BOUGHT,
     * never by a bare `stock <= 0` (PLAN §31.1): a preorder sold ahead of stock
     * is orderable with nothing on the shelf, and pushing it down with the
     * genuinely sold-out rows is what the naive version would have done.
     */
    public function test_out_of_stock_last_moves_what_cannot_be_bought_and_leaves_a_preorder_alone(): void
    {
        $this->product('SRT-10', 'Sold out', price: 100_00, stock: 0);
        $this->product('SRT-11', 'On the shelf', price: 200_00, stock: 5);
        $this->product('SRT-12', 'Preordered', price: 300_00, stock: 0, availability: 'preorder');

        $this->settings(['out_of_stock_last' => true, 'default_sort' => 'price_asc']);

        $this->get('http://localhost/catalog/sorted')->assertOk()
            ->assertSeeInOrder(['On the shelf', 'Preordered', 'Sold out']);
    }

    /** ...and with the tumbler off, the chosen sort is the whole answer. */
    public function test_without_the_tumbler_a_sold_out_product_keeps_its_place(): void
    {
        $this->product('SRT-13', 'Sold out', price: 100_00, stock: 0);
        $this->product('SRT-14', 'On the shelf', price: 200_00, stock: 5);

        $this->settings(['default_sort' => 'price_asc']);

        $this->get('http://localhost/catalog/sorted')->assertOk()
            ->assertSeeInOrder(['Sold out', 'On the shelf']);
    }

    /**
     * The registry is open, exactly as the flags' is: a module states a sort
     * and every listing gains it, with no change to the catalogue.
     */
    public function test_a_module_can_register_its_own_sort(): void
    {
        $this->product('SRT-15', 'Second', price: 100_00);
        $this->product('SRT-16', 'First', price: 300_00);

        hook_listen('catalog.sorts', fn (array $sorts) => $sorts + [
            'by_sku_desc' => [
                'label' => ['en' => 'By SKU, backwards'],
                'apply' => fn (Builder $query) => $query->orderBy('sku', 'desc'),
            ],
        ]);

        $this->settings(['catalog_sorts' => ['newest', 'by_sku_desc']]);

        $this->get('http://localhost/catalog/sorted?sort=by_sku_desc')->assertOk()
            ->assertSee('By SKU, backwards')
            ->assertSeeInOrder(['First', 'Second']);
    }

    /**
     * A page boundary may not fall inside a tie.
     *
     * Every sort is closed with `id`, because without it Postgres may return
     * rows that compare equal in any order it likes — and then one product
     * appears on both pages while another appears on neither. It is not a
     * hypothetical: an imported catalogue has a thousand products sharing a
     * creation second, which is exactly what this shop is about to do.
     */
    public function test_a_tie_is_broken_so_paging_shows_each_product_once(): void
    {
        $at = now()->subDay();

        foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $i => $name) {
            $this->product('SRT-2'.$i, $name)->forceFill(['created_at' => $at])->save();
        }

        $this->settings(['per_page' => 2, 'default_sort' => 'newest']);

        $seen = [];

        foreach ([1, 2] as $page) {
            $html = $this->get('http://localhost/catalog/sorted?page='.$page)->assertOk()->getContent();

            foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $name) {
                if (str_contains($html, '>'.$name.'<')) {
                    $seen[] = $name;
                }
            }
        }

        sort($seen);
        $this->assertSame(['Alpha', 'Beta', 'Delta', 'Gamma'], $seen);
    }

    /** @param array<string, mixed> $values */
    protected function settings(array $values): void
    {
        foreach ($values as $key => $value) {
            app(SettingsManager::class)->set('torgash.catalog', $key, $value);
        }

        app(Sorts::class)->flush();
    }

    protected function product(string $sku, string $name, int $price = 100_00, int $stock = 4, string $availability = 'in_stock'): Product
    {
        $product = Product::create([
            'sku' => $sku,
            'name' => ['en' => $name],
            'slug' => strtolower($sku),
            'price' => $price,
            'stock' => $stock,
            'status' => 'active',
            'availability_status' => $availability,
        ]);

        $product->categories()->attach($this->category->id);

        return $product;
    }
}
