<?php

namespace Tests\Feature\Promotions;

use App\Models\Storefront;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\OrderTotals;
use Torgash\Promotions\Models\Promotion;

/**
 * A sale can be global or belong to one storefront — the owner's framing: a
 * storefront is a cover, and what appears on the second one is decided per
 * thing.
 *
 * Expressed with core's display rules rather than a `storefront_id` column, so
 * "both wholesale storefronts" is sayable, the editor already exists, and the
 * fail-closed rule does the work in the places that have no storefront at all
 * (an export, a feed, a console command).
 */
class PromotionStorefrontTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'SF-1', 'name' => ['en' => 'Two covers'], 'slug' => 'two-covers',
            'price' => 1000_00, 'stock' => 10, 'status' => 'active',
        ]);
    }

    public function test_a_promotion_with_no_rules_applies_everywhere(): void
    {
        $this->catalogPromotion(10);

        $this->assertSame(900_00, $this->product->fresh()->effectivePrice());
    }

    public function test_a_promotion_bound_to_a_storefront_is_silent_on_another(): void
    {
        $other = $this->storefront('second', 'Second');

        $this->catalogPromotion(10, [
            ['kind' => 'storefront', 'operator' => 'in', 'value' => [(string) $other->id]],
        ]);

        // Nothing has put us on that storefront, so the rule cannot be met.
        $this->assertSame(1000_00, $this->product->fresh()->effectivePrice());

        app()->instance('torgash.storefront', $other);
        $this->assertSame(900_00, $this->product->fresh()->effectivePrice(), 'and applies on its own');
    }

    /**
     * The trap this design has to survive: an order is re-priced in the ADMIN,
     * where the current storefront is nobody's. The order carries the one it
     * was placed on, and that is the answer the rules must be judged against —
     * otherwise saving an order silently strips its discount.
     */
    public function test_editing_an_order_judges_the_rules_by_the_orders_own_storefront(): void
    {
        $shop = $this->storefront('main-shop', 'Main shop');

        $promotion = Promotion::create([
            'name' => ['en' => 'Main shop only'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true,
            'rules' => [['kind' => 'storefront', 'operator' => 'in', 'value' => [(string) $shop->id]]],
            'rules_match' => 'all',
        ]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'percent', 'discount_value' => 10,
        ]);

        $order = Order::create([
            'order_number' => 'TG-SF1',
            'customer_name' => 'Buyer', 'email' => 'sf@example.test',
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'currency_code' => shop_currency(),
            'storefront_id' => $shop->id,
            'subtotal' => 1000_00, 'shipping_cost' => 0, 'total' => 1000_00,
            'status' => 'open',
            'items' => [[
                'product_id' => $this->product->id, 'sku' => 'SF-1', 'name' => 'Two covers',
                'qty' => 1, 'unit_price' => 1000_00, 'line_total' => 1000_00,
            ]],
        ]);

        // No current storefront — exactly what an admin request looks like.
        $this->assertNull(current_storefront());

        // "Recheck the promotions" — the one path that asks them again.
        app()->getProvider(\Torgash\Promotions\PromotionsServiceProvider::class)->reprice($order);
        app(OrderTotals::class)->recalculate($order->fresh(), $order->items);

        $amount = (int) DB::table('m_promotions_order_promotions')->where('order_id', $order->id)->sum('amount');

        $this->assertSame(-100_00, $amount, 'the order keeps the sale of the storefront it was placed on');
        $this->assertSame(900_00, $order->fresh()->total);
    }

    /** An order from a storefront the sale does not cover gets nothing. */
    public function test_an_order_from_another_storefront_gets_nothing(): void
    {
        $covered = $this->storefront('covered', 'Covered');
        $other = $this->storefront('other', 'Other');

        $promotion = Promotion::create([
            'name' => ['en' => 'Covered only'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true,
            'rules' => [['kind' => 'storefront', 'operator' => 'in', 'value' => [(string) $covered->id]]],
            'rules_match' => 'all',
        ]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'percent', 'discount_value' => 10,
        ]);

        $order = Order::create([
            'order_number' => 'TG-SF2',
            'customer_name' => 'Buyer', 'email' => 'sf2@example.test',
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'currency_code' => shop_currency(),
            'storefront_id' => $other->id,
            'subtotal' => 1000_00, 'shipping_cost' => 0, 'total' => 1000_00,
            'status' => 'open',
            'items' => [[
                'product_id' => $this->product->id, 'sku' => 'SF-1', 'name' => 'Two covers',
                'qty' => 1, 'unit_price' => 1000_00, 'line_total' => 1000_00,
            ]],
        ]);

        app()->getProvider(\Torgash\Promotions\PromotionsServiceProvider::class)->reprice($order);
        app(OrderTotals::class)->recalculate($order->fresh(), $order->items);

        $this->assertSame(0, DB::table('m_promotions_order_promotions')->where('order_id', $order->id)->count());
        $this->assertSame(1000_00, $order->fresh()->total);
    }

    /** A storefront-scoped sale must not reach a file that has no storefront. */
    public function test_a_scoped_promotion_stays_out_of_the_public_price(): void
    {
        $somewhere = $this->storefront('somewhere', 'Somewhere');

        $this->catalogPromotion(50, [
            ['kind' => 'storefront', 'operator' => 'in', 'value' => [(string) $somewhere->id]],
        ]);

        $this->assertSame(1000_00, $this->product->fresh()->publicPrice(), 'an export has no storefront, so a rule about one fails closed');
    }

    protected function storefront(string $code, string $name): Storefront
    {
        return Storefront::create([
            'code' => $code,
            'name' => $name,
            'domains' => [$code.'.test'],
            'theme' => 'default',
            'default_locale' => 'en',
            'locales' => ['en'],
            'default_currency' => shop_currency(),
            'currencies' => [shop_currency()],
            'is_default' => false,
            'enabled' => true,
        ]);
    }

    protected function catalogPromotion(int $percent, ?array $rules = null): Promotion
    {
        $promotion = Promotion::create([
            'name' => ['en' => "Minus {$percent}"], 'zone' => 'catalog', 'combine' => 'replace', 'enabled' => true,
            'rules' => $rules,
            'rules_match' => 'all',
        ]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'percent', 'discount_value' => $percent,
        ]);

        return $promotion;
    }
}
