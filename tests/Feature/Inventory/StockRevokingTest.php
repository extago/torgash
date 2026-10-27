<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Inventory\Models\Document;
use Torgash\Inventory\Revoking;
use Torgash\Warehouses\Models\Warehouse;
use Torgash\Warehouses\StockAllocator;

/**
 * Revoking a posted document: the stock as if it had never been posted, its
 * journal rows and batches gone, the row kept as `revoked` — and refused when
 * undoing it would rewrite what happened after, or without the permission and
 * the person's own password.
 */
class StockRevokingTest extends TestCase
{
    protected User $admin;

    protected Warehouse $main;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
        $this->admin->forceFill(['password' => Hash::make('secret-pass')])->save();
        $this->actingAs($this->admin);

        Warehouse::query()->update(['is_default' => false]);
        $this->main = Warehouse::create(['name' => ['en' => 'Main'], 'is_default' => true, 'enabled' => true, 'sort' => 0]);
        $this->product = Product::create(['sku' => 'REV-CHAIR', 'name' => ['en' => 'Chair'], 'slug' => 'rev-chair', 'price' => 100_00, 'stock' => 4, 'status' => 'active']);
    }

    public function test_a_receipt_nobody_touched_is_taken_back_without_a_trace_in_the_journal(): void
    {
        $document = $this->posted('receipt', 10);
        $this->assertSame(14, $this->stock());
        $movesBefore = DB::table('m_inventory_moves')->count();
        $this->get($this->url($document))->assertOk()->assertSee(__('Revoke the document'))->assertSee('current_password', false);

        $this->post($this->url($document, '/revoke'), ['reason' => 'typed by mistake', 'current_password' => 'secret-pass'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertTrue($document->isRevoked());
        $this->assertSame('typed by mistake', $document->revoke_reason);
        $this->assertSame($this->admin->id, $document->revoked_by);
        $this->assertSame(4, $this->stock());
        $this->assertSame(0, DB::table('m_inventory_moves')->where('document_id', $document->id)->count());
        $this->assertSame(0, DB::table('m_inventory_batches')->where('document_id', $document->id)->count());
        $this->assertSame($movesBefore - 1, DB::table('m_inventory_moves')->count(), 'the undoing is not written down either');

        // Closed for good: no editing, no posting again, out of the working list.
        $this->put($this->url($document), ['warehouse_id' => $this->main->id, 'document_date' => '2026-10-01'])->assertStatus(409);
        $this->get('http://localhost/admin/inventory/documents')->assertDontSee($document->number);
        $this->get('http://localhost/admin/inventory/documents?type=revoked')->assertSee($document->number);
    }

    public function test_a_write_off_comes_back_into_the_batches_it_took_from(): void
    {
        $receipt = $this->posted('receipt', 5, 300);
        $writeOff = $this->posted('writeoff', 3);
        $batch = DB::table('m_inventory_batches')->where('document_id', $receipt->id)->first();
        $this->assertSame(5, (int) $batch->remaining, 'the opening batch was older and went first');

        app(Revoking::class)->revoke($writeOff, $this->admin, 'broken by nobody');

        $this->assertSame(9, $this->stock());
        $remaining = (int) DB::table('m_inventory_batches')->where('product_id', $this->product->id)->sum('remaining');
        $this->assertSame(9, $remaining, 'every unit is in a batch again');
    }

    public function test_a_receipt_whose_units_have_moved_on_is_refused(): void
    {
        // The opening four go first; selling six reaches the receipt's batch.
        $receipt = $this->posted('receipt', 10);
        $this->posted('writeoff', 6);

        $this->get($this->url($receipt))->assertOk()->assertSee(__('This document cannot be revoked: undoing it would change what happened after it.'))->assertDontSee('current_password', false);
        $obstacles = app(Revoking::class)->obstacles($receipt);
        $this->assertCount(1, $obstacles);
        $this->assertStringContainsString('REV-CHAIR', $obstacles[0]);

        $this->post($this->url($receipt, '/revoke'), ['reason' => 'x', 'current_password' => 'secret-pass'])
            ->assertSessionHasErrorsIn('revoke');
        $this->assertTrue($receipt->fresh()->isPosted());
        $this->assertSame(8, $this->stock());
    }

    public function test_it_needs_the_permission_and_the_password(): void
    {
        $document = $this->posted('receipt', 2);

        $this->post($this->url($document, '/revoke'), ['reason' => 'x', 'current_password' => 'wrong'])
            ->assertSessionHasErrorsIn('revoke', 'current_password');
        $this->assertTrue($document->fresh()->isPosted());

        // A manager who keeps the documents but was not given this.
        Permission::findOrCreate('inventory.manage');
        $role = Role::create(['name' => 'stock-keeper']);
        $role->givePermissionTo(['inventory.manage', 'admin.access']);
        $keeper = User::create(['name' => 'Keeper', 'email' => 'keeper@example.test', 'password' => Hash::make('secret-pass')]);
        $keeper->assignRole($role);
        $this->actingAs($keeper);

        $this->get($this->url($document))->assertOk()->assertDontSee(__('Revoke the document'));
        $this->post($this->url($document, '/revoke'), ['reason' => 'x', 'current_password' => 'secret-pass'])->assertForbidden();
        $this->assertTrue($document->fresh()->isPosted());
    }

    protected function posted(string $type, int $qty, ?int $cost = null): Document
    {
        $this->post('http://localhost/admin/inventory/documents/new/'.$type)->assertRedirect();
        $document = Document::latest('id')->firstOrFail();
        $document->update(['warehouse_id' => $this->main->id]);
        $document->lines()->create([
            'product_id' => $this->product->id, 'sku' => $this->product->sku, 'name' => 'Chair', 'qty' => $qty,
            'unit_cost_original' => $cost, 'currency' => $cost ? shop_currency() : null,
        ]);
        $this->post($this->url($document, '/post'))->assertRedirect()->assertSessionHasNoErrors();

        return $document->fresh();
    }

    protected function stock(): int
    {
        return (int) app(StockAllocator::class)->qtyAt($this->main->id, $this->product->id);
    }

    protected function url(Document $document, string $tail = ''): string
    {
        return 'http://localhost/admin/inventory/documents/'.$document->id.$tail;
    }
}
