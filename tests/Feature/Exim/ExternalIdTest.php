<?php

namespace Tests\Feature\Exim;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;

/**
 * What the OTHER system calls a row, and why it is matched on first.
 *
 * An exchange keyed on our autoincrements survives until the first catalogue
 * reload; one keyed on the sku survives until the shop renames a sku. The
 * foreign id survives both, because it belongs to the system on the other end
 * and nobody here edits it.
 *
 * The order of matching is the whole feature, so that is what these pin down —
 * plus the migration path, which is the part that is easy to leave broken: a
 * catalogue that already exists has no external ids, and must acquire them from
 * the first import that carries them rather than being duplicated by it.
 */
class ExternalIdTest extends TestCase
{
    /** A line whose external id is known updates that row, whatever its sku says. */
    public function test_a_product_is_matched_by_its_external_id_before_its_sku(): void
    {
        $product = Product::create([
            'sku' => 'OLD-SKU', 'external_id' => '1c-42',
            'name' => ['en' => 'Renamed since'], 'slug' => 'renamed-since',
            'price' => 10_00, 'stock' => 1, 'status' => 'active',
        ]);

        $match = hook_filter('exim.match.product', null, ['row' => ['sku' => 'NEW-SKU', 'external_id' => '1c-42']]);

        $this->assertSame($product->id, $match['model']->id);
        $this->assertSame('external_id', $match['matchedBy']);
    }

    /**
     * The migration path: a catalogue that predates the exchange has no ids, so
     * the first import matches on the sku and the field writes the id onto it.
     */
    public function test_an_unknown_external_id_still_matches_by_sku_and_then_sticks(): void
    {
        $product = Product::create([
            'sku' => 'HAS-SKU',
            'name' => ['en' => 'Existing'], 'slug' => 'existing-product',
            'price' => 10_00, 'stock' => 1, 'status' => 'active',
        ]);

        $match = hook_filter('exim.match.product', null, ['row' => ['sku' => 'HAS-SKU', 'external_id' => 'new-link']]);

        $this->assertSame($product->id, $match['model']->id);
        $this->assertSame('sku', $match['matchedBy']);

        // ...and the schema field is what attaches it.
        $field = collect(hook_filter('exim.schema.product', []))->firstWhere('key', 'external_id');
        ($field['set'])($match['model'], 'new-link');
        $match['model']->save();

        $this->assertSame('new-link', $product->fresh()->external_id);
    }

    /** Nothing known: a new row, as before. */
    public function test_an_unknown_line_is_still_a_new_product(): void
    {
        $match = hook_filter('exim.match.product', null, ['row' => ['sku' => 'BRAND-NEW', 'external_id' => 'nobody']]);

        $this->assertFalse($match['model']->exists);
        $this->assertNull($match['matchedBy']);
    }

    /** An order's own number stays ours; theirs goes in external_id. */
    public function test_an_order_is_matched_by_the_other_systems_number(): void
    {
        $order = Order::create([
            'order_number' => 'TG-EX01', 'external_id' => 'doc-77',
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'currency_code' => 'USD', 'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
            'status' => 'open', 'payment_state' => PaymentState::PENDING, 'items' => [],
        ]);

        $match = hook_filter('exim.match.order', null, ['row' => ['order_number' => 'TG-SOMETHING-ELSE', 'external_id' => 'doc-77']]);

        $this->assertSame($order->id, $match['model']->id);
        $this->assertSame('external_id', $match['matchedBy']);
    }

    /** A customer's email moves; their id in the other system does not. */
    public function test_a_customer_is_matched_by_external_id_before_email(): void
    {
        $user = User::factory()->create(['email' => 'old@example.test']);
        $user->forceFill(['external_id' => 'crm-9'])->save();

        $match = hook_filter('exim.match.customer', null, ['row' => ['email' => 'new@example.test', 'external_id' => 'crm-9']]);

        $this->assertSame($user->id, $match['model']->id);
        $this->assertSame('external_id', $match['matchedBy']);
    }

    /** Two rows cannot claim one foreign id. */
    public function test_the_column_is_unique(): void
    {
        Product::create([
            'sku' => 'UNIQ-1', 'external_id' => 'same',
            'name' => ['en' => 'First'], 'slug' => 'uniq-first',
            'price' => 10_00, 'stock' => 1, 'status' => 'active',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Product::create([
            'sku' => 'UNIQ-2', 'external_id' => 'same',
            'name' => ['en' => 'Second'], 'slug' => 'uniq-second',
            'price' => 10_00, 'stock' => 1, 'status' => 'active',
        ]);
    }
}
