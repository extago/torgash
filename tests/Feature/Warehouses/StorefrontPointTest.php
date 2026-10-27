<?php

namespace Tests\Feature\Warehouses;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Shipping\LocationStore;
use Torgash\Locations\Models\Place;
use Torgash\Warehouses\Models\Warehouse;

/**
 * The catalogue seen through the shop the customer picked (PLAN §20.3).
 *
 * The hard part is not the filter, it is that "we do not have it at that shop"
 * is a THIRD answer. Printing "in stock" sends someone to an empty shelf;
 * printing "out of stock" refuses a sale the shop could make. So the default is
 * to say exactly that, and hiding is a setting a shop opts into — the one where
 * goods genuinely cannot move between points.
 */
class StorefrontPointTest extends TestCase
{
    protected Place $kyiv;

    protected Warehouse $shop;

    protected Warehouse $other;

    protected Product $here;

    protected Product $elsewhere;

    protected Product $unsplit;

    protected function setUp(): void
    {
        parent::setUp();

        Warehouse::query()->update(['enabled' => false, 'is_default' => false]);

        $this->kyiv = Place::create(['country' => 'UA', 'name' => ['en' => 'Kyiv'], 'type' => 'settlement', 'enabled' => true]);

        $this->shop = $this->point('Kyiv shop', $this->kyiv);
        $this->other = $this->point('Depot', $this->kyiv, pickup: false);

        $this->here = $this->product('POINT-HERE', 'point-here');
        $this->elsewhere = $this->product('POINT-AWAY', 'point-away');
        // No warehouse rows at all: its stock is the plain column, so it is
        // available wherever the rest is and none of this applies to it.
        $this->unsplit = $this->product('POINT-PLAIN', 'point-plain');

        $this->stock($this->here, [$this->shop->id => 4, $this->other->id => 1]);
        $this->stock($this->elsewhere, [$this->shop->id => 0, $this->other->id => 7]);
    }

    public function test_the_catalogue_says_what_is_on_the_chosen_shelf(): void
    {
        $this->choose($this->shop);

        $page = $this->get('http://localhost/catalog')->assertOk();

        $page->assertSee('In stock at Kyiv shop');
        // The third answer: we have it, just not where you are going.
        $page->assertSee('Not at Kyiv shop — available to order');
        // And the product whose stock is not split says what it always said.
        $page->assertSee('In stock');
    }

    /** The product page answers the same question, ahead of the warehouse list. */
    public function test_the_product_page_leads_with_the_chosen_shop(): void
    {
        $this->choose($this->shop);

        $this->get('http://localhost/product/point-away')
            ->assertOk()
            ->assertSee('Not at Kyiv shop — available to order');
    }

    public function test_without_a_chosen_shop_nothing_changes(): void
    {
        // The picker itself names every shop, of course — what must be absent
        // is any claim about a particular shelf.
        $this->get('http://localhost/catalog')
            ->assertOk()
            ->assertSee('In stock')
            ->assertDontSee('In stock at Kyiv shop');
    }

    /** A point that has since been closed must not go on speaking for the shop. */
    public function test_a_point_that_is_no_longer_offered_is_forgotten(): void
    {
        $this->choose($this->shop);
        $this->shop->update(['is_pickup' => false]);
        $this->nextRequest();

        $this->get('http://localhost/catalog')
            ->assertOk()
            ->assertDontSee('In stock at Kyiv shop');
    }

    public function test_hiding_is_opt_in_and_never_hides_unsplit_products(): void
    {
        app(SettingsManager::class)->set('torgash.warehouses', 'point_availability', 'hide');
        $this->choose($this->shop);

        $page = $this->get('http://localhost/catalog')->assertOk();

        $page->assertSee('POINT-HERE');
        // The whole difference from "mark": this one is simply not there.
        $page->assertDontSee('POINT-AWAY');
        // A product with no per-warehouse rows is available everywhere and is
        // never hidden — that would empty the catalogue of a shop that only
        // splits some of its stock.
        $page->assertSee('POINT-PLAIN');
    }

    /** Search is a listing too: hiding that only half applies is worse than not hiding. */
    public function test_search_hides_the_same_products_the_catalogue_does(): void
    {
        app(SettingsManager::class)->set('torgash.warehouses', 'point_availability', 'hide');
        $this->choose($this->shop);

        // Asserted on the slug in the card's href, not on the SKU: the results
        // page echoes the query back, so searching for the thing that must be
        // absent always "finds" it in the heading.
        $this->get('http://localhost/search?q=POINT')
            ->assertOk()
            ->assertSee('point-here')
            ->assertDontSee('point-away');
    }

    /**
     * Naming a shop names a town. The cart asks the same question, and asking
     * it again after the customer has already answered it is what makes the two
     * controls feel like different websites.
     */
    public function test_choosing_a_shop_answers_the_carts_town_question_too(): void
    {
        $this->choose($this->shop);

        $this->assertSame($this->kyiv->id, app(LocationStore::class)->placeId());
        $this->assertSame('Kyiv', app(LocationStore::class)->town());
    }

    public function test_it_never_overwrites_a_town_the_customer_typed(): void
    {
        $lviv = Place::create(['country' => 'UA', 'name' => ['en' => 'Lviv'], 'type' => 'settlement', 'enabled' => true]);

        $this->withSession(['checkout_location' => [
            'place_id' => $lviv->id, 'town' => 'Lviv', 'postal' => null, 'country' => 'UA',
        ]])->post('http://localhost/pickup-point', ['point_id' => $this->shop->id]);

        // Still Lviv: quoting delivery to a town the customer never asked for
        // is worse than asking them to pick the shop's town themselves.
        $this->assertSame($lviv->id, app(LocationStore::class)->placeId());
    }

    /** The per-point lookup is primed for the whole grid, like every other card query. */
    public function test_the_grid_does_not_cost_more_queries_as_it_grows(): void
    {
        $this->choose($this->shop);

        $this->get('http://localhost/catalog')->assertOk(); // warm the per-request memos

        $few = $this->queriesFor('http://localhost/catalog');

        for ($i = 1; $i <= 9; $i++) {
            $extra = $this->product("POINT-N{$i}", "point-n{$i}");
            $this->stock($extra, [$this->shop->id => 2]);
        }

        $this->assertSame($few, $this->queriesFor('http://localhost/catalog'), 'the point lookup runs per card');
    }

    protected function choose(Warehouse $point): void
    {
        $this->post('http://localhost/pickup-point', ['point_id' => $point->id])->assertRedirect();
    }

    protected function point(string $name, Place $place, bool $pickup = true): Warehouse
    {
        return Warehouse::create([
            'name' => ['en' => $name], 'place_id' => $place->id, 'enabled' => true,
            'is_public' => true, 'is_pickup' => $pickup,
        ]);
    }

    protected function product(string $sku, string $slug): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => $slug,
            'price' => 10_00, 'stock' => 5, 'status' => 'active',
        ]);
    }

    /** @param array<int,int> $rows warehouse id => qty */
    protected function stock(Product $product, array $rows): void
    {
        foreach ($rows as $warehouseId => $qty) {
            DB::table('m_warehouse_product_stock')->insert([
                'warehouse_id' => $warehouseId, 'product_id' => $product->id, 'qty' => $qty,
            ]);
        }

        DB::table('m_catalog_products')->where('id', $product->id)->update(['stock' => array_sum($rows)]);
    }

    /**
     * Tests reuse ONE application across requests, so the per-request memos
     * (the allocator's, the chosen point) stay warm — which in production they
     * never are. Dropping them is what makes the next request measure, and
     * behave, like a genuinely new one.
     */
    protected function nextRequest(): void
    {
        app()->forgetInstance(\Torgash\Warehouses\StockAllocator::class);
        app()->forgetInstance(\Torgash\Warehouses\PointStore::class);
    }

    protected function queriesFor(string $url): int
    {
        $this->nextRequest();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get($url)->assertOk();

        $count = count(DB::getRawQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
