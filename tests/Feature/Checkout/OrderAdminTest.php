<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Taxes\Models\Tax;

/**
 * Managing a placed order from the admin: finding it, giving it to somebody,
 * and changing what is in it.
 *
 * The invariant every editing test is really checking is that three things stay
 * in step — the lines, the warehouse, and the money (including the tax snapshot
 * frozen onto the order). Any of the three drifting on its own is the bug this
 * file exists to catch.
 */
class OrderAdminTest extends TestCase
{
    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::role('admin')->firstOrFail();

        $this->product = Product::create([
            'sku' => 'ORD-1',
            'name' => ['en' => 'Orderable widget', 'ru' => 'Виджет'],
            'slug' => 'orderable-widget',
            'price' => 50_00,
            'stock' => 8,
            'status' => 'active',
        ]);
    }

    // ---------------------------------------------------------------- filters

    public function test_the_list_filters_by_status_manager_total_and_contents(): void
    {
        $manager = $this->manager();
        $other = Product::create([
            'sku' => 'ORD-2', 'name' => ['en' => 'Other widget'], 'slug' => 'other-widget',
            'price' => 10_00, 'stock' => 5, 'status' => 'active',
        ]);

        $mine = $this->order(['status' => 'new', 'manager_id' => $manager->id, 'total' => 100_00]);
        $unassigned = $this->order(['status' => 'new', 'total' => 20_00, 'email' => 'zoe@example.test'], $other);
        $done = $this->order(['status' => $this->otherStatus(), 'total' => 300_00]);

        $this->assertFiltered(['manager' => $manager->id], [$mine]);
        $this->assertFiltered(['manager' => 'none'], [$unassigned, $done]);
        $this->assertFiltered(['status' => ['new']], [$mine, $unassigned]);
        $this->assertFiltered(['total_from' => '90', 'total_to' => '200'], [$mine]);
        $this->assertFiltered(['product_id' => $other->id], [$unassigned]);
        $this->assertFiltered(['q' => 'zoe@example.test'], [$unassigned]);
    }

    public function test_an_unfiltered_list_shows_everything(): void
    {
        $a = $this->order();
        $b = $this->order(['email' => 'second@example.test']);

        $this->assertFiltered([], [$a, $b]);
    }

    // ---------------------------------------------------------------- manager

    public function test_an_order_can_be_given_to_a_staff_member_and_taken_back(): void
    {
        $manager = $this->manager();
        $order = $this->order();

        $this->actingAs($this->admin)
            ->patch($this->url($order, 'manager'), ['manager_id' => $manager->id])
            ->assertOk();

        $this->assertSame($manager->id, $order->fresh()->manager_id);
        $this->assertTrue($order->logs()->where('description', 'like', 'Manager:%')->exists());

        $this->actingAs($this->admin)
            ->patch($this->url($order, 'manager'), ['manager_id' => null])
            ->assertOk();

        $this->assertNull($order->fresh()->manager_id);
    }

    public function test_a_customer_cannot_be_made_the_manager_of_an_order(): void
    {
        $customer = User::create(['name' => 'Buyer', 'email' => 'buyer@example.test', 'password' => 'secret123']);
        $order = $this->order();

        $this->actingAs($this->admin)
            ->patch($this->url($order, 'manager'), ['manager_id' => $customer->id])
            ->assertSessionHasErrors('manager_id');

        $this->assertNull($order->fresh()->manager_id);
    }

    // ---------------------------------------------------------------- methods

    public function test_the_payment_method_can_be_changed_and_is_stored_as_its_name(): void
    {
        $order = $this->order();
        $method = PaymentMethod::where('enabled', true)->orderByDesc('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->patch($this->url($order, 'payment'), ['payment_method_id' => $method->id])
            ->assertOk();

        $this->assertSame($method->t('name'), $order->fresh()->payment_method_name);
        $this->assertTrue($order->logs()->where('description', 'like', 'Payment method:%')->exists());
    }

    public function test_changing_the_delivery_method_reprices_the_order_and_marks_it_manual(): void
    {
        $order = $this->order(['shipping_cost' => 0, 'total' => 100_00]);
        $order->update(['shipping_details' => ['pending_quote' => true, 'quote_reason' => 'carrier_unavailable']]);

        $name = ShippingMethod::where('enabled', true)->firstOrFail()->t('name');

        $this->actingAs($this->admin)
            ->patch('http://localhost/admin/shipping/orders/'.$order->id.'/method', [
                'shipping_method_name' => $name,
                'shipping_cost' => '7.50',
            ])
            ->assertRedirect();

        $order->refresh();

        $this->assertSame($name, $order->shipping_method_name);
        $this->assertSame(7_50, $order->shipping_cost);
        $this->assertSame(100_00 + 7_50, $order->total);
        $this->assertFalse($order->deliveryPending());
        $this->assertTrue($order->shipping_details['manual']);
        // The carrier's own snapshot is kept, not deleted: it is what the
        // customer was told at checkout.
        $this->assertSame('carrier_unavailable', $order->shipping_details['previous']['quote_reason']);
    }

    public function test_an_unknown_delivery_method_name_is_refused(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)
            ->patch('http://localhost/admin/shipping/orders/'.$order->id.'/method', [
                'shipping_method_name' => 'Teleportation',
                'shipping_cost' => '1.00',
            ])
            ->assertSessionHasErrors('shipping_method_name');

        $this->assertNull($order->fresh()->shipping_method_name);
    }

    // ------------------------------------------------------------------ items

    public function test_raising_a_quantity_reprices_the_order_and_takes_the_stock(): void
    {
        $order = $this->order(['stock_deducted' => true]);

        $this->actingAs($this->admin)
            ->patch($this->url($order, 'items/'.$this->product->id), ['qty' => 3, 'unit_price' => '50.00'])
            ->assertOk();

        $order->refresh();

        $this->assertSame(3, $order->items[0]['qty']);
        $this->assertSame(150_00, $order->subtotal);
        $this->assertSame(150_00, $order->total);
        // Two were already out of stock when the order was placed; the third
        // comes out now.
        $this->assertSame(7, $this->product->fresh()->stock);
    }

    public function test_lowering_a_quantity_puts_the_stock_back(): void
    {
        $order = $this->order(['stock_deducted' => true]);

        $this->actingAs($this->admin)
            ->patch($this->url($order, 'items/'.$this->product->id), ['qty' => 1, 'unit_price' => '50.00'])
            ->assertOk();

        $this->assertSame(50_00, $order->fresh()->subtotal);
        $this->assertSame(9, $this->product->fresh()->stock);
    }

    public function test_an_order_that_never_took_stock_does_not_give_any_back(): void
    {
        $order = $this->order(['stock_deducted' => false]);

        $this->actingAs($this->admin)
            ->patch($this->url($order, 'items/'.$this->product->id), ['qty' => 1, 'unit_price' => '50.00'])
            ->assertOk();

        $this->assertSame(50_00, $order->fresh()->subtotal);
        $this->assertSame(8, $this->product->fresh()->stock);
    }

    public function test_a_hand_typed_unit_price_is_what_the_line_and_the_order_cost(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)
            ->patch($this->url($order, 'items/'.$this->product->id), ['qty' => 2, 'unit_price' => '12,50'])
            ->assertOk();

        $order->refresh();

        $this->assertSame(12_50, $order->items[0]['unit_price']);
        $this->assertSame(25_00, $order->items[0]['line_total']);
        $this->assertSame(25_00, $order->total);
    }

    public function test_removing_the_only_line_empties_the_order_and_restores_stock(): void
    {
        $order = $this->order(['stock_deducted' => true]);

        $this->actingAs($this->admin)
            ->delete($this->url($order, 'items/'.$this->product->id))
            ->assertOk();

        $order->refresh();

        $this->assertSame([], $order->items);
        $this->assertSame(0, $order->subtotal);
        $this->assertSame(0, $order->total);
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_adding_a_product_appends_a_line_and_adding_it_again_only_raises_the_quantity(): void
    {
        $extra = Product::create([
            'sku' => 'ORD-3', 'name' => ['en' => 'Extra widget'], 'slug' => 'extra-widget',
            'price' => 25_00, 'stock' => 4, 'status' => 'active',
        ]);
        $order = $this->order(['stock_deducted' => true]);

        $this->actingAs($this->admin)->post($this->url($order, 'items'), ['product_id' => $extra->id])->assertOk();
        $this->actingAs($this->admin)->post($this->url($order, 'items'), ['product_id' => $extra->id])->assertOk();

        $order->refresh();

        $this->assertCount(2, $order->items);
        $this->assertSame(2, $order->items[1]['qty']);
        $this->assertSame(25_00, $order->items[1]['unit_price']);
        $this->assertSame(100_00 + 50_00, $order->subtotal);
        $this->assertSame(2, $extra->fresh()->stock);
    }

    public function test_the_product_search_finds_by_name_in_any_language_and_by_sku(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->get($this->url($order, 'items/search').'?q=Виджет')
            ->assertOk()->assertSee('Orderable widget');

        $this->actingAs($this->admin)->get($this->url($order, 'items/search').'?q=ORD-1')
            ->assertOk()->assertSee('ORD-1');

        $this->actingAs($this->admin)->get($this->url($order, 'items/search').'?q=nothinglikethis')
            ->assertOk()->assertSee('Nothing found.');
    }

    /**
     * The reason the tax snapshot is rewritten rather than left alone: an order
     * whose lines were edited has to keep adding up.
     */
    public function test_editing_the_lines_rewrites_the_frozen_tax_snapshot(): void
    {
        Tax::create([
            'code' => 'order-admin-vat', 'name' => ['en' => 'VAT'], 'rate_type' => 'percent',
            'rate_percent' => 10, 'price_includes_tax' => false, 'priority' => 0, 'enabled' => true,
        ]);

        $order = $this->order();
        // The order was placed before the tax existed, so it has no rows yet;
        // the first edit is what gives it some.
        $this->actingAs($this->admin)
            ->patch($this->url($order, 'items/'.$this->product->id), ['qty' => 4, 'unit_price' => '50.00'])
            ->assertOk();

        $order->refresh();
        $rows = DB::table('m_taxes_order_taxes')->where('order_id', $order->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(20_00, (int) $rows[0]->amount);
        $this->assertSame(200_00 + 20_00, $order->total);

        // And a second edit replaces those rows rather than adding to them.
        $this->actingAs($this->admin)
            ->patch($this->url($order, 'items/'.$this->product->id), ['qty' => 2, 'unit_price' => '50.00'])
            ->assertOk();

        $rows = DB::table('m_taxes_order_taxes')->where('order_id', $order->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(10_00, (int) $rows[0]->amount);
        $this->assertSame(100_00 + 10_00, $order->fresh()->total);
    }

    public function test_the_order_card_and_its_editor_render(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->get($this->url($order))->assertOk()->assertSee('Orderable widget');
        $this->actingAs($this->admin)->get($this->url($order, 'items'))->assertOk()->assertSee('Apply');
    }

    // ---------------------------------------------------------------- the list's own actions

    /** A bulk status change is N single changes: each order's stock moves as its own page would move it. */
    public function test_a_bulk_status_change_goes_through_the_status_changer(): void
    {
        $a = $this->order();
        $b = $this->order();

        $this->actingAs($this->admin)
            ->post('http://localhost/admin/orders/bulk', ['action' => 'status', 'status' => 'processing', 'ids' => [$a->id, $b->id]])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['processing', 'processing'], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertSame(8 - 2 - 2, (int) $this->product->fresh()->stock, 'both orders took their goods off the shelf');
    }

    public function test_bulk_manager_and_paid(): void
    {
        $manager = $this->manager();
        $a = $this->order();
        $b = $this->order();

        $this->actingAs($this->admin)
            ->post('http://localhost/admin/orders/bulk', ['action' => 'manager', 'manager_id' => $manager->id, 'ids' => [$a->id, $b->id]])
            ->assertRedirect();
        $this->assertSame([$manager->id, $manager->id], [$a->fresh()->manager_id, $b->fresh()->manager_id]);

        $this->post('http://localhost/admin/orders/bulk', ['action' => 'paid', 'ids' => [$a->id]])->assertRedirect();
        $this->assertSame('paid', $a->fresh()->payment_state);
        $this->assertSame((int) $a->total, (int) $a->fresh()->paid_total);

        // A customer is never a manager, in bulk either.
        $customer = User::create(['name' => 'Not staff', 'email' => 'nostaff@example.test', 'password' => 'secret123']);
        $this->post('http://localhost/admin/orders/bulk', ['action' => 'manager', 'manager_id' => $customer->id, 'ids' => [$b->id]])
            ->assertSessionHasErrors('manager_id');
    }

    public function test_the_row_changes_its_status_in_place_and_the_tabs_count(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)
            ->patch($this->url($order, 'status'), ['status' => 'processing', 'cell' => 1])
            ->assertOk()
            ->assertSee('id="order-status-'.$order->id.'"', false);
        $this->assertSame('processing', $order->fresh()->status);

        $this->get('http://localhost/admin/orders?status[]=processing')->assertOk()->assertSee($order->order_number);
    }

    // ---------------------------------------------------------------- the trash

    /** A trashed order gives its goods back; a restored one takes them again. */
    public function test_the_trash_returns_the_stock_and_a_restore_takes_it_again(): void
    {
        $order = $this->order();
        $this->actingAs($this->admin)
            ->post('http://localhost/admin/orders/bulk', ['action' => 'status', 'status' => 'processing', 'ids' => [$order->id]]);
        $this->assertSame(6, (int) $this->product->fresh()->stock);

        $this->delete($this->url($order))->assertRedirect(route('admin.orders.index'));
        $this->assertSoftDeleted($order);
        $this->assertSame(8, (int) $this->product->fresh()->stock, 'the goods are back on the shelf');
        // (the number itself is in the "moved to the trash" notice — the ROW is the question)
        $this->get('http://localhost/admin/orders')->assertDontSee('value="'.$order->id.'" x-model="selected"', false);
        $this->get('http://localhost/admin/orders?trashed=1')->assertOk()->assertSee('value="'.$order->id.'" x-model="selected"', false);

        $this->post('http://localhost/admin/orders/trash/'.$order->order_number.'/restore')->assertRedirect();
        $this->assertNotSoftDeleted($order);
        $this->assertSame(6, (int) $this->product->fresh()->stock, 'back in a status that holds stock, it takes them again');

        $this->delete($this->url($order));
        $this->delete('http://localhost/admin/orders/trash/'.$order->order_number)->assertRedirect();
        $this->assertDatabaseMissing('m_checkout_orders', ['id' => $order->id]);
    }

    /** An order with money on it stays: deleting it would erase a payment. */
    public function test_an_order_with_money_received_is_not_trashed(): void
    {
        $paid = $this->order(['payment_state' => 'paid', 'paid_total' => 100_00]);
        $spam = $this->order();

        $this->actingAs($this->admin)
            ->post('http://localhost/admin/orders/bulk', ['action' => 'trash', 'ids' => [$paid->id, $spam->id]])
            ->assertSessionHasErrors('refused');

        $this->assertNotSoftDeleted($paid);
        $this->assertSoftDeleted($spam);

        $this->delete($this->url($paid))->assertSessionHasErrors('trash');
        $this->assertNotSoftDeleted($paid);
    }

    // ---------------------------------------------------------------- invoice

    /** The invoice is the customer's document: their language by default, any of the storefront's on request. */
    public function test_the_invoice_speaks_the_orders_language_and_can_switch(): void
    {
        $order = $this->order(['locale' => 'ru', 'status' => 'processing']);
        $ruStatus = OrderStatus::find('processing')->t('name', 'ru');

        $this->actingAs($this->admin)->get($this->url($order, 'invoice'))->assertOk()
            ->assertSee('Плательщик')
            ->assertSee($ruStatus)
            ->assertSee('lang="ru"', false);

        $this->get($this->url($order, 'invoice').'?lang=en')->assertOk()
            ->assertSee('Bill to')
            ->assertSee('lang="en"', false);

        // A language the storefront does not have falls back to the order's own.
        $this->get($this->url($order, 'invoice').'?lang=xx')->assertOk()->assertSee('Плательщик');

        // ...and the admin's own language is put back afterwards.
        $this->assertSame('en', app()->getLocale());
    }

    // ---------------------------------------------------------------- statuses

    public function test_the_statuses_screen_counts_orders_and_keeps_a_used_status(): void
    {
        $this->order(['status' => 'processing']);

        $this->actingAs($this->admin)->get('http://localhost/admin/orders/statuses')->assertOk()
            ->assertSee(route('admin.orders.index', ['status' => ['processing']]), false);

        // Saving stays on the form; "save and close" goes back to the list.
        $this->put('http://localhost/admin/orders/statuses/processing', [
            'name' => 'Processing', 'color' => '#123456', 'inventory_action' => 'decrease',
        ])->assertRedirect(route('admin.orders.statuses.edit', 'processing'));
        $this->put('http://localhost/admin/orders/statuses/processing', [
            'name' => 'Processing', 'color' => '#123456', 'inventory_action' => 'decrease', 'after_save' => 'close',
        ])->assertRedirect(route('admin.orders.statuses.index'));

        // In use: refused, not deleted.
        $this->delete('http://localhost/admin/orders/statuses/processing')->assertSessionHasErrors();
        $this->assertNotNull(OrderStatus::find('processing'));
    }

    // ---------------------------------------------------------------- helpers

    protected function order(array $overrides = [], ?Product $product = null): Order
    {
        $product ??= $this->product;
        $qty = 2;
        $unit = (int) $product->price;

        $order = Order::create(array_merge([
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'currency_code' => 'USD',
            'subtotal' => $qty * $unit,
            'shipping_cost' => 0,
            'total' => $qty * $unit,
            'status' => 'new',
            'stock_deducted' => false,
            'items' => [[
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->t('name'),
                'qty' => $qty,
                'unit_price' => $unit,
                'line_total' => $qty * $unit,
            ]],
        ], $overrides));

        $order->update(['order_number' => 'TG-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)]);

        return $order->fresh();
    }

    protected function manager(): User
    {
        $role = Role::findOrCreate('sales');
        $role->syncPermissions(['admin.access', 'orders.manage']);

        $user = User::create(['name' => 'Sales Sam', 'email' => 'sam@torgash.local', 'password' => 'secret123']);
        $user->assignRole($role);

        return $user;
    }

    protected function otherStatus(): string
    {
        return OrderStatus::where('code', '!=', 'new')->value('code');
    }

    protected function url(Order $order, string $suffix = ''): string
    {
        return rtrim('http://localhost/admin/orders/'.$order->order_number.'/'.$suffix, '/');
    }

    /** @param  list<Order>  $expected */
    protected function assertFiltered(array $query, array $expected): void
    {
        $response = $this->actingAs($this->admin)
            ->get('http://localhost/admin/orders?'.http_build_query($query))
            ->assertOk();

        $shown = collect($expected)->pluck('order_number');
        foreach (Order::pluck('order_number') as $number) {
            $shown->contains($number)
                ? $response->assertSee($number)
                : $response->assertDontSee($number);
        }
    }
}
