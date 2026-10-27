<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Guard\Models\AuditEntry;

/**
 * "Newest first" sorts by the creation date, so the date is editable — the
 * same answer older engines give (PLAN §31.2).
 *
 * The owner's case is the one pinned first: a catalogue of phones imported in
 * one afternoon must not claim the iPhone 10 is as new as the 17. The rest is
 * what makes that safe — an emptied field is not an instruction to forget, a
 * form that never heard of the field leaves it alone, and a person moving it
 * is written down, because for a product this is a decision rather than a
 * timestamp.
 */
class ProductCreatedDateTest extends TestCase
{
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => ['en' => 'Phones'], 'slug' => 'phones', 'enabled' => true,
        ]);
    }

    /** The whole point: the date decides what "Newest first" shows first. */
    public function test_the_typed_date_decides_the_order(): void
    {
        $this->actingAs($this->admin());

        $old = $this->product('PH-10', 'Phone 10');
        $new = $this->product('PH-17', 'Phone 17');

        // Both rows were created a second apart just now, so without the field
        // the older model is simply whichever was inserted first.
        $this->save($old, ['created_at' => '2018-09-21T10:00']);
        $this->save($new, ['created_at' => '2025-09-19T10:00']);

        $this->get('http://localhost/catalog/phones?sort=newest')->assertOk()
            ->assertSeeInOrder(['Phone 17', 'Phone 10']);

        $this->assertSame('2018-09-21', $old->fresh()->created_at->format('Y-m-d'));
    }

    /**
     * An empty field is "I did not say", not "forget when this was made" — and
     * a form built before the field existed must leave the date alone
     * entirely, which is the same rule `availability_status` already follows.
     */
    public function test_an_empty_or_absent_field_leaves_the_date_alone(): void
    {
        $this->actingAs($this->admin());

        $product = $this->product('PH-9', 'Phone 9');
        $this->save($product, ['created_at' => '2019-01-05T09:30']);

        $this->save($product, ['created_at' => '']);
        $this->assertSame('2019-01-05', $product->fresh()->created_at->format('Y-m-d'));

        $this->save($product);
        $this->assertSame('2019-01-05', $product->fresh()->created_at->format('Y-m-d'));
    }

    /** A new product with no date typed is created now, exactly as before. */
    public function test_a_new_product_still_gets_todays_date(): void
    {
        $this->actingAs($this->admin());

        $this->post('http://localhost/admin/catalog/products', [
            'sku' => 'PH-NEW', 'name' => 'Brand new', 'price' => '10', 'stock' => '1', 'status' => 'active',
        ])->assertRedirect();

        $this->assertTrue(Product::where('sku', 'PH-NEW')->firstOrFail()->created_at->isToday());
    }

    /**
     * Moving it is an act, so the audit records it — the ignore list treats
     * `created_at` as a timestamp everywhere else, which is right everywhere
     * else. Without this, "who put that product back at the top of the shop"
     * has no answer.
     */
    public function test_moving_the_date_is_written_down(): void
    {
        $this->actingAs($this->admin());

        $product = $this->product('PH-8', 'Phone 8');
        $this->save($product, ['created_at' => '2017-09-22T12:00']);

        $entry = AuditEntry::where('entity_type', Product::class)
            ->where('entity_id', $product->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'the edit was not audited at all');
        $this->assertArrayHasKey('created_at', $entry->changes);
        $this->assertStringContainsString('2017-09-22', (string) $entry->changes['created_at']['to']);
    }

    /** ...but creating a row still does not carry a pointless entry for its own birth second. */
    public function test_creating_a_row_does_not_record_the_timestamp(): void
    {
        $this->actingAs($this->admin());

        $this->post('http://localhost/admin/catalog/products', [
            'sku' => 'PH-AUD', 'name' => 'Audited', 'price' => '10', 'stock' => '1', 'status' => 'active',
        ])->assertRedirect();

        $product = Product::where('sku', 'PH-AUD')->firstOrFail();

        $entry = AuditEntry::where('entity_type', Product::class)
            ->where('entity_id', $product->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($entry);
        $this->assertArrayNotHasKey('created_at', $entry->changes);
    }

    /** The import path, which is where the release dates actually come from. */
    public function test_the_import_schema_carries_the_date(): void
    {
        $product = $this->product('PH-IMP', 'Imported');

        $fields = collect(hook_filter('exim.schema.product', [], []))->keyBy('key');

        $this->assertArrayHasKey('created_at', $fields->all());

        ($fields['created_at']['set'])($product, '2016-09-16 08:00:00');
        $product->save();

        $this->assertSame('2016-09-16', $product->fresh()->created_at->format('Y-m-d'));

        // Empty in the file leaves the shop's own date where it is: an import
        // that quietly forgot it would re-date the whole catalogue.
        ($fields['created_at']['set'])($product, '');
        $product->save();

        $this->assertSame('2016-09-16', $product->fresh()->created_at->format('Y-m-d'));
    }

    /** @param array<string, mixed> $overrides */
    protected function save(Product $product, array $overrides = []): void
    {
        $this->put('http://localhost/admin/catalog/products/'.$product->id, [
            'sku' => $product->sku,
            'name' => $product->t('name'),
            'price' => $product->price / 100,
            'stock' => $product->stock,
            'status' => $product->status,
            // The form syncs relations from what it posts, so a payload that
            // omits this detaches the product from its category and the
            // listing below would be empty for a reason nothing to do with
            // dates.
            'categories' => [$this->category->id],
        ] + $overrides)->assertRedirect();
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    protected function product(string $sku, string $name): Product
    {
        $product = Product::create([
            'sku' => $sku,
            'name' => ['en' => $name],
            'slug' => strtolower($sku),
            'price' => 100_00,
            'stock' => 5,
            'status' => 'active',
        ]);

        $product->categories()->attach($this->category->id);

        return $product;
    }
}
