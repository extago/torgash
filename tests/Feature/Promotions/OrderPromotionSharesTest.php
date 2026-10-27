<?php

namespace Tests\Feature\Promotions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Promotions\Models\Promotion;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * A promotion on a placed order stays on the lines it was granted for (PLAN
 * §40.10/3): the owner's case — a computer bought in a sale, the order packed,
 * and the customer rings to add screws. Editing the order must not ask today's
 * promotions again, and what is added pays full price.
 */
class OrderPromotionSharesTest extends TestCase
{
    protected User $admin;

    protected Order $order;

    protected Promotion $promotion;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::role('admin')->firstOrFail();
        Product::create(['sku' => 'PS-PC', 'name' => ['en' => 'Computer'], 'slug' => 'ps-pc', 'price' => 900_00, 'stock' => 10, 'status' => 'active']);
        Product::create(['sku' => 'PS-KB', 'name' => ['en' => 'Keyboard'], 'slug' => 'ps-kb', 'price' => 100_00, 'stock' => 10, 'status' => 'active']);
        Product::create(['sku' => 'PS-SC', 'name' => ['en' => 'Screws'], 'slug' => 'ps-sc', 'price' => 5_00, 'stock' => 10, 'status' => 'active']);
        $cash = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => -1]);

        $this->promotion = Promotion::create(['name' => ['en' => 'Ten off'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);
        $this->promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => [['kind' => 'subtotal', 'operator' => 'subtotal_gte', 'value' => 0]]],
            'discount_type' => 'percent', 'discount_value' => 10,
        ]);

        $this->post('http://localhost/cart/add/ps-pc', ['qty' => 2]);
        $this->post('http://localhost/cart/add/ps-kb', ['qty' => 1]);
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test', 'address_line' => '1 Street', 'city' => 'Town',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $cash->id,
        ])->assertSessionHasNoErrors();

        $this->order = Order::latest('id')->firstOrFail();
    }

    protected function promotionCut(): int
    {
        return -(int) DB::table('m_promotions_order_promotions')->where('order_id', $this->order->id)->sum('amount');
    }

    protected function line(string $sku): string
    {
        return (string) collect($this->order->fresh()->items)->firstWhere('sku', $sku)['key'];
    }

    public function test_the_cut_is_laid_over_the_lines_it_came_from(): void
    {
        $row = DB::table('m_promotions_order_promotions')->where('order_id', $this->order->id)->first();
        $shares = json_decode($row->shares, true);

        $this->assertSame('goods', $row->target);
        $this->assertSame(190_00, $this->promotionCut());
        $this->assertSame(['a' => 180_00, 'q' => 2], $shares[$this->line('PS-PC')]);
        $this->assertSame(['a' => 10_00, 'q' => 1], $shares[$this->line('PS-KB')]);
    }

    public function test_an_expired_promotion_stays_and_added_goods_pay_full_price(): void
    {
        $total = $this->order->total;
        $this->promotion->update(['enabled' => false]);

        $screws = Product::where('sku', 'PS-SC')->value('id');
        $this->actingAs($this->admin)
            ->post("http://localhost/admin/orders/{$this->order->order_number}/items", ['product_id' => $screws])
            ->assertOk();

        $this->assertSame(190_00, $this->promotionCut(), 'the sale stays on the computer');
        $this->assertSame($total + 5_00, $this->order->fresh()->total, 'the screws pay full price');

        // One more computer is also an addition: full price.
        $this->patch("http://localhost/admin/orders/{$this->order->order_number}/items/{$this->line('PS-PC')}", ['qty' => 3, 'unit_price' => '900.00'])
            ->assertOk();
        $this->assertSame(190_00, $this->promotionCut());
    }

    public function test_what_leaves_the_order_takes_its_share(): void
    {
        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/orders/{$this->order->order_number}/items/{$this->line('PS-PC')}", ['qty' => 1, 'unit_price' => '900.00'])
            ->assertOk();
        $this->assertSame(100_00, $this->promotionCut(), 'one computer of two: half its share');

        $this->delete("http://localhost/admin/orders/{$this->order->order_number}/items/{$this->line('PS-KB')}")->assertOk();
        $this->assertSame(90_00, $this->promotionCut());

        // Putting the unit back gives its part back — the grant is unchanged.
        $this->patch("http://localhost/admin/orders/{$this->order->order_number}/items/{$this->line('PS-PC')}", ['qty' => 2, 'unit_price' => '900.00'])
            ->assertOk();
        $this->assertSame(180_00, $this->promotionCut());
    }

    public function test_rechecking_the_promotions_covers_everything_the_order_holds(): void
    {
        $screws = Product::where('sku', 'PS-SC')->value('id');
        $this->actingAs($this->admin)
            ->post("http://localhost/admin/orders/{$this->order->order_number}/items", ['product_id' => $screws])
            ->assertOk();
        $this->assertSame(190_00, $this->promotionCut());

        $this->post("http://localhost/admin/promotions/orders/{$this->order->order_number}/reapply")->assertOk();
        $this->assertSame(190_50, $this->promotionCut());
    }
}
