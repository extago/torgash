<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Inventory\Models\Document;
use Torgash\Inventory\Models\Move;
use Torgash\Warehouses\Models\Warehouse;

/**
 * Stock documents (PLAN §42): a draft is filled in and posted once; posting
 * moves exactly what it says at exactly the warehouses it names, every
 * movement written down as the document, and nothing about it changes after.
 */
class StockDocumentsTest extends TestCase
{
    protected User $admin;

    protected Warehouse $main;

    protected Warehouse $shop;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
        $this->actingAs($this->admin);

        Warehouse::query()->update(['is_default' => false]);
        $this->main = Warehouse::create(['name' => ['en' => 'Main'], 'is_default' => true, 'enabled' => true, 'sort' => 0]);
        $this->shop = Warehouse::create(['name' => ['en' => 'Shop'], 'enabled' => true, 'sort' => 1]);
        $this->product = Product::create(['sku' => 'DOC-TABLE', 'name' => ['en' => 'Table'], 'slug' => 'doc-table', 'price' => 500_00, 'stock' => 4, 'status' => 'active']);
    }

    public function test_a_receipt_adds_at_its_warehouse_at_a_price_frozen_in_the_books(): void
    {
        $document = $this->document('receipt');
        $this->post($this->url($document, '/lines'), ['code' => 'doc-table', 'qty' => 3])->assertOk()->assertSee('DOC-TABLE');
        $this->post($this->url($document, '/lines'), ['code' => 'DOC-TABLE', 'qty' => 2])->assertOk();

        $line = $document->lines()->firstOrFail();
        $this->assertSame(5, $line->qty, 'the same product again adds to its line');

        $this->put($this->url($document), [
            'warehouse_id' => $this->main->id, 'document_date' => '2026-09-30', 'counterparty' => 'Timber Ltd',
            'lines' => [$line->id => ['qty' => 5, 'cost' => '120.50', 'currency' => shop_currency()]],
            'after' => 'post',
        ])->assertRedirect();

        $document->refresh();
        $this->assertTrue($document->isPosted());
        $this->assertSame(12050, $line->fresh()->unit_cost);
        $this->assertSame(5 * 12050, $document->total());

        // Its own four went to the default warehouse when it was first split,
        // then five arrived.
        $this->assertSame(9, $this->product->fresh()->stock);
        $this->assertSame(9, (int) DB::table('m_warehouse_product_stock')->where('product_id', $this->product->id)->where('warehouse_id', $this->main->id)->value('qty'));
        $this->assertTrue(Move::where('document_id', $document->id)->where('delta', 5)->where('type', 'document')->exists());
    }

    public function test_a_write_off_of_more_than_the_warehouse_holds_is_refused_and_moves_nothing(): void
    {
        $document = $this->document('writeoff');
        $this->post($this->url($document, '/lines'), ['code' => 'DOC-TABLE', 'qty' => 7]);

        $this->post($this->url($document, '/post'))->assertRedirect()->assertSessionHasErrors('lines');

        $this->assertFalse($document->fresh()->isPosted());
        $this->assertSame(4, $this->product->fresh()->stock);
        $this->assertFalse(Move::where('document_id', $document->id)->exists());
    }

    public function test_a_transfer_takes_from_one_and_adds_to_the_other(): void
    {
        $document = $this->document('transfer');
        $this->post($this->url($document, '/lines'), ['code' => 'DOC-TABLE', 'qty' => 3]);
        $document->update(['to_warehouse_id' => $this->shop->id]);

        $this->post($this->url($document, '/post'))->assertRedirect()->assertSessionHasNoErrors();

        $rows = DB::table('m_warehouse_product_stock')->where('product_id', $this->product->id)->pluck('qty', 'warehouse_id');
        $this->assertSame(1, (int) $rows[$this->main->id]);
        $this->assertSame(3, (int) $rows[$this->shop->id]);
        $this->assertSame(4, $this->product->fresh()->stock, 'a transfer changes where, not how many');
    }

    public function test_a_count_sets_what_was_counted_and_keeps_what_was_expected(): void
    {
        $document = $this->document('count');
        $this->post($this->url($document, '/lines'), ['code' => 'DOC-TABLE', 'qty' => 1]);
        $line = $document->lines()->firstOrFail();
        $line->update(['qty' => 2]);

        $this->post($this->url($document, '/post'))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(4, $line->fresh()->expected);
        $this->assertSame(2, $this->product->fresh()->stock);
        $this->get($this->url($document))->assertOk()->assertSee('-2');
    }

    public function test_a_posted_document_cannot_be_changed_and_prints(): void
    {
        $document = $this->document('receipt');
        $this->post($this->url($document, '/lines'), ['code' => 'DOC-TABLE', 'qty' => 1]);
        $this->post($this->url($document, '/post'))->assertRedirect();

        $this->post($this->url($document, '/lines'), ['code' => 'DOC-TABLE', 'qty' => 1])->assertStatus(409);
        $this->delete($this->url($document))->assertStatus(409);
        $this->post($this->url($document, '/post'))->assertSessionHasErrors('document');

        $this->get($this->url($document, '/print'))->assertOk()->assertSee($document->number)->assertSee('DOC-TABLE');
        $this->get('http://localhost/admin/inventory/documents?type=receipt')->assertOk()->assertSee($document->number);
    }

    public function test_a_write_off_prints_what_its_goods_cost(): void
    {
        $receipt = $this->document('receipt');
        $this->post($this->url($receipt, '/lines'), ['code' => 'DOC-TABLE', 'qty' => 5]);
        $line = $receipt->lines()->firstOrFail();
        $line->update(['unit_cost_original' => 200_00, 'currency' => shop_currency()]);
        $this->post($this->url($receipt, '/post'))->assertSessionHasNoErrors();

        // Four typed on the product before (no price) and five at 200: the
        // oldest go first, so a write-off of six is four unknown and two at 200.
        $writeoff = $this->document('writeoff');
        $this->post($this->url($writeoff, '/lines'), ['code' => 'DOC-TABLE', 'qty' => 6]);

        $this->get($this->url($writeoff, '/print'))->assertOk()
            ->assertSee(__('The cost is an estimate: what the oldest batches would give now. Posting writes the exact figure.'))
            ->assertSee('400.00');

        $this->post($this->url($writeoff, '/post'))->assertSessionHasNoErrors();
        $this->get($this->url($writeoff, '/print'))->assertOk()
            ->assertDontSee(__('The cost is an estimate: what the oldest batches would give now. Posting writes the exact figure.'))
            ->assertSee('400.00')
            ->assertSee(trans_choice(':count unit without a price|:count units without a price', 4, ['count' => 4]));
    }

    public function test_an_unknown_sku_says_so_and_adds_nothing(): void
    {
        $document = $this->document('receipt');

        $this->post($this->url($document, '/lines'), ['code' => 'NOPE-1', 'qty' => 1])
            ->assertOk()->assertSee(__('No product with SKU :sku.', ['sku' => 'NOPE-1']));
        $this->assertSame(0, $document->lines()->count());
    }

    protected function document(string $type): Document
    {
        $this->post('http://localhost/admin/inventory/documents/new/'.$type)->assertRedirect();

        return Document::latest('id')->firstOrFail();
    }

    protected function url(Document $document, string $tail = ''): string
    {
        return 'http://localhost/admin/inventory/documents/'.$document->id.$tail;
    }
}
