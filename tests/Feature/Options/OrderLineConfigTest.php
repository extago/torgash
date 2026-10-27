<?php

namespace Tests\Feature\Options;

use App\Models\User;
use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\LineConfig;
use Torgash\Checkout\Orders\OrderLines;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Options\Configurator;
use Torgash\Options\Groups;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\Models\OptionGroup;
use Torgash\Options\PriceRules;

/**
 * Changing the build of a line of a PLACED order (PLAN §33.7).
 *
 * Before this, a configured line could only be deleted: "make it 32 GB
 * instead of 16" meant destroying the server, its parts and its warehouse
 * breakdown and assembling a plain line in their place. The statements worth
 * pinning are the ones that make it a correction rather than a demolition —
 * the line keeps its name, the warehouse is told exactly what changed, the
 * money still adds up, and nothing can be sold from the back office that the
 * shop has stopped selling.
 */
class OrderLineConfigTest extends TestCase
{
    protected Product $server;

    protected Product $small;

    protected Product $large;

    protected OptionGroup $memory;

    protected OptionChoice $smallChoice;

    protected OptionChoice $largeChoice;

    protected function setUp(): void
    {
        parent::setUp();

        app(Groups::class)->flush();
        app(PriceRules::class)->flush();

        $this->server = $this->product('SRV-1', 'Server', 1000_00, 5);
        $this->small = $this->product('MEM-16', '16 GB', 100_00, 10);
        $this->large = $this->product('MEM-32', '32 GB', 250_00, 10);

        $this->memory = $this->group(['type' => 'select', 'name' => ['en' => 'Memory'], 'required' => true]);
        $this->smallChoice = $this->choice(['kind' => 'product', 'product_id' => $this->small->id, 'price_rule' => 'product_price']);
        $this->largeChoice = $this->choice(['kind' => 'product', 'product_id' => $this->large->id, 'price_rule' => 'product_price']);
    }

    /** The whole point: the build changes, the line stays the line it was. */
    public function test_changing_the_build_keeps_the_line_and_swaps_its_part(): void
    {
        $order = $this->placeWith($this->smallChoice);
        $wasKey = $order->items[0]['key'];

        $this->reconfigure($order, $this->largeChoice);
        $order->refresh();

        $tree = OrderLines::tree($order->items);

        $this->assertCount(1, $tree, 'still one thing, with its parts under it');
        $this->assertSame($wasKey, $tree[0]['line']['key'], 'the line keeps its name');
        $this->assertCount(1, $tree[0]['children']);
        $this->assertSame($this->large->id, $tree[0]['children'][0]['product_id']);
        $this->assertSame(1100_00 + 150_00, $order->subtotal);
    }

    /**
     * The warehouse hears about it, and hears the truth: the 16 GB module is
     * back on the shelf and a 32 GB one has left it. This is what deleting and
     * re-adding the line could never get right.
     */
    public function test_the_warehouse_is_credited_and_charged_for_what_changed(): void
    {
        $order = $this->placeWith($this->smallChoice);

        $this->assertSame(9, $this->small->fresh()->stock, 'one 16 GB left the shelf at placement');
        $this->assertSame(10, $this->large->fresh()->stock);

        $this->reconfigure($order, $this->largeChoice);

        $this->assertSame(10, $this->small->fresh()->stock, 'the 16 GB module came back');
        $this->assertSame(9, $this->large->fresh()->stock, 'the 32 GB one went out');
        $this->assertSame(4, $this->server->fresh()->stock, 'the server itself never moved');
    }

    /**
     * A service folds into the parent's own price rather than becoming a line,
     * and the two lists still add up to the same money after an edit.
     */
    public function test_a_folded_service_is_repriced_against_the_orders_own_base(): void
    {
        // 10% of the product — of what THIS order sold it for.
        $warranty = $this->group(['type' => 'select', 'name' => ['en' => 'Warranty']]);
        $cover = OptionChoice::create([
            'group_id' => $warranty->id, 'enabled' => true, 'kind' => 'plain',
            'name' => ['en' => '+1 year'], 'price_rule' => 'percent', 'percent' => 10,
        ]);
        app(Groups::class)->flush();

        $order = $this->placeWith($this->smallChoice);

        // The catalogue moves after the sale; the order's own price does not.
        $this->server->update(['price' => 5000_00]);

        $this->reconfigure($order, $this->smallChoice, [$warranty->id => (string) $cover->id]);
        $order->refresh();

        $parent = OrderLines::tree($order->items)[0]['line'];

        // 1000 + 10% of 1000, not 10% of today's 5000.
        $this->assertSame(1100_00, $parent['unit_price']);
        $this->assertSame('Warranty: +1 year', $parent['options'][0]['label']);
        $this->assertSame(
            array_sum(array_column($order->items, 'line_total')),
            $order->subtotal,
            'the two lists add up to the same money',
        );
    }

    /** A line remembers what was ANSWERED, or nothing could reopen it. */
    public function test_a_placed_line_carries_its_configuration(): void
    {
        $order = $this->placeWith($this->smallChoice);
        $parent = $order->items[0];

        $this->assertArrayHasKey('config', $parent);
        $this->assertSame(1000_00, $parent['base_price']);
        $this->assertSame(
            $this->smallChoice->id,
            (int) $parent['config'][Configurator::KEY][(string) $this->memory->id][0]['c'],
        );

        // An ordinary line is untouched: a shop with no options has the orders
        // it always had, byte for byte. A product with no questions, because
        // the server has a REQUIRED one and is rightly unbuyable without an
        // answer.
        $cart = app(CartManager::class);
        $cart->clear();
        $cart->add($this->small->id, 1);
        $plain = $this->checkout();

        $this->assertArrayNotHasKey('config', $plain->items[0]);
        $this->assertArrayNotHasKey('base_price', $plain->items[0]);
    }

    /** What the shop has stopped selling cannot be sold from the back office either. */
    public function test_a_choice_the_shop_switched_off_is_refused(): void
    {
        $order = $this->placeWith($this->smallChoice);

        $this->largeChoice->update(['enabled' => false]);
        app(Groups::class)->flush();

        $body = $this->actingAs($this->admin())
            ->patch($this->url($order), ['config' => $this->answers($this->largeChoice)])
            ->assertOk()
            ->getContent();

        // The form comes back with the reason on it — never a 4xx, which HTMX
        // does not swap at all.
        $this->assertStringContainsString('no longer offered', $body);

        $order->refresh();
        $this->assertSame($this->small->id, OrderLines::tree($order->items)[0]['children'][0]['product_id']);
        $this->assertSame(10, $this->large->fresh()->stock, 'a refused edit moves no stock');
    }

    /** A part is configured where its parent is, and nowhere else. */
    public function test_a_part_of_a_line_is_not_configurable_on_its_own(): void
    {
        $order = $this->placeWith($this->smallChoice);
        $child = OrderLines::tree($order->items)[0]['children'][0];

        $this->assertFalse(app(LineConfig::class)->isConfigurable($order, $child));
        $this->assertTrue(app(LineConfig::class)->isConfigurable($order, $order->items[0]));
    }

    /** The button, and the form behind it. */
    public function test_the_editor_offers_the_build_and_draws_the_questions(): void
    {
        $order = $this->placeWith($this->smallChoice);
        $key = (string) $order->items[0]['key'];

        $editor = $this->actingAs($this->admin())
            ->get(route('admin.orders.items.edit', $order))->assertOk()->getContent();

        $this->assertStringContainsString('items/'.$key.'/config', $editor);

        $form = $this->actingAs($this->admin())
            ->get(route('admin.orders.items.configure', ['order' => $order, 'line' => $key]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Memory', $form);
        $this->assertStringContainsString('config[options]['.$this->memory->id.']', $form);
        // No live repricing in the order editor: the money appears in the
        // items table when Apply is pressed.
        $this->assertStringNotContainsString('options-total', $form);
    }

    /** Placing an order for the server, with one answer to the memory question. */
    protected function placeWith(OptionChoice $choice): Order
    {
        $cart = app(CartManager::class);
        $cart->clear();
        $cart->add($this->server->id, 1, $this->answers($choice));

        return $this->checkout();
    }

    /** The checkout the shop itself uses, so the lines under test are real ones. */
    protected function checkout(): Order
    {
        $this->post('/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ]);

        return Order::latest('id')->firstOrFail();
    }

    /** @param array<string, mixed> $extra other groups' answers */
    protected function reconfigure(Order $order, OptionChoice $choice, array $extra = []): void
    {
        $this->actingAs($this->admin())
            ->patch($this->url($order), ['config' => $this->answers($choice, $extra)])
            ->assertOk();
    }

    protected function url(Order $order): string
    {
        return route('admin.orders.items.reconfigure', [
            'order' => $order,
            'line' => (string) $order->items[0]['key'],
        ]);
    }

    /** @param array<string, mixed> $extra */
    protected function answers(OptionChoice $choice, array $extra = []): array
    {
        return [Configurator::KEY => [(string) $this->memory->id => (string) $choice->id] + $extra];
    }


    protected function group(array $attributes): OptionGroup
    {
        $group = OptionGroup::create($attributes + [
            'product_id' => $this->server->id,
            'admin_name' => 'g'.uniqid(),
            'enabled' => true,
        ]);

        app(Groups::class)->flush();

        return $group;
    }

    protected function choice(array $attributes): OptionChoice
    {
        $choice = OptionChoice::create($attributes + ['group_id' => $this->memory->id, 'enabled' => true]);

        app(Groups::class)->flush();

        return $choice;
    }

    protected function product(string $sku, string $name, int $price, int $stock): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => $price, 'stock' => $stock, 'status' => 'active',
            'availability_status' => 'in_stock',
        ]);
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
