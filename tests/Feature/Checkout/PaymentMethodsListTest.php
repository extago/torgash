<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Tests\Fixtures\Payments\FakeGateway;
use Tests\TestCase;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Payments\Models\Binding;

/**
 * The payment methods list: switched in place, several at once, and never
 * deleted from under the orders that were paid by it — the method is what
 * links such an order to its gateway, and a refund goes through that link.
 */
class PaymentMethodsListTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    public function test_the_list_names_the_gateway_beside_the_method(): void
    {
        FakeGateway::reset();
        hook_listen('payments.gateways', fn (array $g) => $g + ['fake' => FakeGateway::class]);

        $wired = PaymentMethod::create(['name' => ['en' => 'Wired card'], 'enabled' => true]);
        Binding::create(['payment_method_id' => $wired->id, 'gateway' => 'fake', 'config' => [], 'test_mode' => true]);
        PaymentMethod::create(['name' => ['en' => 'Plain cash'], 'enabled' => true]);

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/payments/methods')
            ->assertOk()
            ->assertSeeInOrder(['Wired card', 'Fake gateway', 'test mode'])
            ->assertSeeInOrder(['Plain cash', 'Settled by hand']);
    }

    public function test_search_and_status_tabs_narrow_the_list(): void
    {
        PaymentMethod::create(['name' => ['en' => 'Zebra transfer'], 'enabled' => false]);

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/payments/methods?q=zebra&status=disabled')
            ->assertOk()
            ->assertSee('Zebra transfer');

        $this->get('http://localhost/admin/payments/methods?q=zebra&status=enabled')
            ->assertOk()
            ->assertDontSee('Zebra transfer');
    }

    public function test_the_status_switches_in_place(): void
    {
        $method = PaymentMethod::create(['name' => ['en' => 'Card'], 'enabled' => true]);

        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/payments/methods/{$method->id}/status", ['enabled' => 0])
            ->assertOk()
            ->assertSee('payment-method-status-'.$method->id, false);

        $this->assertFalse($method->refresh()->enabled);
    }

    public function test_a_method_orders_were_paid_by_is_not_deleted(): void
    {
        $used = PaymentMethod::create(['name' => ['en' => 'Used'], 'enabled' => true]);
        $unused = PaymentMethod::create(['name' => ['en' => 'Unused'], 'enabled' => true]);
        Order::create([
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'subtotal' => 100, 'total' => 100, 'items' => [],
            'status' => 'open', 'payment_method_id' => $used->id, 'payment_method_name' => 'Used',
        ]);

        $this->actingAs($this->admin)
            ->delete("http://localhost/admin/payments/methods/{$used->id}")
            ->assertSessionHasErrors();
        $this->assertNotNull($used->fresh());

        // Several at once: the unused one goes, the used one stays and is named.
        $this->post('http://localhost/admin/payments/methods/bulk', ['action' => 'delete', 'ids' => [$used->id, $unused->id]])
            ->assertSessionHasErrors();
        $this->assertNotNull($used->fresh());
        $this->assertNull($unused->fresh());
    }

    public function test_bulk_switches_several_off(): void
    {
        $a = PaymentMethod::create(['name' => ['en' => 'A'], 'enabled' => true]);
        $b = PaymentMethod::create(['name' => ['en' => 'B'], 'enabled' => true]);

        $this->actingAs($this->admin)
            ->post('http://localhost/admin/payments/methods/bulk', ['action' => 'disable', 'ids' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertFalse($a->refresh()->enabled);
        $this->assertFalse($b->refresh()->enabled);
    }

    public function test_save_and_close_returns_to_the_list(): void
    {
        $method = PaymentMethod::create(['name' => ['en' => 'Card'], 'enabled' => true]);

        $this->actingAs($this->admin)
            ->put("http://localhost/admin/payments/methods/{$method->id}", ['name' => 'Card', 'after_save' => 'close'])
            ->assertRedirect(route('admin.checkout.payment-methods.index'));

        $this->put("http://localhost/admin/payments/methods/{$method->id}", ['name' => 'Card'])
            ->assertRedirect(route('admin.checkout.payment-methods.edit', $method));
    }
}
