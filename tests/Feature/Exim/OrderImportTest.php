<?php

namespace Tests\Feature\Exim;

use Tests\TestCase;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;

/**
 * An import of orders updates the ones that exist and never makes one (PLAN
 * §72): an order is created only by PlaceOrder, and a row that matched
 * nothing used to become an order with no goods, written past it.
 */
class OrderImportTest extends TestCase
{
    protected function file(string $csv): string
    {
        $path = sys_get_temp_dir().'/torgash-orders-'.bin2hex(random_bytes(6)).'.csv';
        file_put_contents($path, $csv);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    public function test_a_row_that_matches_no_order_is_skipped_even_when_new_ones_are_asked_for(): void
    {
        $order = Order::create([
            'order_number' => 'TG-IMP01', 'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'currency_code' => 'USD', 'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
            'status' => 'open', 'payment_state' => PaymentState::PENDING, 'items' => [],
        ]);
        $before = Order::count();
        $path = $this->file("order_number;notes\nTG-IMP01;Rang back\nTG-NOWHERE;Made up\n");

        $this->artisan('exim:import', ['file' => $path, '--entity' => 'order', '--create-new' => true, '--dry-run' => true])
            ->expectsOutputToContain('Would create 0, update 1, skip 1.')
            ->expectsOutputToContain('Line 2: skipped — no order matches it, and an import does not create orders')
            ->assertSuccessful();

        $this->artisan('exim:import', ['file' => $path, '--entity' => 'order', '--create-new' => true])
            ->expectsOutputToContain('Done: 0 created, 1 updated, 1 skipped.')
            ->assertSuccessful();

        $this->assertSame($before, Order::count(), 'no order without goods appears');
        $this->assertFalse(Order::where('order_number', 'TG-NOWHERE')->exists());
        $this->assertSame('Rang back', $order->fresh()->notes, 'an order that exists is still updated');
    }
}
