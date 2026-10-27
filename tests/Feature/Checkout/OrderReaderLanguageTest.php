<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\MethodNames;

/**
 * An order keeps names as its buyer saw them; an admin screen shows them in
 * the READER's language — but only when the snapshot is merely another
 * language of the same words (Order::sameWordsFor()).
 */
class OrderReaderLanguageTest extends TestCase
{
    public function test_a_snapshot_is_translated_only_when_it_is_another_language_of_the_same_words(): void
    {
        $words = ['en' => 'Arrange with manager', 'ru' => 'Обсудить с менеджером'];

        app()->setLocale('ru');

        $this->assertSame('Обсудить с менеджером', Order::sameWordsFor('Arrange with manager', $words));
        // Renamed since, or typed by hand: what it was sold as stands.
        $this->assertSame('Old courier', Order::sameWordsFor('Old courier', $words));
        $this->assertSame('Old courier', Order::sameWordsFor('Old courier', null));
        $this->assertNull(Order::sameWordsFor(null, $words));
    }

    public function test_lines_and_the_payment_method_read_in_the_readers_language(): void
    {
        $product = Product::create([
            'sku' => 'RL-1', 'name' => ['en' => 'Phone Beta', 'ru' => 'Смартфон Бета'], 'slug' => 'rl-1',
            'price' => 10_00, 'stock' => 5, 'status' => 'active',
        ]);
        $method = PaymentMethod::create([
            'name' => ['en' => 'Cash on delivery', 'ru' => 'Наложенный платёж'],
            'enabled' => true, 'position' => 99,
        ]);
        $order = $this->order([
            'payment_method_id' => $method->id,
            'payment_method_name' => 'Cash on delivery',
            'items' => [
                ['key' => (string) $product->id, 'product_id' => $product->id, 'name' => 'Phone Beta', 'qty' => 1, 'unit_price' => 10_00, 'line_total' => 10_00],
                ['key' => 'x', 'product_id' => $product->id, 'name' => 'Phone Beta — 256 GB', 'qty' => 1, 'unit_price' => 10_00, 'line_total' => 10_00],
            ],
        ]);

        app()->setLocale('ru');

        $this->assertSame('Смартфон Бета', $order->itemLabel($order->items[0]));
        // A configured line's composed name is not one of the product's names.
        $this->assertSame('Phone Beta — 256 GB', $order->itemLabel($order->items[1]));
        $this->assertSame('Наложенный платёж', $order->paymentMethodLabel());
    }

    public function test_the_method_filter_is_one_option_per_method_and_finds_every_language(): void
    {
        PaymentMethod::create([
            'name' => ['en' => 'Discuss with manager', 'ru' => 'Обсудить с менеджером'],
            'enabled' => true, 'position' => 99,
        ]);
        $en = $this->order(['order_number' => 'TG-RL-EN', 'payment_method_name' => 'Discuss with manager']);
        $ru = $this->order(['order_number' => 'TG-RL-RU', 'payment_method_name' => 'Обсудить с менеджером']);

        app()->setLocale('ru');
        $names = new MethodNames('payment');

        $this->assertEqualsCanonicalizing(['Обсудить с менеджером', 'Retired method'], $names->options(['Discuss with manager', 'Обсудить с менеджером', 'Retired method']));
        $this->assertEqualsCanonicalizing(['Обсудить с менеджером', 'Discuss with manager'], $names->spellings('Обсудить с менеджером'));

        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin)->withSession(['admin_locale' => 'ru'])
            ->get('http://localhost/admin/orders?payment='.urlencode('Обсудить с менеджером'))
            ->assertOk()
            ->assertSee($en->order_number)
            ->assertSee($ru->order_number);
    }

    public function test_the_dashboards_best_sellers_are_named_by_the_catalogue_in_the_readers_language(): void
    {
        $product = Product::create([
            'sku' => 'RL-TOP', 'name' => ['en' => 'Phone Beta', 'ru' => 'Смартфон Бета'], 'slug' => 'rl-top',
            'price' => 10_00, 'stock' => 5, 'status' => 'active',
        ]);
        $counted = \Torgash\Checkout\Models\OrderStatus::where('inventory_action', 'decrease')->value('code');
        $this->order(['status' => $counted, 'items' => [
            ['key' => (string) $product->id, 'product_id' => $product->id, 'name' => 'Phone Beta', 'qty' => 3, 'unit_price' => 10_00, 'line_total' => 30_00],
        ]]);

        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin)->withSession(['admin_locale' => 'ru'])
            ->get('http://localhost/admin')
            ->assertOk()
            ->assertSee('Смартфон Бета');
    }

    protected function order(array $attributes): Order
    {
        return Order::create($attributes + [
            'order_number' => 'TG-RL-'.uniqid(),
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'address_line' => 'Somewhere', 'city' => 'Kyiv',
            'currency_code' => shop_currency(),
            'subtotal' => 0, 'total' => 0, 'status' => 'new', 'items' => [],
            'stock_deducted' => false,
        ]);
    }
}
