<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\AvailabilityStatus;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Sorting\Sorts;
use Torgash\Catalog\Stock\AvailabilityStatuses;

/**
 * The shop's word about availability statuses (PLAN §31.6): a weight in the
 * listing for every status, a new name for any, and statuses of its own.
 *
 * What is pinned: weights order a listing heaviest first with the chosen sort
 * inside one weight; a product that ran out ranks as Out of stock, but a
 * status that cannot be bought anyway keeps its own weight whatever its
 * number; the numbers compare as NUMBERS; a code status's meaning cannot be
 * rewritten from the table; and the screen refuses the two deletes that would
 * strand products.
 */
class AvailabilityStatusWeightsTest extends TestCase
{
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        app(AvailabilityStatuses::class)->flush();

        $this->category = Category::create([
            'name' => ['en' => 'Weighed'], 'slug' => 'weighed', 'enabled' => true,
        ]);

        app(SettingsManager::class)->set('torgash.catalog', 'out_of_stock_last', true);
        app(SettingsManager::class)->set('torgash.catalog', 'default_sort', 'price_asc');
        app(Sorts::class)->flush();
    }

    /**
     * The owner's own example: discontinued 0, out of stock 1, preorder and a
     * status of the shop's own ("needs confirming") both 10 — mixed, so the
     * price decides between them — and in stock 20.
     */
    public function test_weights_order_the_listing_and_equal_weights_mix(): void
    {
        AvailabilityStatus::create(['key' => 'needs_confirming', 'label' => ['en' => 'Needs confirming'], 'orderable' => false, 'tracks_stock' => true, 'sort_weight' => 10]);

        foreach (['discontinued' => 0, 'out_of_stock' => 1, 'preorder' => 10, 'in_stock' => 20] as $key => $weight) {
            AvailabilityStatus::create(['key' => $key, 'sort_weight' => $weight]);
        }

        app(AvailabilityStatuses::class)->flush();

        $this->product('W-1', 'Gone for good', 100_00, 5, 'discontinued');
        $this->product('W-2', 'Ran out', 200_00, 0);
        $this->product('W-3', 'Confirm first', 300_00, 0, 'needs_confirming');
        $this->product('W-4', 'Preordered', 400_00, 0, 'preorder');
        $this->product('W-5', 'Confirm cheaply', 250_00, 0, 'needs_confirming');
        $this->product('W-6', 'On the shelf', 900_00, 3);
        $this->product('W-7', 'Marked sold out', 50_00, 7, 'out_of_stock');

        $this->get('http://localhost/catalog/weighed')->assertOk()->assertSeeInOrder([
            'On the shelf',
            // weight 10, by price — the shop's own status beside preorder,
            // and at zero stock, because its number never decided anything.
            'Confirm cheaply', 'Confirm first', 'Preordered',
            // weight 1: marked so, and in stock with nothing left
            'Marked sold out', 'Ran out',
            'Gone for good',
        ]);
    }

    /** A bound `then ?` is TEXT to Postgres, where "10" sorts below "2". */
    public function test_weights_compare_as_numbers(): void
    {
        AvailabilityStatus::create(['key' => 'preorder', 'sort_weight' => 2]);
        AvailabilityStatus::create(['key' => 'in_stock', 'sort_weight' => 10]);
        app(AvailabilityStatuses::class)->flush();

        $this->product('W-8', 'Preordered', 100_00, 0, 'preorder');
        $this->product('W-9', 'On the shelf', 200_00, 3);

        $this->get('http://localhost/catalog/weighed')->assertOk()
            ->assertSeeInOrder(['On the shelf', 'Preordered']);
    }

    /** A rename and a weight — never a new meaning for a status code relies on. */
    public function test_a_code_status_takes_only_its_name_and_weight_from_the_table(): void
    {
        AvailabilityStatus::create([
            'key' => 'out_of_stock', 'label' => ['en' => 'Expected soon'],
            'orderable' => true, 'tracks_stock' => false, 'sort_weight' => 5,
        ]);

        $statuses = app(AvailabilityStatuses::class);
        $statuses->flush();

        $this->assertSame('Expected soon', $statuses->label('out_of_stock'));
        $this->assertSame(5, $statuses->find('out_of_stock')['sort_weight']);
        $this->assertFalse($statuses->find('out_of_stock')['orderable']);
        $this->assertTrue($statuses->find('out_of_stock')['tracks_stock']);
        $this->assertFalse($this->product('W-10', 'Still not for sale', 100_00, 9, 'out_of_stock')->isOrderable());
    }

    public function test_the_shop_creates_a_status_and_products_can_carry_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('http://localhost/admin/catalog/availability-statuses', [
            'label' => 'Made to order',
            'orderable' => '1',
            'tracks_stock' => '0',
            'schema' => 'https://schema.org/MadeToOrder',
            'sort_weight' => '7',
        ])->assertRedirect(route('admin.catalog.availability-statuses.edit', 'made_to_order'));

        $statuses = app(AvailabilityStatuses::class);
        $statuses->flush();
        $def = $statuses->find('made_to_order');

        $this->assertNotNull($def);
        $this->assertSame('shop', $def['source']);
        $this->assertSame(7, $def['sort_weight']);
        $this->assertTrue($this->product('W-11', 'Sewn for you', 100_00, 0, 'made_to_order')->isOrderable());

        $this->actingAs($admin)->get('http://localhost/admin/catalog/availability-statuses')
            ->assertOk()->assertSee('Made to order')->assertSee('made_to_order');
    }

    public function test_the_weights_form_saves_and_a_blank_one_returns_to_the_default(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('http://localhost/admin/catalog/availability-statuses/weights', [
            'weights' => ['preorder' => '3', 'in_stock' => ''],
        ])->assertRedirect();

        $this->assertSame(3, AvailabilityStatus::where('key', 'preorder')->value('sort_weight'));
        // Nothing said about in_stock, so no row stands for it.
        $this->assertFalse(AvailabilityStatus::where('key', 'in_stock')->exists());

        $this->actingAs($admin)->put('http://localhost/admin/catalog/availability-statuses/weights', [
            'weights' => ['preorder' => ''],
        ]);

        $this->assertFalse(AvailabilityStatus::where('key', 'preorder')->exists());
        app(AvailabilityStatuses::class)->flush();
        $this->assertSame(AvailabilityStatuses::ORDERABLE_WEIGHT, app(AvailabilityStatuses::class)->find('preorder')['sort_weight']);
    }

    public function test_a_status_in_use_and_a_code_status_are_not_deleted(): void
    {
        $admin = $this->admin();
        AvailabilityStatus::create(['key' => 'on_hold', 'label' => ['en' => 'On hold'], 'orderable' => false]);
        app(AvailabilityStatuses::class)->flush();
        $product = $this->product('W-12', 'Held', 100_00, 1, 'on_hold');

        $this->actingAs($admin)->delete('http://localhost/admin/catalog/availability-statuses/on_hold')->assertSessionHas('error');
        $this->assertTrue(AvailabilityStatus::where('key', 'on_hold')->exists());

        $this->actingAs($admin)->delete('http://localhost/admin/catalog/availability-statuses/preorder')->assertSessionHas('error');

        $product->forceFill(['availability_status' => 'in_stock'])->save();

        $this->actingAs($admin)->delete('http://localhost/admin/catalog/availability-statuses/on_hold')->assertRedirect();
        $this->assertFalse(AvailabilityStatus::where('key', 'on_hold')->exists());
    }

    public function test_the_list_searches_and_the_form_stays_open(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin)->get('http://localhost/admin/catalog/availability-statuses?q=preorder')->assertOk()->getContent();
        $this->assertStringContainsString('/admin/catalog/availability-statuses/preorder/edit', $html);
        $this->assertStringNotContainsString('/admin/catalog/availability-statuses/discontinued/edit', $html);

        $this->get('http://localhost/admin/catalog/availability-statuses/preorder/edit')->assertOk()
            ->assertSee('name="after_save"', false)
            ->assertDontSee('form="delete-status"', false);

        $this->put('http://localhost/admin/catalog/availability-statuses/preorder', ['sort_weight' => '8'])
            ->assertRedirect(route('admin.catalog.availability-statuses.edit', 'preorder'));
        $this->put('http://localhost/admin/catalog/availability-statuses/preorder', ['sort_weight' => '8', 'after_save' => 'close'])
            ->assertRedirect(route('admin.catalog.availability-statuses.index'));
    }

    public function test_a_new_status_may_not_take_a_code_statuses_key(): void
    {
        $this->actingAs($this->admin())->post('http://localhost/admin/catalog/availability-statuses', [
            'label' => 'Preorder again', 'key' => 'preorder', 'orderable' => '1',
        ])->assertSessionHasErrors('key');

        $this->assertFalse(AvailabilityStatus::where('key', 'preorder')->exists());
    }

    protected function product(string $sku, string $name, int $price, int $stock, string $availability = 'in_stock'): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => $price, 'stock' => $stock, 'status' => 'active',
            'availability_status' => $availability,
        ]);

        $product->categories()->attach($this->category->id);

        return $product;
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
