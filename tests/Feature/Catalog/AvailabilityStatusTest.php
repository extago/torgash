<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Filters\Flags;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Stock\AvailabilityStatuses;
use Torgash\Checkout\Orders\OrderStock;

/**
 * Availability status — a second axis from `status` (PLAN §31.3): whether a
 * product can be bought right now, and whether its stock number even applies.
 *
 * What is worth pinning: `isOrderable()` gates on `orderable` FIRST, so a
 * discontinued product with stock left is still not for sale; a preorder
 * tolerates negative stock while a plain "in stock" does not; an untracked
 * status never consults the number at all, in either direction (an order
 * never moves it, `OrderStock::canCover()` never compares against it); and
 * the ONE automatic transition — reaching zero — respects the shop's own
 * setting and never fires for a status that already tolerates going negative.
 */
class AvailabilityStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(AvailabilityStatuses::class)->flush();
    }

    public function test_a_freshly_created_product_is_orderable_without_a_refresh(): void
    {
        // The Eloquent/Postgres trap this guards against: a DB-level DEFAULT
        // is not read back into memory after INSERT, only after fresh().
        $product = $this->product('AVL-1', 5);

        $this->assertSame('in_stock', $product->availability_status);
        $this->assertTrue($product->isOrderable());
    }

    public function test_plain_in_stock_is_gated_by_the_number(): void
    {
        $product = $this->product('AVL-2', 0);

        $this->assertFalse($product->isOrderable());
    }

    public function test_out_of_stock_is_never_orderable_whatever_the_number_says(): void
    {
        $product = $this->product('AVL-3', 10, 'out_of_stock');

        $this->assertFalse($product->isOrderable());
    }

    public function test_preorder_stays_orderable_below_zero(): void
    {
        $product = $this->product('AVL-4', -3, 'preorder');

        $this->assertTrue($product->isOrderable());
    }

    /** Not orderable, whatever is left on the shelf — orderable gates first. */
    public function test_discontinued_is_never_orderable_even_with_stock_left(): void
    {
        $product = $this->product('AVL-5', 50, 'discontinued');

        $this->assertFalse($product->isOrderable());
    }

    public function test_unlimited_is_orderable_at_zero_or_below(): void
    {
        $product = $this->product('AVL-6', 0, 'unlimited');

        $this->assertTrue($product->isOrderable());
    }

    /** The number is untouched: an order-driven delta on an untracked status is a no-op. */
    public function test_adjust_stock_does_not_move_an_untracked_products_number(): void
    {
        $product = $this->product('AVL-7', 10, 'unlimited');

        $product->adjustStock(-4);

        $this->assertSame(10, $product->fresh()->stock);
    }

    /** The one automatic transition — reaching zero — follows the shop's setting. */
    public function test_reaching_zero_moves_to_the_configured_status(): void
    {
        app(SettingsManager::class)->set('torgash.catalog', 'availability_on_zero_status', 'preorder');
        $product = $this->product('AVL-8', 2);

        $product->adjustStock(-2);
        $fresh = $product->fresh();

        $this->assertSame(0, $fresh->stock);
        $this->assertSame('preorder', $fresh->availability_status);

        // ...and once there, further depletion is exactly what preorder is
        // for — it does not chase itself into some third status.
        $fresh->adjustStock(-5);
        $this->assertSame('preorder', $fresh->fresh()->availability_status);
        $this->assertSame(-5, $fresh->fresh()->stock);
    }

    /** Default policy preserves today's behaviour: zero just means not orderable. */
    public function test_the_default_zero_policy_is_out_of_stock(): void
    {
        $product = $this->product('AVL-9', 1);

        $product->adjustStock(-1);

        $this->assertSame('out_of_stock', $product->fresh()->availability_status);
    }

    public function test_the_transition_never_fires_for_a_status_that_already_allows_negative(): void
    {
        app(SettingsManager::class)->set('torgash.catalog', 'availability_on_zero_status', 'discontinued');
        $product = $this->product('AVL-10', 1, 'preorder');

        $product->adjustStock(-3);

        $this->assertSame('preorder', $product->fresh()->availability_status);
    }

    public function test_can_cover_treats_preorder_and_untracked_as_always_coverable(): void
    {
        $preorder = $this->product('AVL-11', -5, 'preorder');
        $unlimited = $this->product('AVL-12', 0, 'unlimited');
        $short = $this->product('AVL-13', 1);

        $stock = new OrderStock;

        $this->assertTrue($stock->canCover([
            ['product_id' => $preorder->id, 'qty' => 20],
            ['product_id' => $unlimited->id, 'qty' => 20],
        ]));

        $this->assertFalse($stock->canCover([
            ['product_id' => $short->id, 'qty' => 2],
        ]));

        $this->assertTrue($stock->canCover([
            ['product_id' => $short->id, 'qty' => 1],
        ]));
    }

    /**
     * "In stock only" narrows a paginated grid, so it has to be a WHERE
     * clause across every registered status — never a bare `stock > 0`,
     * which would hide an available preorder and show a discontinued product.
     */
    public function test_the_in_stock_flag_narrows_across_every_status(): void
    {
        app(SettingsManager::class)->set('torgash.catalog', 'filter_flags', ['in_stock']);
        app(Flags::class)->flush();

        $this->product('AVL-14', 5); // in_stock, has stock — included
        $this->product('AVL-15', 0); // in_stock, none left — excluded
        $this->product('AVL-16', -1, 'preorder'); // always orderable — included
        $this->product('AVL-17', 50, 'discontinued'); // never orderable — excluded

        $skus = app(Flags::class)->find('in_stock')['apply'](Product::query())
            ->pluck('sku')->sort()->values()->all();

        $this->assertSame(['AVL-14', 'AVL-16'], $skus);
    }

    /**
     * A theme's own CSS targets the status KEY, never the translated label —
     * "Preorder" moves with the locale and with a shop-typed override, the
     * key never does.
     */
    public function test_the_product_page_carries_a_css_hook_for_the_status_key(): void
    {
        $product = $this->product('AVL-21', -2, 'preorder');

        $body = $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent();

        $this->assertStringContainsString('torgash-availability-preorder', $body);
        $this->assertStringNotContainsString('torgash-availability-in_stock', $body);
    }

    /**
     * Found live: a plain "in stock" product with nothing left still carries
     * the RAW column's `in_stock` class while visibly reading "Out of stock" —
     * a hook that disagrees with what is on screen is useless for styling it.
     * The class has to track {@see Product::effectiveAvailabilityKey()}, not
     * the stored column.
     */
    public function test_the_css_hook_tracks_the_effective_state_not_the_raw_column(): void
    {
        $product = $this->product('AVL-22', 0); // availability_status stays 'in_stock' — nothing ever transitioned it

        $body = $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent();

        $this->assertStringContainsString('torgash-availability-out_of_stock', $body);
        $this->assertStringNotContainsString('torgash-availability-in_stock', $body);
    }

    public function test_schema_availability_maps_every_base_status(): void
    {
        $statuses = app(AvailabilityStatuses::class);

        $this->assertSame('https://schema.org/InStock', $statuses->schemaAvailability('in_stock'));
        $this->assertSame('https://schema.org/OutOfStock', $statuses->schemaAvailability('out_of_stock'));
        $this->assertSame('https://schema.org/PreOrder', $statuses->schemaAvailability('preorder'));
        $this->assertSame('https://schema.org/Discontinued', $statuses->schemaAvailability('discontinued'));
        // Fail closed: a status nobody registers is not advertised as buyable.
        $this->assertSame('https://schema.org/OutOfStock', $statuses->schemaAvailability('made_up'));
    }

    public function test_the_admin_form_writes_the_chosen_status(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product('AVL-18', 5);

        $this->post('http://localhost/admin/catalog/products', [
            'sku' => 'AVL-19', 'name' => 'New one', 'price' => '10', 'stock' => '3',
            'availability_status' => 'preorder', 'status' => 'active',
        ])->assertRedirect();

        $this->assertSame('preorder', Product::where('sku', 'AVL-19')->firstOrFail()->availability_status);

        // Omitted on an update: the product keeps what it already had, exactly
        // like a form built before this field existed.
        $this->put('http://localhost/admin/catalog/products/'.$product->id, [
            'sku' => 'AVL-18', 'name' => 'Renamed', 'price' => '10', 'stock' => '5', 'status' => 'active',
        ])->assertRedirect();

        $this->assertSame('in_stock', $product->fresh()->availability_status);
    }

    public function test_the_admin_form_refuses_an_unregistered_status(): void
    {
        $this->actingAs($this->admin());

        $this->post('http://localhost/admin/catalog/products', [
            'sku' => 'AVL-20', 'name' => 'Bad', 'price' => '10', 'stock' => '1',
            'availability_status' => 'made_up', 'status' => 'active',
        ])->assertSessionHasErrors('availability_status');
    }

    protected function product(string $sku, int $stock, string $availabilityStatus = 'in_stock'): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => $stock, 'status' => 'active',
            'availability_status' => $availabilityStatus,
        ]);
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
