<?php

namespace Tests\Feature\Exim;

use App\Models\CustomerGroup;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Exim\ExportRunner;
use Torgash\Exim\Models\EximProfile;
use Torgash\Promotions\Models\Promotion;

/**
 * Which price leaves the shop in an export.
 *
 * The `price` column of an export is the price WITH the discounts applied
 * (group discount, price tiers, catalog promotions) and `base_price` is the
 * shop's own untouched number — the same split Bitrix draws between a price
 * type "with discount" and one without, except that ours is safe by
 * construction: `price` has no setter, so a file exported and imported back
 * can never bake a sale into the base price.
 *
 * What it must NOT be is a price belonging to whoever happened to press the
 * button. An export has no visitor — the same reason the sitemap asks
 * `visible()` rather than `listed()` — and an admin is a user with an account,
 * a customer group, and now possibly a catalog promotion aimed at that group.
 */
class ExportPriceTest extends TestCase
{
    public function test_an_export_carries_the_public_price_not_the_exporting_admin_s(): void
    {
        $wholesale = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 50]);

        $admin = User::role('admin')->firstOrFail();
        $admin->update(['customer_group_id' => $wholesale->id]);

        Product::create([
            'sku' => 'EXPORT-1', 'name' => ['en' => 'Exported'], 'slug' => 'exported',
            'price' => 1000_00, 'stock' => 5, 'status' => 'active',
        ]);

        $csv = $this->export($admin);

        $this->assertStringContainsString('100000', $csv, 'the public price, in minor units');
        $this->assertStringNotContainsString('50000', $csv, 'the exporting admin’s own wholesale price must not leak into the feed');
    }

    /** The same, for a catalog promotion aimed at the group the admin is in. */
    public function test_a_group_only_promotion_does_not_reach_the_feed(): void
    {
        $vip = CustomerGroup::create(['name' => ['en' => 'VIP'], 'discount_percent' => 0]);

        $admin = User::role('admin')->firstOrFail();
        $admin->update(['customer_group_id' => $vip->id]);

        Product::create([
            'sku' => 'EXPORT-2', 'name' => ['en' => 'Exported too'], 'slug' => 'exported-too',
            'price' => 800_00, 'stock' => 5, 'status' => 'active',
        ]);

        $promotion = Promotion::create(['name' => ['en' => 'VIP only'], 'zone' => 'catalog', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => [
                ['kind' => 'usergroup', 'operator' => 'group_in', 'ids' => [$vip->id]],
            ]],
            'discount_type' => 'percent', 'discount_value' => 25,
        ]);

        $csv = $this->export($admin);

        $this->assertStringContainsString('80000', $csv);
        $this->assertStringNotContainsString('60000', $csv, 'a price only VIPs may see is not a price to publish');
    }

    /** A sale for everybody, on the other hand, IS the price to publish. */
    public function test_a_public_promotion_does_reach_the_feed(): void
    {
        $admin = User::role('admin')->firstOrFail();

        Product::create([
            'sku' => 'EXPORT-3', 'name' => ['en' => 'On sale'], 'slug' => 'on-sale',
            'price' => 200_00, 'stock' => 5, 'status' => 'active',
        ]);

        $promotion = Promotion::create(['name' => ['en' => 'Everyone'], 'zone' => 'catalog', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'percent', 'discount_value' => 10,
        ]);

        $csv = $this->export($admin);

        $this->assertStringContainsString('18000', $csv, 'a sale with no audience condition is simply the price now');
    }

    /**
     * A marketplace file wants the pair: what it costs now, and what it was.
     * The second half had nowhere to come from until list_price was exportable.
     */
    public function test_the_struck_through_price_travels_beside_the_current_one(): void
    {
        $product = Product::create([
            'sku' => 'EXPORT-RRP', 'name' => ['en' => 'Was cheaper'], 'slug' => 'was-cheaper',
            'price' => 700_00, 'list_price' => 900_00, 'stock' => 5, 'status' => 'active',
        ]);

        $csv = $this->export(User::role('admin')->firstOrFail(), ['sku', 'price', 'list_price']);

        $this->assertStringContainsString('EXPORT-RRP,70000,90000', $csv);
        $this->assertSame(900_00, $product->fresh()->oldPrice(), 'the same number the storefront strikes through');
    }

    /** And it comes back: an RRP is the shop's own number, not a computed one. */
    public function test_a_list_price_can_be_imported_and_cleared(): void
    {
        $product = Product::create([
            'sku' => 'IMPORT-RRP', 'name' => ['en' => 'No RRP yet'], 'slug' => 'no-rrp-yet',
            'price' => 500_00, 'stock' => 5, 'status' => 'active',
        ]);

        $field = collect(hook_filter('exim.schema.product', []))->firstWhere('key', 'list_price');

        ($field['set'])($product, 800_00);
        $product->save();
        $this->assertSame(800_00, $product->fresh()->list_price);

        ($field['set'])($product, '');
        $product->save();
        $this->assertNull($product->fresh()->list_price, 'no RRP is a different answer from an RRP of zero');
    }

    protected function export(User $admin, ?array $fields = null): string
    {
        $profile = EximProfile::create([
            'type' => 'export',
            'entity' => 'product',
            'format' => 'csv',
            'mode' => 'schema',
            'name' => ['en' => 'Price feed'],
            'fields' => $fields ?? ['sku', 'price', 'base_price'],
            'enabled' => true,
        ]);

        $this->actingAs($admin);

        $runner = app(ExportRunner::class);
        $path = $runner->run($profile);

        return \Illuminate\Support\Facades\Storage::disk('local')->get($path);
    }
}
