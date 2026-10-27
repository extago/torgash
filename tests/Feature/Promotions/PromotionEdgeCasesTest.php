<?php

namespace Tests\Feature\Promotions;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderTotals;
use Torgash\Promotions\Models\Promotion;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * The adversarial pass.
 *
 * The negative-total bug survived nineteen green tests because every one of
 * them confirmed an intention rather than attacking it. These attack: the
 * branch no test had ever entered (an admin editing a placed order), money
 * that does not divide evenly, a gift that is not in stock, and a scope
 * pointing at rows that no longer exist.
 */
class PromotionEdgeCasesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Product::create([
            'sku' => 'EDGE-1', 'name' => ['en' => 'Edge'], 'slug' => 'edge',
            'price' => 100_00, 'stock' => 50, 'status' => 'active',
        ]);
    }

    // ------------------------------------------------- the admin edits an order

    /**
     * OrderTotals::recalculate() is the one funnel for a placed order's money and
     * the only branch of this module no test had ever entered. Since PLAN
     * §40.10/3 an edit does not ask the promotions again: units added after
     * placement pay full price.
     */
    public function test_editing_a_placed_orders_contents_keeps_what_was_granted(): void
    {
        $promotion = $this->percentOffEverything(10);

        $this->place(qty: 1);
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(-10_00, (int) $this->rows($order)->sum('amount'));

        // The manager adds two more of the same item.
        $items = $order->items;
        $items[0]['qty'] = 3;
        $items[0]['line_total'] = 300_00;
        app(OrderTotals::class)->recalculate($order, $items);

        $order->refresh();

        $this->assertSame(300_00, $order->subtotal);
        $this->assertSame(-10_00, (int) $this->rows($order)->sum('amount'), 'what was added pays full price');
        $this->assertSame(290_00 + $order->shipping_cost, $order->total);
    }

    /**
     * The other direction: the edit drops the cart below what the promotion
     * wanted. The threshold is not asked again — the customer met it when they
     * ordered — but the unit that left takes its share of the discount.
     */
    public function test_an_edit_below_the_threshold_keeps_the_share_of_what_is_left(): void
    {
        $promotion = Promotion::create(['name' => ['en' => 'Spend 200'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => [['kind' => 'subtotal', 'operator' => 'subtotal_gte', 'value' => 200_00]]],
            'discount_type' => 'percent', 'discount_value' => 10,
        ]);

        $this->place(qty: 2);
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(-20_00, (int) $this->rows($order)->sum('amount'));

        $items = $order->items;
        $items[0]['qty'] = 1;
        $items[0]['line_total'] = 100_00;
        app(OrderTotals::class)->recalculate($order, $items);

        $order->refresh();

        $this->assertSame(-10_00, (int) $this->rows($order)->sum('amount'), 'half the units, half the discount');
        $this->assertSame(90_00 + $order->shipping_cost, $order->total);
    }

    /** A redemption is charged when the order is PLACED, not every time it is edited. */
    public function test_editing_an_order_does_not_charge_the_promotion_twice(): void
    {
        $promotion = $this->percentOffEverything(10);

        $this->place(qty: 1);
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(1, $promotion->fresh()->used_count);

        app(OrderTotals::class)->recalculate($order, $order->items);
        app(OrderTotals::class)->recalculate($order, $order->items);

        $this->assertSame(1, $promotion->fresh()->used_count);
    }

    // ------------------------------------------------------------- the arithmetic

    /** Money that does not divide evenly: the lines must still add up to the total. */
    public function test_an_odd_priced_cart_still_adds_up_to_the_cent(): void
    {
        Product::create([
            'sku' => 'ODD-1', 'name' => ['en' => 'Odd'], 'slug' => 'odd',
            'price' => 33_33, 'stock' => 50, 'status' => 'active',
        ]);

        $this->percentOffEverything(15);

        $this->post('http://localhost/cart/add/odd', ['qty' => 3])->assertOk();
        $this->post('http://localhost/checkout', $this->form());

        $order = Order::latest('id')->firstOrFail();
        $extra = (int) $this->rows($order)->sum('amount');

        $this->assertSame(99_99, $order->subtotal);
        $this->assertSame(-15_00, $extra, '15% of 99.99 is 14.9985, and money is whole cents');
        $this->assertSame($order->subtotal + $extra + $order->shipping_cost, $order->total);
    }

    // ------------------------------------------------------------- broken inputs

    /** A scope naming products that have since been deleted discounts nothing. */
    public function test_a_scope_pointing_at_deleted_products_takes_nothing_and_spends_nothing(): void
    {
        $promotion = Promotion::create(['name' => ['en' => 'Ghosts'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true, 'usage_limit' => 5]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'percent', 'discount_value' => 50,
            'discount_scope' => ['type' => 'product', 'ids' => [987654, 987655]],
        ]);

        $this->place(qty: 1);
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(100_00 + $order->shipping_cost, $order->total);
        $this->assertSame(0, $this->rows($order)->count());
        $this->assertSame(0, $promotion->fresh()->used_count, 'a promotion that gave nothing has not been used');
    }

    /** A gift whose shelf is empty. Whatever happens, the warehouse must not lie. */
    public function test_a_gift_that_is_out_of_stock_does_not_drive_the_stock_negative(): void
    {
        $gift = Product::create([
            'sku' => 'GIFT-EMPTY', 'name' => ['en' => 'Empty gift'], 'slug' => 'gift-empty',
            'price' => 20_00, 'stock' => 0, 'status' => 'active',
        ]);

        $promotion = Promotion::create(['name' => ['en' => 'Free gift'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'free_product', 'free_product_id' => $gift->id, 'free_qty' => 1,
        ]);

        $this->place(qty: 1);
        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(0, $gift->fresh()->stock, 'the shelf cannot hold minus one gift');
        $this->assertCount(1, $order->items, 'the gift is withheld, not promised');
        $this->assertSame(0, $this->rows($order)->count(), 'and it is not recorded as given');
        $this->assertSame(0, $promotion->fresh()->used_count);
    }

    /** The same gift, once the shelf has one on it. */
    public function test_a_gift_in_stock_is_still_added_and_taken_off_the_shelf(): void
    {
        $gift = Product::create([
            'sku' => 'GIFT-ONE', 'name' => ['en' => 'One gift'], 'slug' => 'gift-one',
            'price' => 20_00, 'stock' => 1, 'status' => 'active',
        ]);

        $promotion = Promotion::create(['name' => ['en' => 'Free gift'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'free_product', 'free_product_id' => $gift->id, 'free_qty' => 1,
        ]);

        $this->place(qty: 1);
        $order = Order::latest('id')->firstOrFail();

        $this->assertCount(2, $order->items);
        $this->assertSame(0, $gift->fresh()->stock);
        $this->assertSame(1, $this->rows($order)->count());
    }

    /**
     * The gift IS the last one the customer is buying. canCover() sums demand
     * per product, so the shelf is asked about both lines at once.
     */
    public function test_a_gift_of_the_very_product_being_bought_counts_both_lines(): void
    {
        $product = Product::create([
            'sku' => 'GIFT-SAME', 'name' => ['en' => 'Same'], 'slug' => 'gift-same',
            'price' => 50_00, 'stock' => 1, 'status' => 'active',
        ]);

        $promotion = Promotion::create(['name' => ['en' => 'Buy one get one'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'free_product', 'free_product_id' => $product->id, 'free_qty' => 1,
        ]);

        $this->post('http://localhost/cart/add/gift-same', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout', $this->form());

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(0, $product->fresh()->stock, 'the only one on the shelf was sold, not sold and gifted');
        $this->assertCount(1, $order->items);
    }

    // ------------------------------------------------------------------ helpers

    protected function percentOffEverything(int $percent): Promotion
    {
        $promotion = Promotion::create(['name' => ['en' => "Minus {$percent}"], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'percent', 'discount_value' => $percent,
        ]);

        return $promotion;
    }

    protected function rows(Order $order)
    {
        return DB::table('m_promotions_order_promotions')->where('order_id', $order->id)->get();
    }

    protected function place(int $qty): void
    {
        $this->post('http://localhost/cart/add/edge', ['qty' => $qty])->assertOk();
        $this->post('http://localhost/checkout', $this->form());
    }

    protected function form(): array
    {
        return [
            'customer_name' => 'Edge Buyer',
            'email' => 'edge@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ];
    }
}
