<?php

namespace Tests\Feature\Exim;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Exim\ImportRunner;
use Torgash\Exim\Models\ImportDraft;
use Torgash\Exim\SchemaRegistry;

/**
 * Orders are exported and never imported (PLAN §72). A status and a payment
 * have doors of their own — the order's page, the API's actions, 1C's
 * exchange — and an order's money is worked out from lines a file does not
 * carry: written from one, the morning's export put its total over a line
 * added at noon, and a blank cell made the total 0 and erased the email.
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

    public function test_orders_are_exported_and_never_imported(): void
    {
        $this->assertArrayHasKey('order', app(SchemaRegistry::class)->entities());
        $this->assertContains('total', array_column(app(SchemaRegistry::class)->fieldsFor('order'), 'key'));

        $this->assertSame([], app(ImportRunner::class)->fields('order'));
        $this->assertArrayNotHasKey('order', app(ImportRunner::class)->importable());
        $this->assertArrayHasKey('product', app(ImportRunner::class)->importable());
    }

    public function test_the_console_refuses_a_file_of_orders_and_changes_nothing(): void
    {
        $order = $this->order('TG-IMP01');
        $path = $this->file("order_number;status;email;total\nTG-IMP01;cancelled;;\n");

        $this->artisan('exim:import', ['file' => $path, '--entity' => 'order'])
            ->expectsOutputToContain('Nothing is imported as "order".')
            ->assertFailed();

        $fresh = $order->fresh();
        $this->assertSame('open', $fresh->status);
        $this->assertSame('buyer@example.test', $fresh->email);
        $this->assertSame(100_00, (int) $fresh->total);
    }

    public function test_the_import_screen_neither_offers_orders_nor_takes_them(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->get('http://localhost/admin/exim/import')->assertOk()
            ->assertSee('value="product"', false)
            ->assertDontSee('value="order"', false);

        $this->post('http://localhost/admin/exim/import/upload', [
            'entity' => 'order',
            'file' => UploadedFile::fake()->createWithContent('orders.csv', "order_number;status\nTG-IMP01;cancelled\n"),
        ])->assertSessionHasErrors('entity');

        $this->assertSame(0, ImportDraft::where('entity', 'order')->count());
    }

    /** One uploaded while orders could still be imported is dropped when it is opened or sent, never run. */
    public function test_an_unfinished_import_of_orders_is_dropped(): void
    {
        Storage::fake('private');
        Queue::fake();
        $this->actingAs(User::role('admin')->firstOrFail());
        $order = $this->order('TG-IMP02');

        $opened = $this->draft('a');
        $this->get('http://localhost/admin/exim/import/'.$opened->token.'/map')->assertRedirect(route('admin.exim.import.index'));
        $this->assertNull(ImportDraft::find($opened->id));
        Storage::disk('private')->assertMissing($opened->path);

        $sent = $this->draft('b');
        $this->post('http://localhost/admin/exim/import/commit', ['token' => $sent->token])->assertRedirect(route('admin.exim.import.index'));
        $this->assertNull(ImportDraft::find($sent->id));

        Queue::assertNothingPushed();
        $this->assertSame('open', $order->fresh()->status);
    }

    protected function draft(string $letter): ImportDraft
    {
        $token = str_repeat($letter, 32);
        $path = ImportDraft::DIR.'/'.$token.'.csv';
        Storage::disk('private')->put($path, "order_number;status\nTG-IMP02;cancelled\n");

        return ImportDraft::create([
            'token' => $token, 'entity' => 'order', 'path' => $path, 'file_name' => 'orders.csv',
            'mapping' => ['order_number', 'status'], 'column_count' => 2, 'row_count' => 1,
        ]);
    }

    protected function order(string $number): Order
    {
        return Order::create([
            'order_number' => $number, 'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'currency_code' => 'USD', 'subtotal' => 100_00, 'shipping_cost' => 0, 'total' => 100_00,
            'status' => 'open', 'payment_state' => PaymentState::PENDING, 'stock_deducted' => false, 'items' => [],
        ]);
    }
}
