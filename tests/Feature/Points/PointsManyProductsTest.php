<?php

namespace Tests\Feature\Points;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Exim\ExportRunner;
use Torgash\Exim\ImportRunner;
use Torgash\Exim\Models\EximProfile;
use Torgash\Points\ProductTerms;

/**
 * A product's own answers about points for many products at once (PLAN §74,
 * stage 2): three columns of the product exchange that go through a
 * spreadsheet and back, and a section of the products list's "Change…" dialog.
 */
class PointsManyProductsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    public function test_the_spelling_of_a_cell(): void
    {
        $this->assertSame(['default', null], ProductTerms::parseEarn(''));
        $this->assertSame(['multiplier', 2.0], ProductTerms::parseEarn('x2'));
        $this->assertSame(['multiplier', 0.5], ProductTerms::parseEarn('×0,5'));
        $this->assertSame(['percent', 5.0], ProductTerms::parseEarn('5%'));
        $this->assertSame(['fixed', 500.0], ProductTerms::parseEarn('500'));
        $this->assertSame(['none', null], ProductTerms::parseEarn('none'));
        $this->assertSame(['none', null], ProductTerms::parseEarn('0'));
        $this->assertNull(ProductTerms::parseEarn('plenty'));

        $this->assertSame(['default', null], ProductTerms::parsePay(''));
        $this->assertSame(['allowed', null], ProductTerms::parsePay('Y'));
        $this->assertSame(['forbidden', null], ProductTerms::parsePay('нет'));
        $this->assertSame(['limit', 30], ProductTerms::parsePay('30%'));
        $this->assertNull(ProductTerms::parsePay('150%'));
        $this->assertNull(ProductTerms::parsePay('maybe'));
    }

    public function test_the_columns_come_in_and_go_back_out_the_same(): void
    {
        Storage::fake('local');
        $runner = app(ImportRunner::class);
        $result = $runner->commit('product', $runner->rowsOf($this->file(
            "sku;name_en;price;points_earn;points_pay;points_price\n"
            ."PM-1;One;100;x2;no;1500\n"
            ."PM-2;Two;100;5%;30%;\n"
            ."PM-3;Three;100;500;yes;\n"
            ."PM-4;Four;100;;;\n"
            ."PM-5;Five;100;plenty;maybe;\n"
        ), 'product'));

        $row = fn (string $sku) => DB::table('m_points_products')->where('product_id', Product::where('sku', $sku)->value('id'))->first();

        $this->assertSame(['multiplier', '2.00', 'forbidden', 1500], [$row('PM-1')->earn_mode, $row('PM-1')->earn_value, $row('PM-1')->spend_mode, $row('PM-1')->points_price]);
        $this->assertSame(['percent', 'limit', 30], [$row('PM-2')->earn_mode, $row('PM-2')->spend_mode, $row('PM-2')->spend_percent]);
        $this->assertSame(['fixed', 'allowed'], [$row('PM-3')->earn_mode, $row('PM-3')->spend_mode]);
        $this->assertNull($row('PM-4'), 'a product that answers nothing has no row');
        $this->assertNull($row('PM-5'), 'what is not an answer is left as it was');
        $this->assertStringContainsString('plenty', json_encode($result['batch']->notes()), 'and the import says which line');

        $profile = EximProfile::create([
            'type' => 'export', 'entity' => 'product', 'format' => 'csv', 'mode' => 'schema',
            'name' => ['en' => 'Points'], 'fields' => ['sku', 'points_earn', 'points_pay', 'points_price'], 'enabled' => true,
        ]);
        $csv = Storage::disk('local')->get(app(ExportRunner::class)->run($profile));

        foreach (['PM-1,x2,no,1500', 'PM-2,5%,30%,', 'PM-3,500,yes,', 'PM-4,,,'] as $line) {
            $this->assertStringContainsString($line, str_replace('"', '', $csv));
        }

        // An empty cell takes the answer back.
        $runner->commit('product', $runner->rowsOf($this->file("sku;points_earn;points_pay;points_price\nPM-1;;;\n"), 'product'));
        $this->assertNull($row('PM-1'));
    }

    public function test_the_products_list_changes_many_at_once(): void
    {
        $a = Product::create(['sku' => 'PM-A', 'name' => ['en' => 'A'], 'slug' => 'pm-a', 'price' => 100_00, 'status' => 'active']);
        $b = Product::create(['sku' => 'PM-B', 'name' => ['en' => 'B'], 'slug' => 'pm-b', 'price' => 100_00, 'status' => 'active']);
        DB::table('m_points_products')->insert(['product_id' => $b->id, 'points_price' => 900]);

        $this->get('http://localhost/admin/catalog/products')->assertOk()->assertSee('points_bulk_earn', false);

        $this->post('http://localhost/admin/catalog/products/bulk', [
            'action' => 'edit', 'ids' => [$a->id, $b->id],
            'points_bulk_earn' => 'none', 'points_bulk_spend' => 'limit', 'points_bulk_spend_percent' => 20,
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status');

        foreach ([$a, $b] as $product) {
            $row = DB::table('m_points_products')->where('product_id', $product->id)->first();
            $this->assertSame(['none', 'limit', 20], [$row->earn_mode, $row->spend_mode, $row->spend_percent]);
        }
        $this->assertSame(900, DB::table('m_points_products')->where('product_id', $b->id)->value('points_price'), 'what was not named is kept');

        // "As the rules say" takes one answer back and leaves the other.
        $this->post('http://localhost/admin/catalog/products/bulk', ['action' => 'edit', 'ids' => [$a->id], 'points_bulk_earn' => 'default']);
        $this->assertSame(['default', 'limit'], array_values((array) DB::table('m_points_products')->where('product_id', $a->id)->first(['earn_mode', 'spend_mode'])));

        // Nothing filled in is still nothing to change.
        $this->post('http://localhost/admin/catalog/products/bulk', ['action' => 'edit', 'ids' => [$a->id], 'points_bulk_earn' => 'multiplier'])
            ->assertSessionHasErrors('action');
    }

    protected function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pm').'.csv';
        file_put_contents($path, $content);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
